<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Event */
class EventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'calendar' => $this->whenLoaded('calendar', fn () => [
                'id' => $this->calendar->id,
                'name' => $this->calendar->displayName(),
                'color' => $this->calendar->displayColor(),
                'provider' => $this->calendar->relationLoaded('account') ? $this->calendar->account->provider->value : null,
            ]),
            'title' => $this->title,
            'description' => $this->description,
            'location' => $this->location,
            'start' => $this->starts_at->toIso8601ZuluString(),
            'end' => $this->ends_at->toIso8601ZuluString(),
            'is_all_day' => $this->is_all_day,
            'timezone' => $this->timezone,
            'status' => $this->status,
            'is_busy' => $this->is_busy,
            'visibility' => $this->visibility ?? 'default',
            'organizer_email' => $this->organizer_email,
            'url' => $this->url,
            'recurring_event_id' => $this->recurring_event_id,
        ];
    }
}
