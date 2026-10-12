<?php

namespace App\Services\Calendar;

use App\Enums\AccountStatus;
use App\Models\Calendar;
use App\Models\CalendarAccount;
use App\Models\Event;
use App\Services\Calendar\Contracts\CalendarProvider;
use App\Services\Calendar\Exceptions\ReauthorizationRequired;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * Imports calendars and events from a provider into the local database.
 *
 * Each sync run stamps the events it sees with a batch id; anything in the calendar
 * that was not seen (deleted remotely, or now outside the sync window) is removed.
 */
class CalendarSyncService
{
    private const UPSERT_CHUNK = 500;

    public function __construct(private readonly CalendarProviderManager $providers) {}

    /**
     * @return array{calendars: int, events: int}
     */
    public function syncAccount(CalendarAccount $account): array
    {
        $provider = $this->providers->provider($account->provider);

        try {
            $calendars = $this->syncCalendarList($account, $provider->fetchCalendars($account));
            [$from, $to] = $this->window();

            $events = 0;
            foreach ($calendars->where('is_enabled', true) as $calendar) {
                $events += $this->syncEvents($provider, $account, $calendar, $from, $to);
            }

            $account->forceFill([
                'status' => AccountStatus::Active,
                'last_error' => null,
                'last_synced_at' => now(),
            ])->save();

            return ['calendars' => $calendars->count(), 'events' => $events];
        } catch (ReauthorizationRequired $e) {
            $this->markFailed($account, AccountStatus::ReauthRequired, $e);

            throw $e;
        } catch (Throwable $e) {
            $this->markFailed($account, AccountStatus::Error, $e);

            throw $e;
        }
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function window(): array
    {
        return [
            CarbonImmutable::now()->utc()->subDays(config('timejump.sync.past_days'))->startOfDay(),
            CarbonImmutable::now()->utc()->addDays(config('timejump.sync.future_days'))->endOfDay(),
        ];
    }

    /**
     * @param  list<\App\Services\Calendar\Data\CalendarData>  $remote
     * @return Collection<int, Calendar>
     */
    private function syncCalendarList(CalendarAccount $account, array $remote): Collection
    {
        $seen = [];

        foreach ($remote as $data) {
            $calendar = $account->calendars()->firstOrNew(['provider_calendar_id' => $data->id]);
            $calendar->fill([
                'name' => Str::limit($data->name, 255, ''),
                'description' => $data->description,
                'color' => $data->color,
                'timezone' => $data->timezone,
                'is_primary' => $data->isPrimary,
                'is_read_only' => $data->isReadOnly,
            ]);
            $calendar->user_id = $account->user_id;
            $calendar->save();

            $seen[] = $calendar->id;
        }

        // Calendars removed (or unshared) at the provider disappear here too.
        $account->calendars()->whereNotIn('id', $seen)->delete();

        return $account->calendars()->get();
    }

    private function syncEvents(CalendarProvider $provider, CalendarAccount $account, Calendar $calendar, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $batch = (string) Str::uuid();
        $now = now()->utc()->format('Y-m-d H:i:s');
        $rows = [];
        $count = 0;

        foreach ($provider->fetchEvents($account, $calendar, $from, $to) as $event) {
            $rows[$event->id] = $event->toRow($calendar->id, $batch, $now);

            if (count($rows) >= self::UPSERT_CHUNK) {
                $count += $this->upsert($rows);
                $rows = [];
            }
        }

        $count += $this->upsert($rows);

        Event::query()
            ->where('calendar_id', $calendar->id)
            ->where(fn ($q) => $q->whereNull('sync_batch')->orWhere('sync_batch', '!=', $batch))
            ->delete();

        $calendar->forceFill(['last_synced_at' => now()])->save();

        return $count;
    }

    private function upsert(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        Event::upsert(
            array_values($rows),
            ['calendar_id', 'provider_event_id'],
            [
                'title', 'description', 'location', 'starts_at', 'ends_at', 'is_all_day', 'timezone',
                'status', 'is_busy', 'visibility', 'organizer_email', 'url', 'recurring_event_id',
                'sync_batch', 'updated_at',
            ]
        );

        return count($rows);
    }

    private function markFailed(CalendarAccount $account, AccountStatus $status, Throwable $e): void
    {
        $account->forceFill([
            'status' => $status,
            'last_error' => Str::limit($e->getMessage(), 1000),
        ])->save();
    }
}
