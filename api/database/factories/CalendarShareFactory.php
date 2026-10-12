<?php

namespace Database\Factories;

use App\Enums\SharePermission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\CalendarShare>
 */
class CalendarShareFactory extends Factory
{
    public function definition(): array
    {
        return [
            'owner_id' => User::factory(),
            'grantee_id' => User::factory(),
            'permission' => SharePermission::Details,
            'all_calendars' => true,
            'expires_at' => null,
        ];
    }

    public function freeBusy(): static
    {
        return $this->state(['permission' => SharePermission::FreeBusy]);
    }
}
