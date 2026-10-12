<?php

namespace App\Services\Calendar\Data;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final readonly class EventData
{
    public CarbonImmutable $startsAt;

    public CarbonImmutable $endsAt;

    public function __construct(
        public string $id,
        public ?string $title,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        public bool $isAllDay = false,
        public ?string $description = null,
        public ?string $location = null,
        public ?string $timezone = null,
        public string $status = 'confirmed',
        public bool $isBusy = true,
        public ?string $visibility = null,
        public ?string $organizerEmail = null,
        public ?string $url = null,
        public ?string $recurringEventId = null,
    ) {
        $this->startsAt = $startsAt->utc();

        // Guard against zero/negative-length events from sloppy sources.
        $endsAt = $endsAt->utc();
        if ($endsAt->lte($this->startsAt)) {
            $endsAt = $isAllDay ? $this->startsAt->addDay() : $this->startsAt;
        }
        $this->endsAt = $endsAt;
    }

    /**
     * Row for a bulk upsert into the events table.
     */
    public function toRow(int $calendarId, string $syncBatch, string $now): array
    {
        return [
            'calendar_id' => $calendarId,
            'provider_event_id' => Str::limit($this->id, 255, ''),
            'title' => $this->title !== null ? Str::limit($this->title, 255, '') : null,
            'description' => $this->description,
            'location' => $this->location !== null ? Str::limit($this->location, 255, '') : null,
            'starts_at' => $this->startsAt->format('Y-m-d H:i:s'),
            'ends_at' => $this->endsAt->format('Y-m-d H:i:s'),
            'is_all_day' => $this->isAllDay,
            'timezone' => $this->timezone,
            'status' => $this->status,
            'is_busy' => $this->isBusy,
            'visibility' => $this->visibility,
            'organizer_email' => $this->organizerEmail,
            'url' => $this->url,
            'recurring_event_id' => $this->recurringEventId,
            'sync_batch' => $syncBatch,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
