<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CalendarResource;
use App\Jobs\SyncCalendarAccount;
use App\Models\Calendar;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * All of the user's calendars across every connected source.
 */
class CalendarController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['enabled' => ['nullable', 'boolean']]);

        $calendars = $request->user()->calendars()
            ->with('account')
            ->when($request->has('enabled'), fn ($q) => $q->where('is_enabled', $request->boolean('enabled')))
            ->orderByDesc('is_primary')
            ->orderBy('name')
            ->get();

        return CalendarResource::collection($calendars);
    }

    public function show(Calendar $calendar): CalendarResource
    {
        $this->authorize('view', $calendar);

        return new CalendarResource($calendar->load('account'));
    }

    /**
     * Enable/disable a calendar in the aggregated view, or override its name/colour.
     */
    public function update(Request $request, Calendar $calendar): CalendarResource
    {
        $this->authorize('update', $calendar);

        $data = $request->validate([
            'is_enabled' => ['sometimes', 'boolean'],
            'custom_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'custom_color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        $wasEnabled = $calendar->is_enabled;
        $calendar->fill($data)->save();
        $calendar->load('account');

        // Disabled calendars are skipped during sync, so refresh one when it is re-enabled.
        if (! $wasEnabled && $calendar->is_enabled) {
            SyncCalendarAccount::dispatch($calendar->account);
        }

        return new CalendarResource($calendar);
    }
}
