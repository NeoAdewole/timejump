<?php

namespace App\Services\Calendar\Data;

use Carbon\CarbonImmutable;

final readonly class OAuthResult
{
    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        public string $accessToken,
        public ?string $refreshToken,
        public ?CarbonImmutable $expiresAt,
        public string $accountId,
        public ?string $email = null,
        public ?string $name = null,
        public array $scopes = [],
    ) {}
}
