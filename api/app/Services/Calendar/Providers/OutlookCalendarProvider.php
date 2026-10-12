<?php

namespace App\Services\Calendar\Providers;

use App\Models\Calendar;
use App\Models\CalendarAccount;
use App\Services\Calendar\Data\CalendarData;
use App\Services\Calendar\Data\EventData;
use App\Services\Calendar\Data\OAuthResult;
use App\Services\Calendar\Exceptions\ProviderException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * Outlook.com / Microsoft 365 calendars via Microsoft Graph v1.0.
 */
class OutlookCalendarProvider extends OAuthCalendarProvider
{
    protected const NAME = 'Microsoft';

    private const GRAPH = 'https://graph.microsoft.com/v1.0';

    public const SCOPES = [
        'openid',
        'email',
        'profile',
        'offline_access',
        'User.Read',
        'Calendars.Read',
    ];

    private function authority(): string
    {
        return 'https://login.microsoftonline.com/'.($this->clientConfig()['tenant'] ?: 'common').'/oauth2/v2.0';
    }

    protected function tokenUrl(): string
    {
        return $this->authority().'/token';
    }

    protected function clientConfig(): array
    {
        return config('services.microsoft');
    }

    public function authorizationUrl(string $state): string
    {
        $config = $this->clientConfig();

        return $this->authority().'/authorize?'.http_build_query([
            'client_id' => $config['client_id'],
            'response_type' => 'code',
            'redirect_uri' => $config['redirect'],
            'response_mode' => 'query',
            'scope' => implode(' ', self::SCOPES),
            'state' => $state,
            'prompt' => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeCode(string $code): OAuthResult
    {
        $config = $this->clientConfig();

        $token = $this->requestToken([
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'code' => $code,
            'redirect_uri' => $config['redirect'],
            'grant_type' => 'authorization_code',
            'scope' => implode(' ', self::SCOPES),
        ]);

        $me = Http::withToken($token['access_token'])->acceptJson()
            ->get(self::GRAPH.'/me?$select=id,displayName,mail,userPrincipalName');

        if ($me->failed() || blank($me->json('id'))) {
            throw new ProviderException('Could not read the Microsoft account profile: '.$this->errorMessage($me));
        }

        return new OAuthResult(
            accessToken: $token['access_token'],
            refreshToken: $token['refresh_token'] ?? null,
            expiresAt: $this->expiry($token['expires_in'] ?? null),
            accountId: (string) $me->json('id'),
            email: $me->json('mail') ?? $me->json('userPrincipalName'),
            name: $me->json('displayName'),
            scopes: array_values(array_filter(explode(' ', $token['scope'] ?? ''))),
        );
    }

    protected function refreshPayload(string $refreshToken): array
    {
        return parent::refreshPayload($refreshToken) + ['scope' => implode(' ', self::SCOPES)];
    }

    public function fetchCalendars(CalendarAccount $account): array
    {
        $calendars = [];
        $url = self::GRAPH.'/me/calendars?'.$this->odataQuery([
            '$top' => 100,
            '$select' => 'id,name,hexColor,canEdit,isDefaultCalendar',
        ]);

        while ($url) {
            $data = $this->getJson($account, $url);

            foreach ($data['value'] ?? [] as $item) {
                $calendars[] = new CalendarData(
                    id: $item['id'],
                    name: $item['name'] ?? 'Calendar',
                    color: filled($item['hexColor'] ?? null) ? $item['hexColor'] : null,
                    isPrimary: (bool) ($item['isDefaultCalendar'] ?? false),
                    isReadOnly: ! ($item['canEdit'] ?? false),
                );
            }

            $url = $data['@odata.nextLink'] ?? null;
        }

        return $calendars;
    }

    public function fetchEvents(CalendarAccount $account, Calendar $calendar, CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        // calendarView returns expanded occurrences of recurring events within the window.
        $url = self::GRAPH.'/me/calendars/'.rawurlencode($calendar->provider_calendar_id).'/calendarView?'.$this->odataQuery([
            'startDateTime' => $from->utc()->format('Y-m-d\TH:i:s\Z'),
            'endDateTime' => $to->utc()->format('Y-m-d\TH:i:s\Z'),
            '$top' => 250,
            '$select' => 'id,subject,bodyPreview,location,start,end,isAllDay,isCancelled,showAs,sensitivity,organizer,webLink,seriesMasterId,originalStartTimeZone',
        ]);
        $headers = ['Prefer' => 'outlook.timezone="UTC"'];

        while ($url) {
            $data = $this->getJson($account, $url, headers: $headers);

            foreach ($data['value'] ?? [] as $item) {
                if (($item['isCancelled'] ?? false) || ! isset($item['start']['dateTime'])) {
                    continue;
                }

                yield $this->mapEvent($item);
            }

            $url = $data['@odata.nextLink'] ?? null;
        }
    }

    private function mapEvent(array $item): EventData
    {
        $isAllDay = (bool) ($item['isAllDay'] ?? false);
        $showAs = $item['showAs'] ?? 'busy';

        if ($isAllDay) {
            // All-day events are floating dates; keep the calendar date at midnight UTC.
            $start = CarbonImmutable::parse(substr($item['start']['dateTime'], 0, 10), 'UTC');
            $end = CarbonImmutable::parse(substr($item['end']['dateTime'] ?? $item['start']['dateTime'], 0, 10), 'UTC');
        } else {
            $start = $this->parseGraphDate($item['start']);
            $end = $this->parseGraphDate($item['end'] ?? $item['start']);
        }

        return new EventData(
            id: $item['id'],
            title: $item['subject'] ?? null,
            startsAt: $start,
            endsAt: $end,
            isAllDay: $isAllDay,
            description: filled($item['bodyPreview'] ?? null) ? $item['bodyPreview'] : null,
            location: filled($item['location']['displayName'] ?? null) ? $item['location']['displayName'] : null,
            timezone: $item['originalStartTimeZone'] ?? null,
            status: $showAs === 'tentative' ? 'tentative' : 'confirmed',
            isBusy: ! in_array($showAs, ['free', 'workingElsewhere'], true),
            visibility: in_array($item['sensitivity'] ?? null, ['private', 'confidential'], true) ? 'private' : null,
            organizerEmail: $item['organizer']['emailAddress']['address'] ?? null,
            url: $item['webLink'] ?? null,
            recurringEventId: $item['seriesMasterId'] ?? null,
        );
    }

    /**
     * Graph returns e.g. {"dateTime": "2026-10-06T14:00:00.0000000", "timeZone": "UTC"}.
     */
    private function parseGraphDate(array $value): CarbonImmutable
    {
        $dateTime = preg_replace('/\.\d+$/', '', $value['dateTime']);

        return CarbonImmutable::parse($dateTime, $value['timeZone'] ?? 'UTC')->utc();
    }

    /** OData system query options must keep their literal "$" prefix. */
    private function odataQuery(array $params): string
    {
        return str_replace('%24', '$', http_build_query($params, '', '&', PHP_QUERY_RFC3986));
    }
}
