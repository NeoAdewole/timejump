<?php

namespace App\Http\Controllers\Api;

use App\Enums\SharePermission;
use App\Http\Controllers\Controller;
use App\Http\Resources\CalendarShareResource;
use App\Models\CalendarShare;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * View access the authenticated user grants to other users (owner side).
 */
class ShareController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $shares = $request->user()->sharesGiven()
            ->with(['grantee', 'calendars'])
            ->latest()
            ->get();

        return CalendarShareResource::collection($shares);
    }

    /**
     * Grant (or update) view access for another Timejump user, identified by email.
     */
    public function store(Request $request): JsonResponse
    {
        $owner = $request->user();

        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'permission' => ['sometimes', Rule::enum(SharePermission::class)],
            ...$this->scopeRules($owner),
        ]);

        $grantee = User::where('email', strtolower($data['email']))->first();

        if (! $grantee) {
            throw ValidationException::withMessages(['email' => 'No Timejump user is registered with that email address.']);
        }

        if ($grantee->is($owner)) {
            throw ValidationException::withMessages(['email' => 'You cannot share your calendar with yourself.']);
        }

        $calendarIds = $data['calendar_ids'] ?? [];

        $share = $owner->sharesGiven()->updateOrCreate(
            ['grantee_id' => $grantee->id],
            [
                'permission' => $data['permission'] ?? SharePermission::Details,
                'all_calendars' => empty($calendarIds),
                'expires_at' => $data['expires_at'] ?? null,
            ]
        );
        $share->calendars()->sync($calendarIds);

        return (new CalendarShareResource($share->load(['grantee', 'calendars'])))
            ->response()
            ->setStatusCode($share->wasRecentlyCreated ? 201 : 200);
    }

    public function show(CalendarShare $share): CalendarShareResource
    {
        $this->authorize('view', $share);

        return new CalendarShareResource($share->load(['grantee', 'calendars']));
    }

    /**
     * Change the permission level, calendar scope, or expiry of an existing share.
     */
    public function update(Request $request, CalendarShare $share): CalendarShareResource
    {
        $this->authorize('update', $share);

        $data = $request->validate([
            'permission' => ['sometimes', Rule::enum(SharePermission::class)],
            ...$this->scopeRules($request->user()),
        ]);

        if (array_key_exists('permission', $data)) {
            $share->permission = $data['permission'];
        }

        if (array_key_exists('expires_at', $data)) {
            $share->expires_at = $data['expires_at'];
        }

        if (array_key_exists('calendar_ids', $data)) {
            $calendarIds = $data['calendar_ids'] ?? [];
            $share->all_calendars = empty($calendarIds);
            $share->calendars()->sync($calendarIds);
        }

        $share->save();

        return new CalendarShareResource($share->load(['grantee', 'calendars']));
    }

    /**
     * Revoke the grantee's access immediately.
     */
    public function destroy(CalendarShare $share): Response
    {
        $this->authorize('delete', $share);

        $share->delete();

        return response()->noContent();
    }

    private function scopeRules(User $owner): array
    {
        return [
            // Omit / null / [] = all calendars (including ones connected later).
            'calendar_ids' => ['sometimes', 'nullable', 'array'],
            'calendar_ids.*' => ['integer', 'distinct', Rule::exists('calendars', 'id')->where('user_id', $owner->id)],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ];
    }
}
