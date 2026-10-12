<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\CalendarShare */
class CalendarShareResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'owner' => new PublicUserResource($this->whenLoaded('owner')),
            'grantee' => new PublicUserResource($this->whenLoaded('grantee')),
            'permission' => $this->permission->value,
            'all_calendars' => $this->all_calendars,
            'calendars' => $this->when(
                ! $this->all_calendars && $this->relationLoaded('calendars'),
                fn () => $this->calendars->map(fn ($c) => ['id' => $c->id, 'name' => $c->displayName()])->values()
            ),
            'expires_at' => $this->expires_at?->toIso8601ZuluString(),
            'is_active' => $this->isActive(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
