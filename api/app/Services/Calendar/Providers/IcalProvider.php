<?php

namespace App\Services\Calendar\Providers;

use App\Models\Calendar;
use App\Models\CalendarAccount;
use App\Services\Calendar\Contracts\CalendarProvider;
use App\Services\Calendar\Data\CalendarData;
use App\Services\Calendar\Data\EventData;
use App\Services\Calendar\Exceptions\InvalidFeedException;
use App\Services\Calendar\UrlGuard;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Reader;
use Throwable;

/**
 * iCalendar (RFC 5545) feeds, from a subscription URL or an uploaded .ics file.
 * Each feed is exposed as a single read-only calendar.
 */
class IcalProvider implements CalendarProvider
{
    public const CALENDAR_ID = 'default';

    /** @var array<int|string, VCalendar> Parsed feeds, memoised for the lifetime of one sync. */
    private array $parsed = [];

    public function __construct(private readonly UrlGuard $guard) {}

    public function fetchCalendars(CalendarAccount $account): array
    {
        $vcal = $this->load($account);

        return [new CalendarData(
            id: self::CALENDAR_ID,
            name: $this->property($vcal, 'X-WR-CALNAME') ?? $account->display_name ?? 'iCal calendar',
            description: $this->property($vcal, 'X-WR-CALDESC'),
            color: $this->property($vcal, 'X-APPLE-CALENDAR-COLOR'),
            timezone: $this->property($vcal, 'X-WR-TIMEZONE'),
            isPrimary: true,
            isReadOnly: true,
        )];
    }

    public function fetchEvents(CalendarAccount $account, Calendar $calendar, CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        try {
            // Expands RRULEs into individual occurrences within the window, in UTC.
            $expanded = $this->load($account)->expand($from->utc(), $to->utc());
        } catch (Throwable $e) {
            throw new InvalidFeedException('Could not expand the iCal events: '.$e->getMessage(), previous: $e);
        }

        foreach ($expanded->select('VEVENT') as $vevent) {
            if ($event = $this->mapEvent($vevent)) {
                yield $event;
            }
        }
    }

    /**
     * Download and validate a feed. Redirects are followed manually so every hop is checked.
     */
    public function download(string $url): string
    {
        $url = $this->guard->normalize($url);
        $maxRedirects = config('timejump.ical.max_redirects');

        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            try {
                $response = Http::withoutRedirecting()
                    ->timeout(config('timejump.ical.timeout'))
                    ->withHeaders(['Accept' => 'text/calendar, text/plain;q=0.9, */*;q=0.5'])
                    ->get($url);
            } catch (ConnectionException $e) {
                throw new InvalidFeedException('Could not download the iCal feed: '.$e->getMessage(), previous: $e);
            }

            if ($response->redirect() && $response->header('Location')) {
                $url = $this->guard->normalize($this->absoluteUrl($url, $response->header('Location')));

                continue;
            }

            if ($response->failed()) {
                throw new InvalidFeedException("The iCal feed returned HTTP {$response->status()}.");
            }

            $body = $response->body();

            if (strlen($body) > config('timejump.ical.max_bytes')) {
                throw new InvalidFeedException('The iCal feed is larger than the allowed maximum.');
            }

            return $body;
        }

        throw new InvalidFeedException('The iCal feed redirected too many times.');
    }

    public function parse(string $content): VCalendar
    {
        if (! str_contains(substr($content, 0, 2048), 'BEGIN:VCALENDAR')) {
            throw new InvalidFeedException('The data is not an iCalendar (.ics) document.');
        }

        try {
            $vcal = Reader::read($content, Reader::OPTION_FORGIVING);
        } catch (Throwable $e) {
            throw new InvalidFeedException('Could not parse the iCal data: '.$e->getMessage(), previous: $e);
        }

        if (! $vcal instanceof VCalendar) {
            throw new InvalidFeedException('The data is not an iCalendar (.ics) document.');
        }

        return $vcal;
    }

    private function load(CalendarAccount $account): VCalendar
    {
        return $this->parsed[$account->getKey() ?? spl_object_id($account)] ??= $this->parse($this->content($account));
    }

    private function content(CalendarAccount $account): string
    {
        if (filled($account->source_url)) {
            return $this->download($account->source_url);
        }

        $path = $account->icalStoragePath();

        if (! Storage::disk('local')->exists($path)) {
            throw new InvalidFeedException('The uploaded iCal file is missing; please import it again.');
        }

        return Storage::disk('local')->get($path);
    }

    private function mapEvent(VEvent $vevent): ?EventData
    {
        if (! isset($vevent->DTSTART)) {
            return null;
        }

        $status = strtolower((string) ($vevent->STATUS ?? 'confirmed'));
        if ($status === 'cancelled') {
            return null;
        }

        $utc = new DateTimeZone('UTC');
        $isAllDay = ! $vevent->DTSTART->hasTime();
        $start = CarbonImmutable::instance($vevent->DTSTART->getDateTime($utc))->utc();

        if (isset($vevent->DTEND)) {
            $end = CarbonImmutable::instance($vevent->DTEND->getDateTime($utc))->utc();
        } elseif (isset($vevent->DURATION)) {
            $end = $start->add($vevent->DURATION->getDateInterval());
        } else {
            $end = $isAllDay ? $start->addDay() : $start;
        }

        if ($isAllDay) {
            $start = $start->startOfDay();
            $end = $end->startOfDay();
        }

        // Expanded occurrences share a UID; RECURRENCE-ID makes each one unique.
        $uid = (string) ($vevent->UID ?? '') ?: sha1($vevent->serialize());
        $id = isset($vevent->{'RECURRENCE-ID'})
            ? $uid.'/'.$vevent->{'RECURRENCE-ID'}->getDateTime($utc)->format('Ymd\THis\Z')
            : $uid;

        $class = strtoupper((string) ($vevent->CLASS ?? 'PUBLIC'));

        return new EventData(
            id: $id,
            title: $this->text($vevent, 'SUMMARY'),
            startsAt: $start,
            endsAt: $end,
            isAllDay: $isAllDay,
            description: $this->text($vevent, 'DESCRIPTION'),
            location: $this->text($vevent, 'LOCATION'),
            timezone: $vevent->DTSTART['TZID'] ? (string) $vevent->DTSTART['TZID'] : null,
            status: $status === 'tentative' ? 'tentative' : 'confirmed',
            isBusy: strtoupper((string) ($vevent->TRANSP ?? 'OPAQUE')) !== 'TRANSPARENT',
            visibility: in_array($class, ['PRIVATE', 'CONFIDENTIAL'], true) ? 'private' : null,
            organizerEmail: isset($vevent->ORGANIZER) ? preg_replace('/^mailto:/i', '', (string) $vevent->ORGANIZER) : null,
            url: $this->text($vevent, 'URL'),
            recurringEventId: isset($vevent->{'RECURRENCE-ID'}) ? $uid : null,
        );
    }

    private function text(VEvent $vevent, string $name): ?string
    {
        $value = isset($vevent->{$name}) ? trim((string) $vevent->{$name}) : '';

        return $value === '' ? null : $value;
    }

    private function property(VCalendar $vcal, string $name): ?string
    {
        $value = isset($vcal->{$name}) ? trim((string) $vcal->{$name}) : '';

        return $value === '' ? null : $value;
    }

    private function absoluteUrl(string $base, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $location)) {
            return $location;
        }

        $parts = parse_url($base);
        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $dir = rtrim(dirname($parts['path'] ?? '/'), '/');

        return $origin.$dir.'/'.$location;
    }
}
