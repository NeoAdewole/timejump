<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DateRangeRequest;
use App\Http\Resources\CalendarResource;
use App\Http\Resources\CalendarShareResource;
use App\Http\Resources\SharedEventResource;
use App\Models\CalendarShare;
use App\Models\User;
use App\Services\Calendar\CalendarAggregator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Calendars other users have shared with the authenticated user (grantee side).
 */
class SharedCalendarController extends Controller
{
    public function __construct(private readonly CalendarAggregator $aggregator) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $shares = $request->user()->sharesReceived()
            ->active()
            ->with(['owner', 'calendars'])
            ->latest()
            ->get();

        return CalendarShareResource::collection($shares);
    }

    /**
     * Remove a share from your own list (the grantee opting out).
     */
    public function leave(CalendarShare $share): Response
    {
        $this->authorize('leave', $share);

        $share->delete();

        return response()->noContent();
    }

    public function calendars(Request $request, User $user): AnonymousResourceCollection|JsonResponse
    {
        $share = $this->shareFor($request, $user);

        if (! $share->allowsDetails()) {
            return response()->json(['message' => 'Free/busy access does not include calendar details.'], 403);
        }

        $calendars = $user->calendars()
            ->with('account')
            ->where('is_enabled', true)
            ->when(! $share->all_calendars, fn ($q) => $q->whereIn('id', $share->calendars()->pluck('calendars.id')))
            ->orderByDesc('is_primary')
            ->orderBy('name')
            ->get();

        return CalendarResource::collection($calendars)->additional(['meta' => ['permission' => $share->permission->value]]);
    }

    /**
     * The owner's aggregated events. With free/busy access, only merged busy blocks are returned.
     */
    public function events(DateRangeRequest $request, User $user): JsonResponse
    {
        $share = $this->shareFor($request, $user);
        [$start, $end] = $request->range();

        $events = $this->aggregator->events($user, $start, $end, $request->filters(), $share);
        $meta = $request->meta() + ['permission' => $share->permission->value];

        if (! $share->allowsDetails()) {
            return response()->json([
                'data' => $this->aggregator->busyBlocks($events, $start, $end),
                'meta' => $meta + ['format' => 'busy_blocks'],
            ]);
        }

        return SharedEventResource::collection($events)
            ->additional(['meta' => $meta + ['format' => 'events', 'count' => $events->count()]])
            ->response();
    }

    public function busy(DateRangeRequest $request, User $user): JsonResponse
    {
        $share = $this->shareFor($request, $user);
        [$start, $end] = $request->range();

        $events = $this->aggregator->events($user, $start, $end, [], $share);

        return response()->json([
            'data' => $this->aggregator->busyBlocks($events, $start, $end),
            'meta' => $request->meta() + ['permission' => $share->permission->value],
        ]);
    }

    private function shareFor(Request $request, User $owner): CalendarShare
    {
        $share = CalendarShare::query()
            ->active()
            ->where('owner_id', $owner->id)
            ->where('grantee_id', $request->user()->id)
            ->first();

        abort_if($share === null, 403, "You do not have access to this user's calendar.");

        return $share;
    }
}
