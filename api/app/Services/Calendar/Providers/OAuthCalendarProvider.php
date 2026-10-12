<?php

namespace App\Services\Calendar\Providers;

use App\Models\CalendarAccount;
use App\Services\Calendar\Contracts\CalendarProvider;
use App\Services\Calendar\Contracts\OAuthProvider;
use App\Services\Calendar\Exceptions\ProviderException;
use App\Services\Calendar\Exceptions\ReauthorizationRequired;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Shared OAuth 2.0 plumbing: code exchange, token refresh and authenticated API calls.
 */
abstract class OAuthCalendarProvider implements CalendarProvider, OAuthProvider
{
    /** Human-readable provider name used in error messages. */
    protected const NAME = 'Provider';

    abstract protected function tokenUrl(): string;

    /**
     * @return array{client_id: ?string, client_secret: ?string, redirect: string}
     */
    abstract protected function clientConfig(): array;

    public function isConfigured(): bool
    {
        $config = $this->clientConfig();

        return filled($config['client_id'] ?? null) && filled($config['client_secret'] ?? null);
    }

    public function refreshToken(CalendarAccount $account): void
    {
        if (blank($account->refresh_token)) {
            throw new ReauthorizationRequired(static::NAME.' did not issue a refresh token; reconnect the account.');
        }

        try {
            $response = Http::asForm()->acceptJson()->post($this->tokenUrl(), $this->refreshPayload($account->refresh_token));
        } catch (ConnectionException $e) {
            throw new ProviderException('Could not reach '.static::NAME.' to refresh the access token.', previous: $e);
        }

        if ($response->failed() || blank($response->json('access_token'))) {
            throw new ReauthorizationRequired(static::NAME.' token refresh failed: '.$this->errorMessage($response));
        }

        $account->forceFill([
            'access_token' => $response->json('access_token'),
            // Providers may rotate refresh tokens; keep the old one if a new one is not issued.
            'refresh_token' => $response->json('refresh_token') ?: $account->refresh_token,
            'token_expires_at' => $this->expiry($response->json('expires_in')),
        ])->save();
    }

    protected function refreshPayload(string $refreshToken): array
    {
        $config = $this->clientConfig();

        return [
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ];
    }

    /**
     * Exchange an authorization code (or other grant) at the token endpoint.
     */
    protected function requestToken(array $payload): array
    {
        try {
            $response = Http::asForm()->acceptJson()->post($this->tokenUrl(), $payload);
        } catch (ConnectionException $e) {
            throw new ProviderException('Could not reach '.static::NAME.' to complete authorization.', previous: $e);
        }

        if ($response->failed() || blank($response->json('access_token'))) {
            throw new ProviderException('Could not exchange the '.static::NAME.' authorization code: '.$this->errorMessage($response));
        }

        return $response->json();
    }

    /**
     * Authenticated GET returning decoded JSON. Refreshes the access token when it is
     * about to expire, and retries once on a 401.
     */
    protected function getJson(CalendarAccount $account, string $url, array $query = [], array $headers = []): array
    {
        $response = $this->send($account, $url, $query, $headers);

        if ($response->status() === 401) {
            $this->refreshToken($account);
            $response = $this->send($account, $url, $query, $headers);
        }

        if ($response->status() === 401) {
            throw new ReauthorizationRequired(static::NAME.' rejected the stored credentials; reconnect the account.');
        }

        if ($response->failed()) {
            throw new ProviderException(sprintf('%s API error (HTTP %d): %s', static::NAME, $response->status(), $this->errorMessage($response)));
        }

        return $response->json() ?? [];
    }

    private function send(CalendarAccount $account, string $url, array $query, array $headers): Response
    {
        try {
            return $this->client($account)->withHeaders($headers)->get($url, $query ?: null);
        } catch (ConnectionException $e) {
            throw new ProviderException('Could not reach '.static::NAME.': '.$e->getMessage(), previous: $e);
        }
    }

    protected function client(CalendarAccount $account): PendingRequest
    {
        if ($account->token_expires_at !== null && $account->token_expires_at->lte(now()->addMinute())) {
            $this->refreshToken($account);
        }

        return Http::withToken((string) $account->access_token)->acceptJson()->timeout(30);
    }

    protected function expiry(mixed $expiresIn): ?CarbonImmutable
    {
        return is_numeric($expiresIn) ? CarbonImmutable::now()->addSeconds((int) $expiresIn) : null;
    }

    protected function errorMessage(Response $response): string
    {
        $error = $response->json('error');

        return (string) ($response->json('error_description')
            ?? (is_array($error) ? ($error['message'] ?? json_encode($error)) : $error)
            ?? 'HTTP '.$response->status());
    }
}
