<?php

namespace App\Services\Calendar;

use App\Enums\Provider;
use App\Services\Calendar\Contracts\CalendarProvider;
use App\Services\Calendar\Contracts\OAuthProvider;
use App\Services\Calendar\Providers\GoogleCalendarProvider;
use App\Services\Calendar\Providers\IcalProvider;
use App\Services\Calendar\Providers\OutlookCalendarProvider;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Resolves provider implementations. A fresh instance is returned on every call so
 * per-sync state (e.g. parsed iCal feeds) never leaks between queue jobs.
 */
class CalendarProviderManager
{
    public function __construct(private readonly Container $container) {}

    public function provider(Provider|string $provider): CalendarProvider
    {
        $provider = $provider instanceof Provider ? $provider : Provider::from($provider);

        return $this->container->make(match ($provider) {
            Provider::Google => GoogleCalendarProvider::class,
            Provider::Outlook => OutlookCalendarProvider::class,
            Provider::Ical => IcalProvider::class,
        });
    }

    public function oauth(Provider|string $provider): OAuthProvider
    {
        $instance = $this->provider($provider);

        if (! $instance instanceof OAuthProvider) {
            $name = $provider instanceof Provider ? $provider->value : $provider;

            throw new InvalidArgumentException("Provider [{$name}] does not use OAuth.");
        }

        return $instance;
    }
}
