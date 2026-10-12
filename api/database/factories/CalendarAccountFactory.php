<?php

namespace Database\Factories;

use App\Enums\AccountStatus;
use App\Enums\Provider;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\CalendarAccount>
 */
class CalendarAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => Provider::Google,
            'provider_account_id' => fake()->uuid(),
            'email' => fake()->safeEmail(),
            'display_name' => fake()->name(),
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'token_expires_at' => now()->addHour(),
            'scopes' => [],
            'status' => AccountStatus::Active,
        ];
    }

    public function outlook(): static
    {
        return $this->state(['provider' => Provider::Outlook]);
    }

    public function ical(string $url = 'https://example.com/calendar.ics'): static
    {
        return $this->state([
            'provider' => Provider::Ical,
            'provider_account_id' => hash('sha256', $url),
            'source_url' => $url,
            'access_token' => null,
            'refresh_token' => null,
            'token_expires_at' => null,
        ]);
    }
}
