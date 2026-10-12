<?php

use App\Enums\Provider;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CalendarAccountController;
use App\Http\Controllers\Api\CalendarController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\IntegrationController;
use App\Http\Controllers\Api\SharedCalendarController;
use App\Http\Controllers\Api\ShareController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Timejump API  (all routes are prefixed with /api)
|--------------------------------------------------------------------------
*/

// Registration & login
Route::middleware('throttle:10,1')->group(function () {
    Route::post('auth/register', [AuthController::class, 'register']);
    Route::post('auth/login', [AuthController::class, 'login']);
});

// OAuth redirect target. Authenticated by the one-time `state` parameter, not a bearer token.
Route::get('integrations/{provider}/callback', [IntegrationController::class, 'callback'])
    ->whereIn('provider', Provider::oauthValues())
    ->name('integrations.callback');

Route::middleware('auth:sanctum')->group(function () {
    // Session / profile
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('me', [AuthController::class, 'me']);
    Route::patch('me', [AuthController::class, 'updateMe']);

    // Integrations: connect Google / Outlook, import iCal
    Route::get('integrations', [IntegrationController::class, 'index']);
    Route::get('integrations/{provider}/connect', [IntegrationController::class, 'connect'])
        ->whereIn('provider', Provider::oauthValues());
    Route::post('integrations/ical', [IntegrationController::class, 'importIcal'])->middleware('throttle:20,1');

    // Connected accounts
    Route::get('accounts', [CalendarAccountController::class, 'index']);
    Route::get('accounts/{account}', [CalendarAccountController::class, 'show'])->whereNumber('account');
    Route::post('accounts/{account}/sync', [CalendarAccountController::class, 'sync'])->whereNumber('account')->middleware('throttle:10,1');
    Route::delete('accounts/{account}', [CalendarAccountController::class, 'destroy'])->whereNumber('account');

    // Calendars (all sources)
    Route::get('calendars', [CalendarController::class, 'index']);
    Route::get('calendars/{calendar}', [CalendarController::class, 'show'])->whereNumber('calendar');
    Route::patch('calendars/{calendar}', [CalendarController::class, 'update'])->whereNumber('calendar');

    // Aggregated events
    Route::get('events', [EventController::class, 'index']);
    Route::get('events/busy', [EventController::class, 'busy']);
    Route::get('events/{event}', [EventController::class, 'show'])->whereNumber('event');

    // Access I grant to others
    Route::apiResource('shares', ShareController::class)->whereNumber('share');

    // Access others have granted to me
    Route::get('shared-with-me', [SharedCalendarController::class, 'index']);
    Route::delete('shared-with-me/{share}', [SharedCalendarController::class, 'leave'])->whereNumber('share');
    Route::get('users/{user}/calendars', [SharedCalendarController::class, 'calendars'])->whereNumber('user');
    Route::get('users/{user}/events', [SharedCalendarController::class, 'events'])->whereNumber('user');
    Route::get('users/{user}/busy', [SharedCalendarController::class, 'busy'])->whereNumber('user');
});
