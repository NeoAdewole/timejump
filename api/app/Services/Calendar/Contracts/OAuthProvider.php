<?php

namespace App\Services\Calendar\Contracts;

use App\Models\CalendarAccount;
use App\Services\Calendar\Data\OAuthResult;

/**
 * A provider whose accounts are connected with the OAuth 2.0 authorization-code flow.
 */
interface OAuthProvider
{
    public function isConfigured(): bool;

    public function authorizationUrl(string $state): string;

    public function exchangeCode(string $code): OAuthResult;

    public function refreshToken(CalendarAccount $account): void;
}
