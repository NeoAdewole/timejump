<?php

namespace App\Providers;

use App\Services\Calendar\CalendarProviderManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CalendarProviderManager::class);
    }

    public function boot(): void
    {
        Password::defaults(fn () => Password::min(8));

        // Surface lazy-loading and mass-assignment mistakes during development.
        Model::shouldBeStrict(! $this->app->isProduction());
    }
}
