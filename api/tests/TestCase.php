<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    protected function actingAsUser(?User $user = null): User
    {
        $user ??= User::factory()->create();

        Sanctum::actingAs($user);

        return $user;
    }

    protected function fixture(string $name): string
    {
        return file_get_contents(__DIR__.'/Fixtures/'.$name);
    }
}
