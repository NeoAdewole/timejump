<?php

namespace Database\Seeders;

use App\Enums\SharePermission;
use App\Models\Calendar;
use App\Models\CalendarAccount;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Demo data: two users with sample (non-syncing) calendars, and a share between them.
 * Log in as alice@timejump.test / password or bob@timejump.test / password.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $alice = User::factory()->create(['name' => 'Alice Demo', 'email' => 'alice@timejump.test']);
        $bob = User::factory()->create(['name' => 'Bob Demo', 'email' => 'bob@timejump.test']);

        foreach ([$alice, $bob] as $user) {
            // Demo accounts are marked as errored so the scheduler does not try to sync fake tokens.
            $accounts = [
                CalendarAccount::factory()->for($user)->create(['status' => 'error', 'last_error' => 'Demo account (not connected)']),
                CalendarAccount::factory()->outlook()->for($user)->create(['status' => 'error', 'last_error' => 'Demo account (not connected)']),
            ];

            foreach ($accounts as $account) {
                $calendar = Calendar::factory()->for($account, 'account')->create(['user_id' => $user->id, 'is_primary' => true]);
                Event::factory()->count(8)->for($calendar)->create();
            }
        }

        $alice->sharesGiven()->create([
            'grantee_id' => $bob->id,
            'permission' => SharePermission::Details,
            'all_calendars' => true,
        ]);

        $bob->sharesGiven()->create([
            'grantee_id' => $alice->id,
            'permission' => SharePermission::FreeBusy,
            'all_calendars' => true,
        ]);
    }
}
