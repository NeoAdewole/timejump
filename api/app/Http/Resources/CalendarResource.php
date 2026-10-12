<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Calendar */
class CalendarResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'account_id' => $this->calendar_account_id,
            'provider' => $this->whenLoaded('account', fn () => $this->account->provider->value),
            'name' => $this->displayName(),
            'original_name' => $this->name,
            'description' => $this->description,
            'color' => $this->displayColor(),
            'timezone' => $this->timezone,
            'is_primary' => $this->is_primary,
            'is_read_only' => $this->is_read_only,
            'is_enabled' => $this->is_enabled,
            'last_synced_at' => $this->last_synced_at?->toIso8601ZuluString(),
        ];
    }
}
