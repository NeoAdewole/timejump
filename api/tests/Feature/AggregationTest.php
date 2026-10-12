<?php

namespace Tests\Feature;

use App\Models\Calendar;
use App\Models\CalendarAccount;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AggregationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Calendar $google;

    private Calendar $outlook;

    private Calendar $ical;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-01 00:00:00');
        $this->user = $this->actingAsUser();

        $this->google = Calendar::factory()->for(CalendarAccount::factory()->for($this->user), 'account')->create(['name' => 'Work']);
        $this->outlook = Calendar::factory()->for(CalendarAccount::factory()->outlook()->for($this->user), 'account')->create(['name' => 'Office']);
        $this->ical = Calendar::factory()->for(CalendarAccount::factory()->ical()->for($this->user), 'account')->create(['name' => 'Holidays']);
    }

    public function test_events_from_all_providers_are_merged_in_chronological_order(): void
    {
        Event::factory()->for($this->outlook)->between('2026-10-03 09:00', '2026-10-03 10:00')->create(['title' => 'Outlook B']);
        Event::factory()->for($this->google)->between('2026-10-02 09:00', '2026-10-02 10:00')->create(['title' => 'Google A']);
        Event::factory()->for($this->ical)->between('2026-10-04 00:00', '2026-10-05 00:00')->create(['title' => 'iCal C', 'is_all_day' => true]);

        $this->getJson('/api/events?start=2026-10-01&end=2026-10-08')
            ->assertOk()
            ->assertJsonPath('meta.count', 3)
            ->assertJsonPath('data.0.title', 'Google A')
            ->assertJsonPath('data.0.calendar.provider', 'google')
            ->assertJsonPath('data.1.title', 'Outlook B')
            ->assertJsonPath('data.1.calendar.provider', 'outlook')
            ->assertJsonPath('data.2.title', 'iCal C')
            ->assertJsonPath('data.2.calendar.provider', 'ical')
            ->assertJsonPath('data.0.start', '2026-10-02T09:00:00Z');
    }

    public function test_only_events_overlapping_the_range_are_returned(): void
    {
        Event::factory()->for($this->google)->between('2026-09-30 23:00', '2026-10-01 01:00')->create(['title' => 'Overlaps start']);
        Event::factory()->for($this->google)->between('2026-10-05 10:00', '2026-10-05 11:00')->create(['title' => 'Inside']);
        Event::factory()->for($this->google)->between('2026-10-08 00:00', '2026-10-08 01:00')->create(['title' => 'Starts at end']);
        Event::factory()->for($this->google)->between('2026-09-20 10:00', '2026-09-20 11:00')->create(['title' => 'Before']);

        $titles = collect($this->getJson('/api/events?start=2026-10-01T00:00:00Z&end=2026-10-08T00:00:00Z')->json('data'))->pluck('title');

        $this->assertEquals(['Overlaps start', 'Inside'], $titles->all());
    }

    public function test_range_is_interpreted_in_the_users_timezone(): void
    {
        $this->user->update(['timezone' => 'America/New_York']);
        // 2026-10-02 02:00 UTC is still Oct 1 in New York (UTC-4).
        Event::factory()->for($this->google)->between('2026-10-02 02:00', '2026-10-02 03:00')->create(['title' => 'Late evening']);

        $this->getJson('/api/events?start=2026-10-01&end=2026-10-02')
            ->assertOk()
            ->assertJsonPath('meta.start', '2026-10-01T04:00:00Z')
            ->assertJsonPath('data.0.title', 'Late evening');
    }

    public function test_disabled_calendars_and_other_users_events_are_excluded(): void
    {
        Event::factory()->for($this->google)->create(['title' => 'Mine']);

        $hidden = Calendar::factory()->disabled()->for(CalendarAccount::factory()->for($this->user), 'account')->create();
        Event::factory()->for($hidden)->create(['title' => 'Disabled']);

        Event::factory()->create(['title' => 'Somebody else']);

        $this->getJson('/api/events')
            ->assertOk()
            ->assertJsonPath('meta.count', 1)
            ->assertJsonPath('data.0.title', 'Mine');
    }

    public function test_events_can_be_filtered_by_calendar_provider_and_search(): void
    {
        Event::factory()->for($this->google)->create(['title' => 'Design review']);
        Event::factory()->for($this->outlook)->create(['title' => 'Budget review']);
        Event::factory()->for($this->outlook)->create(['title' => 'Lunch']);

        $this->getJson("/api/events?calendar_ids[]={$this->google->id}")->assertJsonPath('meta.count', 1);
        $this->getJson('/api/events?provider=outlook')->assertJsonPath('meta.count', 2);
        $this->getJson('/api/events?q=review')->assertJsonPath('meta.count', 2);
    }

    public function test_duplicate_meetings_across_calendars_can_be_collapsed(): void
    {
        Event::factory()->for($this->google)->between('2026-10-02 09:00', '2026-10-02 10:00')->create(['title' => 'All hands']);
        Event::factory()->for($this->outlook)->between('2026-10-02 09:00', '2026-10-02 10:00')->create(['title' => 'All Hands ']);

        $this->getJson('/api/events')->assertJsonPath('meta.count', 2);
        $this->getJson('/api/events?dedupe=1')->assertJsonPath('meta.count', 1);
    }

    public function test_busy_blocks_merge_overlapping_events_and_ignore_free_time(): void
    {
        Event::factory()->for($this->google)->between('2026-10-02 09:00', '2026-10-02 10:00')->create();
        Event::factory()->for($this->outlook)->between('2026-10-02 09:30', '2026-10-02 11:00')->create();
        Event::factory()->for($this->outlook)->between('2026-10-02 11:00', '2026-10-02 11:30')->create();
        Event::factory()->for($this->google)->free()->between('2026-10-02 13:00', '2026-10-02 14:00')->create();
        Event::factory()->for($this->ical)->between('2026-10-02 15:00', '2026-10-02 16:00')->create();

        $this->getJson('/api/events/busy?start=2026-10-02&end=2026-10-03')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    ['start' => '2026-10-02T09:00:00Z', 'end' => '2026-10-02T11:30:00Z'],
                    ['start' => '2026-10-02T15:00:00Z', 'end' => '2026-10-02T16:00:00Z'],
                ],
                'meta' => ['start' => '2026-10-02T00:00:00Z', 'end' => '2026-10-03T00:00:00Z'],
            ]);
    }

    public function test_invalid_ranges_are_rejected(): void
    {
        $this->getJson('/api/events?start=2026-10-05&end=2026-10-01')->assertUnprocessable()->assertJsonValidationErrors('end');
        $this->getJson('/api/events?start=2026-01-01&end=2028-01-01')->assertUnprocessable()->assertJsonValidationErrors('end');
    }

    public function test_calendars_can_be_listed_and_toggled(): void
    {
        $this->getJson('/api/calendars')->assertOk()->assertJsonCount(3, 'data');

        $this->patchJson("/api/calendars/{$this->outlook->id}", ['is_enabled' => false, 'custom_color' => '#112233'])
            ->assertOk()
            ->assertJsonPath('data.is_enabled', false)
            ->assertJsonPath('data.color', '#112233');

        $this->getJson('/api/calendars?enabled=1')->assertJsonCount(2, 'data');
    }

    public function test_users_cannot_access_each_others_resources(): void
    {
        $theirEvent = Event::factory()->create();
        $theirCalendar = $theirEvent->calendar()->first();

        $this->getJson("/api/events/{$theirEvent->id}")->assertForbidden();
        $this->getJson("/api/calendars/{$theirCalendar->id}")->assertForbidden();
        $this->patchJson("/api/calendars/{$theirCalendar->id}", ['is_enabled' => false])->assertForbidden();
        $this->getJson("/api/accounts/{$theirCalendar->calendar_account_id}")->assertForbidden();
        $this->deleteJson("/api/accounts/{$theirCalendar->calendar_account_id}")->assertForbidden();
    }
}
