<?php

namespace App\Services\Calendar;

use App\Models\CalendarShare;
use App\Models\Event;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Merges events from all of a user's enabled calendars (Google, Outlook, iCal) into a
 * single timeline, optionally restricted to what a share allows.
 */
class CalendarAggregator
{
    /**
     * @param  array{calendar_ids?: list<int>, provider?: string, search?: string, dedupe?: bool}  $filters
     * @return Collection<int, Event>
     */
    public function events(User $owner, CarbonImmutable $start, CarbonImmutable $end, array $filters = [], ?CalendarShare $share = null): Collection
    {
        $query = Event::query()
            ->select('events.*')
            ->join('calendars', 'calendars.id', '=', 'events.calendar_id')
            ->where('calendars.user_id', $owner->id)
            ->where('calendars.is_enabled', true)
            ->overlapping($start, $end)
            ->with('calendar.account')
            ->orderBy('events.starts_at')
            ->orderBy('events.ends_at')
            ->orderBy('events.id');

        if (! empty($filters['calendar_ids'])) {
            $query->whereIn('events.calendar_id', $filters['calendar_ids']);
        }

        if (! empty($filters['provider'])) {
            $query->whereHas('calendar.account', fn (Builder $q) => $q->where('provider', $filters['provider']));
        }

        if (! empty($filters['search'])) {
            $term = '%'.addcslashes($filters['search'], '%_\\').'%';
            $query->where(fn (Builder $q) => $q->where('events.title', 'like', $term)->orWhere('events.location', 'like', $term));
        }

        if ($share !== null && ! $share->all_calendars) {
            $query->whereIn('events.calendar_id', $share->calendars()->pluck('calendars.id'));
        }

        $events = $query->get();

        return ! empty($filters['dedupe']) ? $this->dedupe($events) : $events;
    }

    /**
     * Collapse the same meeting appearing in several calendars (e.g. invited on both
     * a Google and an Outlook account) into one entry.
     *
     * @param  Collection<int, Event>  $events
     * @return Collection<int, Event>
     */
    public function dedupe(Collection $events): Collection
    {
        return $events->unique(function (Event $event) {
            $title = mb_strtolower(trim((string) $event->title));

            return $title === ''
                ? 'id:'.$event->id
                : $title.'|'.$event->starts_at->timestamp.'|'.$event->ends_at->timestamp;
        })->values();
    }

    /**
     * Merge busy events into non-overlapping time blocks, clipped to [start, end).
     * Exposes availability without revealing any event details.
     *
     * @param  Collection<int, Event>  $events
     * @return list<array{start: string, end: string}>
     */
    public function busyBlocks(Collection $events, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $intervals = $events
            ->filter(fn (Event $e) => $e->is_busy)
            ->map(fn (Event $e) => [$e->starts_at->max($start), $e->ends_at->min($end)])
            ->filter(fn (array $i) => $i[0]->lt($i[1]))
            ->sortBy(fn (array $i) => $i[0]->getTimestamp())
            ->values();

        $blocks = [];
        foreach ($intervals as [$blockStart, $blockEnd]) {
            $last = array_key_last($blocks);

            if ($last !== null && $blockStart->lte($blocks[$last][1])) {
                $blocks[$last][1] = $blockEnd->max($blocks[$last][1]);
            } else {
                $blocks[] = [$blockStart, $blockEnd];
            }
        }

        return array_map(fn (array $b) => [
            'start' => $b[0]->utc()->toIso8601ZuluString(),
            'end' => $b[1]->utc()->toIso8601ZuluString(),
        ], $blocks);
    }
}
