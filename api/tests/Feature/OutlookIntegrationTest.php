<?php

namespace Tests\Feature;

use App\Models\CalendarAccount;
use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OutlookIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-01 00:00:00');
    }

    public function test_connect_returns_a_microsoft_consent_url(): void
    {
        $this->actingAsUser();

        $url = $this->getJson('/api/integrations/outlook/connect')->assertOk()->json('authorization_url');

        $this->assertStringStartsWith('https://login.microsoftonline.com/common/oauth2/v2.0/authorize?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('ms-client-id', $query['client_id']);
        $this->assertStringContainsString('Calendars.Read', $query['scope']);
        $this->assertStringContainsString('offline_access', $query['scope']);
    }

    public function test_callback_connects_the_account_and_imports_calendars(): void
    {
        Http::fake([
            'login.microsoftonline.com/common/oauth2/v2.0/token' => Http::response([
                'access_token' => 'ms-access',
                'refresh_token' => 'ms-refresh',
                'expires_in' => 3600,
                'scope' => 'Calendars.Read User.Read',
            ]),
            'graph.microsoft.com/v1.0/me/calendars/*/calendarView*' => Http::sequence()
                ->push([
                    'value' => [
                        [
                            'id' => 'AAMk-1', 'subject' => 'Quarterly review', 'bodyPreview' => 'Agenda',
                            'start' => ['dateTime' => '2026-10-02T13:00:00.0000000', 'timeZone' => 'UTC'],
                            'end' => ['dateTime' => '2026-10-02T14:00:00.0000000', 'timeZone' => 'UTC'],
                            'isAllDay' => false, 'isCancelled' => false, 'showAs' => 'busy', 'sensitivity' => 'normal',
                            'location' => ['displayName' => 'Teams'],
                            'organizer' => ['emailAddress' => ['address' => 'boss@contoso.com']],
                        ],
                        [
                            'id' => 'AAMk-2', 'subject' => 'Cancelled',
                            'start' => ['dateTime' => '2026-10-03T13:00:00.0000000', 'timeZone' => 'UTC'],
                            'end' => ['dateTime' => '2026-10-03T14:00:00.0000000', 'timeZone' => 'UTC'],
                            'isCancelled' => true,
                        ],
                    ],
                    '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/me/calendars/cal-1/calendarView?$skip=250',
                ])
                ->push([
                    'value' => [
                        [
                            'id' => 'AAMk-3', 'subject' => 'Offsite',
                            'start' => ['dateTime' => '2026-10-09T00:00:00.0000000', 'timeZone' => 'UTC'],
                            'end' => ['dateTime' => '2026-10-10T00:00:00.0000000', 'timeZone' => 'UTC'],
                            'isAllDay' => true, 'showAs' => 'tentative', 'sensitivity' => 'private',
                        ],
                    ],
                ]),
            'graph.microsoft.com/v1.0/me/calendars*' => Http::response([
                'value' => [
                    ['id' => 'cal-1', 'name' => 'Calendar', 'hexColor' => '#3366ff', 'canEdit' => true, 'isDefaultCalendar' => true],
                ],
            ]),
            'graph.microsoft.com/v1.0/me*' => Http::response([
                'id' => 'ms-user-1',
                'displayName' => 'Grace Hopper',
                'userPrincipalName' => 'grace@contoso.com',
                'mail' => null,
            ]),
        ]);

        $this->actingAsUser();
        $url = $this->getJson('/api/integrations/outlook/connect')->json('authorization_url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        $this->getJson('/api/integrations/outlook/callback?code=ms-code&state='.$query['state'])
            ->assertCreated()
            ->assertJsonPath('account.provider', 'outlook')
            ->assertJsonPath('account.email', 'grace@contoso.com');

        $account = CalendarAccount::firstOrFail();
        $this->assertSame('ms-refresh', $account->refresh_token);
        $this->assertTrue($account->calendars()->first()->is_primary);

        $this->assertSame(2, Event::count()); // paginated, cancelled skipped

        $review = Event::where('provider_event_id', 'AAMk-1')->firstOrFail();
        $this->assertSame('2026-10-02 13:00:00', $review->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('Teams', $review->location);
        $this->assertSame('boss@contoso.com', $review->organizer_email);

        $offsite = Event::where('provider_event_id', 'AAMk-3')->firstOrFail();
        $this->assertTrue($offsite->is_all_day);
        $this->assertSame('tentative', $offsite->status);
        $this->assertSame('private', $offsite->visibility);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'calendarView') && $r->header('Prefer') === ['outlook.timezone="UTC"']);
    }
}
