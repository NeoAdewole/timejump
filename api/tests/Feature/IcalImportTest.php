<?php

namespace Tests\Feature;

use App\Models\CalendarAccount;
use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IcalImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-01 00:00:00');
    }

    public function test_an_ical_feed_can_be_imported_by_url(): void
    {
        Http::fake(['feeds.example.com/*' => Http::response($this->fixture('sample.ics'), 200, ['Content-Type' => 'text/calendar'])]);
        $user = $this->actingAsUser();

        $response = $this->postJson('/api/integrations/ical', ['url' => 'webcal://feeds.example.com/team.ics']);

        $response->assertCreated()
            ->assertJsonPath('data.provider', 'ical')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.calendars.0.name', 'Team Holidays')
            ->assertJsonPath('data.calendars.0.is_read_only', true)
            // single + all-day + 4 weekly occurrences + private = 7 (cancelled + out-of-window skipped)
            ->assertJsonPath('sync.events', 7);

        // webcal:// is fetched over https.
        Http::assertSent(fn ($request) => $request->url() === 'https://feeds.example.com/team.ics');

        $account = CalendarAccount::firstOrFail();
        $this->assertSame($user->id, $account->user_id);
        $this->assertSame('https://feeds.example.com/team.ics', $account->source_url);

        // Timezone-aware event converted to UTC (09:00 BST = 08:00 UTC).
        $kickoff = Event::where('title', 'Project kickoff')->firstOrFail();
        $this->assertSame('2026-10-05 08:00:00', $kickoff->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-05 09:30:00', $kickoff->ends_at->format('Y-m-d H:i:s'));

        $holiday = Event::where('title', 'Company holiday')->firstOrFail();
        $this->assertTrue($holiday->is_all_day);
        $this->assertFalse($holiday->is_busy);

        $this->assertSame(4, Event::where('title', 'Weekly sync')->count());
        $this->assertSame('private', Event::where('title', 'Doctor appointment')->value('visibility'));
        $this->assertDatabaseMissing('events', ['title' => 'Cancelled meeting']);
        $this->assertDatabaseMissing('events', ['title' => 'Outside the sync window']);
    }

    public function test_an_ics_file_can_be_uploaded(): void
    {
        Storage::fake('local');
        $this->actingAsUser();

        $file = UploadedFile::fake()->createWithContent('family.ics', $this->fixture('sample.ics'));

        $this->post('/api/integrations/ical', ['file' => $file, 'name' => 'Family'])
            ->assertCreated()
            ->assertJsonPath('data.display_name', 'Family')
            ->assertJsonPath('sync.events', 7);

        $account = CalendarAccount::firstOrFail();
        Storage::disk('local')->assertExists($account->icalStoragePath());

        // Deleting the account removes the stored file and imported data.
        $this->deleteJson("/api/accounts/{$account->id}")->assertNoContent();
        Storage::disk('local')->assertMissing($account->icalStoragePath());
        $this->assertDatabaseCount('events', 0);
    }

    public function test_resyncing_removes_events_deleted_from_the_feed(): void
    {
        $feed = $this->fixture('sample.ics');
        Http::fakeSequence('feeds.example.com/*')
            ->push($feed)
            ->push(preg_replace('/BEGIN:VEVENT\r?\nUID:single-event.*?END:VEVENT\r?\n/s', '', $feed));
        $this->actingAsUser();

        $id = $this->postJson('/api/integrations/ical', ['url' => 'https://feeds.example.com/a.ics'])->json('data.id');
        $this->assertDatabaseHas('events', ['title' => 'Project kickoff']);

        $this->postJson("/api/accounts/{$id}/sync")->assertAccepted();

        $this->assertDatabaseMissing('events', ['title' => 'Project kickoff']);
        $this->assertSame(6, Event::count());
    }

    public function test_invalid_feeds_are_rejected_and_nothing_is_saved(): void
    {
        Http::fake(['feeds.example.com/*' => Http::response('<html>Not a calendar</html>', 200)]);
        $this->actingAsUser();

        $this->postJson('/api/integrations/ical', ['url' => 'https://feeds.example.com/bad.ics'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('url');

        $this->assertDatabaseCount('calendar_accounts', 0);
    }

    public function test_the_same_feed_cannot_be_imported_twice(): void
    {
        Http::fake(['feeds.example.com/*' => Http::response($this->fixture('sample.ics'))]);
        $this->actingAsUser();

        $this->postJson('/api/integrations/ical', ['url' => 'https://feeds.example.com/a.ics'])->assertCreated();
        $this->postJson('/api/integrations/ical', ['url' => 'https://feeds.example.com/a.ics'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('url');
    }

    public function test_private_network_urls_are_blocked(): void
    {
        config(['timejump.ical.allow_private_hosts' => false]);
        Http::fake();
        $this->actingAsUser();

        foreach (['http://127.0.0.1/cal.ics', 'http://10.0.0.5/cal.ics', 'http://169.254.169.254/latest', 'ftp://example.com/cal.ics'] as $url) {
            $this->postJson('/api/integrations/ical', ['url' => $url])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('url');
        }

        Http::assertNothingSent();
    }
}
