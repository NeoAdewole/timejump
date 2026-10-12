<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DateRangeRequest;
use App\Http\Resources\EventResource;
use App\Models\Event;
use App\Services\Calendar\CalendarAggregator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The authenticated user's aggregated timeline across all connected calendars.
 */
class EventController extends Controller
{
    public function __construct(private readonly CalendarAggregator $aggregator) {}

    public function index(DateRangeRequest $request): AnonymousResourceCollection
    {
        [$start, $end] = $request->range();

        $events = $this->aggregator->events($request->user(), $start, $end, $request->filters());

        return EventResource::collection($events)->additional([
            'meta' => $request->meta() + ['count' => $events->count()],
        ]);
    }

    /**
     * Merged busy time blocks (free/busy view of the aggregated calendar).
     */
    public function busy(DateRangeRequest $request): JsonResponse
    {
        [$start, $end] = $request->range();

        $events = $this->aggregator->events($request->user(), $start, $end, $request->filters());

        return response()->json([
            'data' => $this->aggregator->busyBlocks($events, $start, $end),
            'meta' => $request->meta(),
        ]);
    }

    public function show(Event $event): EventResource
    {
        $event->load('calendar.account');

        $this->authorize('view', $event);

        return new EventResource($event);
    }
}
