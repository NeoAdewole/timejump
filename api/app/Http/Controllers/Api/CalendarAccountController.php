<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CalendarAccountResource;
use App\Jobs\SyncCalendarAccount;
use App\Models\CalendarAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Connected calendar sources (one per Google/Microsoft login or iCal feed).
 */
class CalendarAccountController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $accounts = $request->user()->calendarAccounts()
            ->withCount('calendars')
            ->orderBy('created_at')
            ->get();

        return CalendarAccountResource::collection($accounts);
    }

    public function show(CalendarAccount $account): CalendarAccountResource
    {
        $this->authorize('view', $account);

        return new CalendarAccountResource($account->load('calendars.account'));
    }

    public function sync(CalendarAccount $account): JsonResponse
    {
        $this->authorize('sync', $account);

        SyncCalendarAccount::dispatch($account);

        return response()->json(['message' => 'Sync queued.'], 202);
    }

    /**
     * Disconnect the source. Its calendars and imported events are removed.
     */
    public function destroy(CalendarAccount $account): Response
    {
        $this->authorize('delete', $account);

        $account->delete();

        return response()->noContent();
    }
}
