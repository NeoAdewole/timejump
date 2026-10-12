<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_register_and_receives_a_token(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Ada Lovelace',
            'email' => 'Ada@Example.com',
            'password' => 'correct-horse',
            'password_confirmation' => 'correct-horse',
            'timezone' => 'Europe/London',
        ]);

        $response->assertCreated()
            ->assertJsonPath('user.email', 'ada@example.com')
            ->assertJsonPath('user.timezone', 'Europe/London')
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonStructure(['user' => ['id', 'name', 'email'], 'token']);

        $this->assertDatabaseHas('users', ['email' => 'ada@example.com']);

        // The issued token works against protected routes.
        $this->withToken($response->json('token'))
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.name', 'Ada Lovelace');
    }

    public function test_registration_is_validated(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/auth/register', [
            'name' => '',
            'email' => 'taken@example.com',
            'password' => 'short',
            'password_confirmation' => 'different',
            'timezone' => 'Mars/Olympus',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'password', 'timezone']);
    }

    public function test_a_user_can_log_in_and_out(): void
    {
        User::factory()->create(['email' => 'grace@example.com', 'password' => 'secret-pass']);

        $token = $this->postJson('/api/auth/login', [
            'email' => 'grace@example.com',
            'password' => 'secret-pass',
        ])->assertOk()->json('token');

        $this->withToken($token)->postJson('/api/auth/logout')->assertNoContent();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->create(['email' => 'grace@example.com']);

        $this->postJson('/api/auth/login', [
            'email' => 'grace@example.com',
            'password' => 'nope',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_protected_routes_require_a_token(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
        $this->get('/api/events')->assertUnauthorized()->assertJson(['message' => 'Unauthenticated.']);
    }

    public function test_profile_can_be_updated(): void
    {
        $this->actingAsUser();

        $this->patchJson('/api/me', ['name' => 'New Name', 'timezone' => 'America/New_York'])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.timezone', 'America/New_York');
    }
}
