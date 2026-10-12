<?php

namespace Database\Factories;

use App\Models\Calendar;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Event>
 */
class EventFactory extends Factory
{
    public function definition(): array
    {
        $start = now()->utc()->addDays(fake()->numberBetween(1, 14))->setTime(fake()->numberBetween(8, 17), 0);

        return [
            'calendar_id' => Calendar::factory(),
            'provider_event_id' => fake()->uuid(),
            'title' => ucfirst(fake()->words(3, true)),
            'description' => fake()->sentence(),
            'location' => fake()->city(),
            'starts_at' => $start,
            'ends_at' => $start->copy()->addHour(),
            'is_all_day' => false,
            'timezone' => 'UTC',
            'status' => 'confirmed',
            'is_busy' => true,
        ];
    }

    public function between(string $start, string $end): static
    {
        return $this->state(['starts_at' => $start, 'ends_at' => $end]);
    }

    public function private(): static
    {
        return $this->state(['visibility' => 'private']);
    }

    public function free(): static
    {
        return $this->state(['is_busy' => false]);
    }
}
