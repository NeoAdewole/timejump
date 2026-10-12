<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * An event as seen by a user the owner has shared their calendar with ("details" access).
 * Events the owner marked private are reduced to a "Busy" placeholder, and links back
 * into the owner's provider account are never exposed.
 *
 * @mixin \App\Models\Event
 */
class SharedEventResource extends EventResource
{
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);
        $data['url'] = null;

        if ($this->isPrivate()) {
            $data = array_merge($data, [
                'title' => 'Busy',
                'description' => null,
                'location' => null,
                'organizer_email' => null,
                'recurring_event_id' => null,
            ]);
        }

        return $data;
    }
}
