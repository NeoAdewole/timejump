<?php

namespace Tests\Feature;

use App\Models\Calendar;
use App\Models\CalendarAccount;
use App\Models\CalendarShare;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SharingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $viewer;

    private Calendar $work;

    private Calendar $personal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-01 00:00:00');

        $this->owner = User::factory()->create(['email' => 'owner@example.com']);
        $this->viewer = User::factory()->create(['email' => 'viewer@example.com']);

        $this->work = Calendar::factory()->for(CalendarAccount::factory()->for($this->owner), 'account')->create(['name' => 'Work']);
        $this->personal = Calendar::factory()->for(CalendarAccount::factory()->ical()->for($this->owner), 'account')->create(['name' => 'Personal']);

        Event::factory()->for($this->work)->between('2026-10-02 09:00', '2026-10-02 10:00')->create(['title' => 'Standup', 'url' => 'https://calendar.google.com/x']);
        Event::factory()->for($this->work)->between('2026-10-02 09:30', '2026-10-02 10:30')->create(['title' => 'Overlap']);
        Event::factory()->for($this->personal)->private()->between('2026-10-03 12:00', '2026-10-03 13:00')->create(['title' => 'Doctor']);
    }

    public function test_owner_can_grant_view_access_by_email(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson('/api/shares', ['email' => 'VIEWER@example.com', 'permission' => 'details'])
            ->assertCreated()
            ->assertJsonPath('data.grantee.email', 'viewer@example.com')
            ->assertJsonPath('data.permission', 'details')
            ->assertJsonPath('data.all_calendars', true)
            ->assertJsonPath('data.is_active', true);

        $this->getJson('/api/shares')->assertOk()->assertJsonCount(1, 'data');

        // Granting again updates the existing share rather than duplicating it.
        $this->postJson('/api/shares', ['email' => 'viewer@example.com', 'permission' => 'free_busy'])
            ->assertOk()
            ->assertJsonPath('data.permission', 'free_busy');
        $this->assertDatabaseCount('calendar_shares', 1);
    }

    public function test_viewer_with_details_access_sees_events_with_private_ones_masked(): void
    {
        $this->share();
        Sanctum::actingAs($this->viewer);

        $this->getJson('/api/shared-with-me')
            ->assertOk()
            ->assertJsonPath('data.0.owner.email', 'owner@example.com');

        $response = $this->getJson("/api/users/{$this->owner->id}/events?start=2026-10-01&end=2026-10-08")
            ->assertOk()
            ->assertJsonPath('meta.permission', 'details')
            ->assertJsonPath('meta.count', 3)
            ->assertJsonPath('data.0.title', 'Standup')
            ->assertJsonPath('data.0.url', null);

        $doctor = collect($response->json('data'))->firstWhere('start', '2026-10-03T12:00:00Z');
        $this->assertSame('Busy', $doctor['title']);
        $this->assertNull($doctor['description']);
        $this->assertNull($doctor['location']);

        $this->getJson("/api/users/{$this->owner->id}/calendars")->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_viewer_with_free_busy_access_only_sees_busy_blocks(): void
    {
        $this->share(['permission' => 'free_busy']);
        Sanctum::actingAs($this->viewer);

        $this->getJson("/api/users/{$this->owner->id}/events?start=2026-10-01&end=2026-10-08")
            ->assertOk()
            ->assertJsonPath('meta.format', 'busy_blocks')
            ->assertJsonPath('data', [
                ['start' => '2026-10-02T09:00:00Z', 'end' => '2026-10-02T10:30:00Z'],
                ['start' => '2026-10-03T12:00:00Z', 'end' => '2026-10-03T13:00:00Z'],
            ])
            ->assertJsonMissing(['title' => 'Standup']);

        $this->getJson("/api/users/{$this->owner->id}/calendars")->assertForbidden();
        $this->getJson("/api/users/{$this->owner->id}/busy?start=2026-10-01&end=2026-10-08")->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_share_can_be_limited_to_specific_calendars(): void
    {
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/shares', ['email' => 'viewer@example.com', 'calendar_ids' => [$this->work->id]])
            ->assertCreated()
            ->assertJsonPath('data.all_calendars', false)
            ->assertJsonPath('data.calendars.0.name', 'Work');

        Sanctum::actingAs($this->viewer);
        $titles = collect($this->getJson("/api/users/{$this->owner->id}/events?start=2026-10-01&end=2026-10-08")->json('data'))->pluck('title');

        $this->assertEquals(['Standup', 'Overlap'], $titles->all());
        $this->getJson("/api/users/{$this->owner->id}/calendars")->assertJsonCount(1, 'data');
    }

    public function test_cannot_share_someone_elses_calendar(): void
    {
        $foreign = Calendar::factory()->create();
        Sanctum::actingAs($this->owner);

        $this->postJson('/api/shares', ['email' => 'viewer@example.com', 'calendar_ids' => [$foreign->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('calendar_ids.0');
    }

    public function test_owner_can_revoke_access(): void
    {
        $share = $this->share();

        Sanctum::actingAs($this->viewer);
        $this->getJson("/api/users/{$this->owner->id}/events")->assertOk();

        Sanctum::actingAs($this->owner);
        $this->deleteJson("/api/shares/{$share->id}")->assertNoContent();

        Sanctum::actingAs($this->viewer);
        $this->getJson("/api/users/{$this->owner->id}/events")->assertForbidden();
        $this->getJson('/api/shared-with-me')->assertJsonCount(0, 'data');
    }

    public function test_owner_can_change_permission_and_scope(): void
    {
        $share = $this->share();
        Sanctum::actingAs($this->owner);

        $this->patchJson("/api/shares/{$share->id}", ['permission' => 'free_busy', 'calendar_ids' => [$this->personal->id]])
            ->assertOk()
            ->assertJsonPath('data.permission', 'free_busy')
            ->assertJsonPath('data.all_calendars', false);

        $this->patchJson("/api/shares/{$share->id}", ['calendar_ids' => null])
            ->assertOk()
            ->assertJsonPath('data.all_calendars', true);
    }

    public function test_expired_shares_grant_no_access(): void
    {
        $this->share(['expires_at' => now()->addDay()]);
        Sanctum::actingAs($this->viewer);
        $this->getJson("/api/users/{$this->owner->id}/events")->assertOk();

        $this->travel(2)->days();

        $this->getJson("/api/users/{$this->owner->id}/events")->assertForbidden();
        $this->getJson('/api/shared-with-me')->assertJsonCount(0, 'data');
    }

    public function test_grantee_can_leave_a_share_but_not_modify_it(): void
    {
        $share = $this->share();
        Sanctum::actingAs($this->viewer);

        $this->patchJson("/api/shares/{$share->id}", ['permission' => 'details'])->assertForbidden();
        $this->deleteJson("/api/shares/{$share->id}")->assertForbidden();

        $this->deleteJson("/api/shared-with-me/{$share->id}")->assertNoContent();
        $this->assertDatabaseCount('calendar_shares', 0);
    }

    public function test_users_without_a_share_are_denied(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/users/{$this->owner->id}/events")->assertForbidden();
        $this->getJson("/api/users/{$this->owner->id}/busy")->assertForbidden();
        $this->getJson("/api/users/{$this->owner->id}/calendars")->assertForbidden();
    }

    public function test_share_validation(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson('/api/shares', ['email' => 'nobody@example.com'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/api/shares', ['email' => 'owner@example.com'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/api/shares', ['email' => 'viewer@example.com', 'permission' => 'admin'])->assertUnprocessable()->assertJsonValidationErrors('permission');
        $this->postJson('/api/shares', ['email' => 'viewer@example.com', 'expires_at' => '2020-01-01'])->assertUnprocessable()->assertJsonValidationErrors('expires_at');
    }

    private function share(array $attributes = []): CalendarShare
    {
        return CalendarShare::factory()->create(array_merge([
            'owner_id' => $this->owner->id,
            'grantee_id' => $this->viewer->id,
        ], $attributes));
    }
}
