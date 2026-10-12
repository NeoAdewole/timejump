<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\CalendarAccount */
class CalendarAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider->value,
            'provider_label' => $this->provider->label(),
            'email' => $this->email,
            'display_name' => $this->display_name,
            'status' => $this->status->value,
            'last_error' => $this->last_error,
            'last_synced_at' => $this->last_synced_at?->toIso8601ZuluString(),
            'calendars_count' => $this->whenCounted('calendars'),
            'calendars' => CalendarResource::collection($this->whenLoaded('calendars')),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
