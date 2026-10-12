<?php

namespace Tests\Feature;

use App\Models\CalendarAccount;
use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-01 00:00:00');
    }

    private function fakeGoogle(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'g-access',
                'refresh_token' => 'g-refresh',
                'expires_in' => 3599,
                'scope' => 'openid email https://www.googleapis.com/auth/calendar.readonly',
                'token_type' => 'Bearer',
            ]),
            'openidconnect.googleapis.com/*' => Http::response([
                'sub' => 'google-user-1',
                'email' => 'ada@gmail.com',
                'name' => 'Ada',
            ]),
            'www.googleapis.com/calendar/v3/users/me/calendarList*' => Http::response([
                'items' => [
                    ['id' => 'primary@gmail.com', 'summary' => 'Ada', 'primary' => true, 'accessRole' => 'owner', 'backgroundColor' => '#9fe1e7', 'timeZone' => 'Europe/London'],
                    ['id' => 'en.uk#holiday@group.v.calendar.google.com', 'summary' => 'Holidays', 'accessRole' => 'reader'],
                ],
            ]),
            'www.googleapis.com/calendar/v3/calendars/primary%40gmail.com/events*' => Http::response([
                'timeZone' => 'Europe/London',
                'items' => [
                    [
                        'id' => 'evt1', 'status' => 'confirmed', 'summary' => 'Dentist',
                        'start' => ['dateTime' => '2026-10-02T10:00:00+01:00'],
                        'end' => ['dateTime' => '2026-10-02T11:00:00+01:00'],
                        'htmlLink' => 'https://calendar.google.com/event?eid=1',
                    ],
                    [
                        'id' => 'evt2', 'status' => 'cancelled',
                        'start' => ['dateTime' => '2026-10-03T10:00:00Z'], 'end' => ['dateTime' => '2026-10-03T11:00:00Z'],
                    ],
                    [
                        'id' => 'evt3_20261005', 'status' => 'confirmed', 'summary' => 'Standup',
                        'recurringEventId' => 'evt3', 'transparency' => 'transparent',
                        'start' => ['dateTime' => '2026-10-05T09:00:00Z'], 'end' => ['dateTime' => '2026-10-05T09:15:00Z'],
                    ],
                ],
            ]),
            'www.googleapis.com/calendar/v3/calendars/*/events*' => Http::response([
                'items' => [
                    ['id' => 'hol1', 'summary' => 'Bank holiday', 'start' => ['date' => '2026-12-25'], 'end' => ['date' => '2026-12-26']],
                ],
            ]),
        ]);
    }

    public function test_connect_returns_a_google_consent_url(): void
    {
        $this->actingAsUser();

        $response = $this->getJson('/api/integrations/google/connect')->assertOk();

        $url = $response->json('authorization_url');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);

        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('google-client-id', $query['client_id']);
        $this->assertSame('offline', $query['access_type']);
        $this->assertStringContainsString('calendar.readonly', $query['scope']);
        $this->assertNotEmpty($query['state']);
    }

    public function test_callback_connects_the_account_and_imports_calendars(): void
    {
        $this->fakeGoogle();
        $user = $this->actingAsUser();

        $url = $this->getJson('/api/integrations/google/connect')->json('authorization_url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        // The browser comes back without a bearer token; `state` identifies the user.
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/integrations/google/callback?code=auth-code&state='.$query['state'])
            ->assertCreated()
            ->assertJsonPath('account.provider', 'google')
            ->assertJsonPath('account.email', 'ada@gmail.com');

        $account = CalendarAccount::firstOrFail();
        $this->assertSame($user->id, $account->user_id);
        $this->assertSame('g-access', $account->access_token);
        $this->assertSame('g-refresh', $account->refresh_token);

        // Tokens are encrypted at rest.
        $raw = $this->app['db']->table('calendar_accounts')->value('access_token');
        $this->assertNotSame('g-access', $raw);

        $this->assertSame(2, $account->calendars()->count());
        $this->assertSame(3, Event::count()); // cancelled event skipped

        $dentist = Event::where('provider_event_id', 'evt1')->firstOrFail();
        $this->assertSame('2026-10-02 09:00:00', $dentist->starts_at->format('Y-m-d H:i:s'));

        $standup = Event::where('provider_event_id', 'evt3_20261005')->firstOrFail();
        $this->assertFalse($standup->is_busy);
        $this->assertSame('evt3', $standup->recurring_event_id);

        $holiday = Event::where('provider_event_id', 'hol1')->firstOrFail();
        $this->assertTrue($holiday->is_all_day);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://oauth2.googleapis.com/token' && $r['code'] === 'auth-code');
    }

    public function test_state_cannot_be_reused_or_forged(): void
    {
        $this->fakeGoogle();
        $this->actingAsUser();

        $url = $this->getJson('/api/integrations/google/connect')->json('authorization_url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        $this->getJson('/api/integrations/google/callback?code=x&state=forged')->assertStatus(400);
        $this->getJson('/api/integrations/outlook/callback?code=x&state='.$query['state'])->assertStatus(400);

        // The state was consumed by the (wrong-provider) attempt above.
        $this->getJson('/api/integrations/google/callback?code=x&state='.$query['state'])->assertStatus(400);
        $this->assertDatabaseCount('calendar_accounts', 0);
    }

    public function test_user_denying_consent_is_reported(): void
    {
        $this->actingAsUser();
        $url = $this->getJson('/api/integrations/google/connect')->json('authorization_url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        $this->getJson('/api/integrations/google/callback?error=access_denied&state='.$query['state'])
            ->assertStatus(400)
            ->assertJsonPath('message', 'Authorization was not granted: access_denied');
    }

    public function test_expired_access_tokens_are_refreshed_before_syncing(): void
    {
        $this->fakeGoogle();
        $account = CalendarAccount::factory()->create([
            'access_token' => 'stale',
            'refresh_token' => 'g-refresh-old',
            'token_expires_at' => now()->subMinute(),
        ]);
        $this->actingAsUser($account->user);

        $this->postJson("/api/accounts/{$account->id}/sync")->assertAccepted();

        $account->refresh();
        $this->assertSame('g-access', $account->access_token);
        $this->assertTrue($account->token_expires_at->isFuture());
        Http::assertSent(fn (Request $r) => $r->url() === 'https://oauth2.googleapis.com/token' && $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === 'g-refresh-old');
    }

    public function test_a_revoked_grant_marks_the_account_for_reconnection(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'], 400)]);
        $account = CalendarAccount::factory()->create(['token_expires_at' => now()->subMinute()]);
        $this->actingAsUser($account->user);

        $this->postJson("/api/accounts/{$account->id}/sync")->assertAccepted();

        $this->getJson("/api/accounts/{$account->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'reauth_required');
    }

    public function test_connect_fails_cleanly_when_not_configured(): void
    {
        config(['services.google.client_id' => null]);
        $this->actingAsUser();

        $this->getJson('/api/integrations/google/connect')->assertStatus(503);

        $this->getJson('/api/integrations')
            ->assertOk()
            ->assertJsonFragment(['provider' => 'google', 'configured' => false])
            ->assertJsonFragment(['provider' => 'outlook', 'configured' => true])
            ->assertJsonFragment(['provider' => 'ical', 'configured' => true]);
    }
}
