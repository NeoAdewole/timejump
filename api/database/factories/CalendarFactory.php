<?php

namespace Database\Factories;

use App\Models\CalendarAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Calendar>
 */
class CalendarFactory extends Factory
{
    public function definition(): array
    {
        return [
            'calendar_account_id' => CalendarAccount::factory(),
            'user_id' => fn (array $attributes) => CalendarAccount::find($attributes['calendar_account_id'])->user_id,
            'provider_calendar_id' => fake()->uuid(),
            'name' => ucfirst(fake()->words(2, true)),
            'color' => fake()->hexColor(),
            'timezone' => 'UTC',
            'is_primary' => false,
            'is_read_only' => false,
            'is_enabled' => true,
        ];
    }

    public function disabled(): static
    {
        return $this->state(['is_enabled' => false]);
    }
}
