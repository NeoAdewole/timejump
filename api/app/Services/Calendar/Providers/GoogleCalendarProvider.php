<?php

namespace App\Services\Calendar\Providers;

use App\Models\Calendar;
use App\Models\CalendarAccount;
use App\Services\Calendar\Data\CalendarData;
use App\Services\Calendar\Data\EventData;
use App\Services\Calendar\Data\OAuthResult;
use App\Services\Calendar\Exceptions\ProviderException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * Google Calendar API v3.
 */
class GoogleCalendarProvider extends OAuthCalendarProvider
{
    protected const NAME = 'Google';

    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';

    private const API = 'https://www.googleapis.com/calendar/v3';

    public const SCOPES = [
        'openid',
        'email',
        'profile',
        'https://www.googleapis.com/auth/calendar.readonly',
    ];

    protected function tokenUrl(): string
    {
        return self::TOKEN_URL;
    }

    protected function clientConfig(): array
    {
        return config('services.google');
    }

    public function authorizationUrl(string $state): string
    {
        $config = $this->clientConfig();

        return self::AUTH_URL.'?'.http_build_query([
            'client_id' => $config['client_id'],
            'redirect_uri' => $config['redirect'],
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'access_type' => 'offline',      // issue a refresh token
            'prompt' => 'consent',           // ...even on re-connect
            'include_granted_scopes' => 'true',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeCode(string $code): OAuthResult
    {
        $config = $this->clientConfig();

        $token = $this->requestToken([
            'code' => $code,
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'redirect_uri' => $config['redirect'],
            'grant_type' => 'authorization_code',
        ]);

        $profile = Http::withToken($token['access_token'])->acceptJson()->get(self::USERINFO_URL);

        if ($profile->failed() || blank($profile->json('sub'))) {
            throw new ProviderException('Could not read the Google account profile: '.$this->errorMessage($profile));
        }

        return new OAuthResult(
            accessToken: $token['access_token'],
            refreshToken: $token['refresh_token'] ?? null,
            expiresAt: $this->expiry($token['expires_in'] ?? null),
            accountId: (string) $profile->json('sub'),
            email: $profile->json('email'),
            name: $profile->json('name'),
            scopes: array_values(array_filter(explode(' ', $token['scope'] ?? ''))),
        );
    }

    public function fetchCalendars(CalendarAccount $account): array
    {
        $calendars = [];
        $pageToken = null;

        do {
            $data = $this->getJson($account, self::API.'/users/me/calendarList', array_filter([
                'maxResults' => 250,
                'pageToken' => $pageToken,
            ]));

            foreach ($data['items'] ?? [] as $item) {
                if ($item['deleted'] ?? false) {
                    continue;
                }

                $calendars[] = new CalendarData(
                    id: $item['id'],
                    name: $item['summaryOverride'] ?? $item['summary'] ?? $item['id'],
                    description: $item['description'] ?? null,
                    color: $item['backgroundColor'] ?? null,
                    timezone: $item['timeZone'] ?? null,
                    isPrimary: (bool) ($item['primary'] ?? false),
                    isReadOnly: ! in_array($item['accessRole'] ?? 'reader', ['owner', 'writer'], true),
                );
            }

            $pageToken = $data['nextPageToken'] ?? null;
        } while ($pageToken);

        return $calendars;
    }

    public function fetchEvents(CalendarAccount $account, Calendar $calendar, CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        $url = self::API.'/calendars/'.rawurlencode($calendar->provider_calendar_id).'/events';
        $pageToken = null;

        do {
            $data = $this->getJson($account, $url, array_filter([
                'timeMin' => $from->utc()->toRfc3339String(),
                'timeMax' => $to->utc()->toRfc3339String(),
                'singleEvents' => 'true',   // expand recurring events into instances
                'orderBy' => 'startTime',
                'maxResults' => 2500,
                'pageToken' => $pageToken,
            ]));

            foreach ($data['items'] ?? [] as $item) {
                if (($item['status'] ?? null) === 'cancelled' || ! isset($item['start'])) {
                    continue;
                }

                yield $this->mapEvent($item, $data['timeZone'] ?? null);
            }

            $pageToken = $data['nextPageToken'] ?? null;
        } while ($pageToken);
    }

    private function mapEvent(array $item, ?string $calendarTimezone): EventData
    {
        $isAllDay = isset($item['start']['date']);

        if ($isAllDay) {
            $start = CarbonImmutable::parse($item['start']['date'], 'UTC')->startOfDay();
            $end = CarbonImmutable::parse($item['end']['date'] ?? $item['start']['date'], 'UTC')->startOfDay();
        } else {
            $start = CarbonImmutable::parse($item['start']['dateTime']);
            $end = CarbonImmutable::parse($item['end']['dateTime'] ?? $item['start']['dateTime']);
        }

        return new EventData(
            id: $item['id'],
            title: $item['summary'] ?? null,
            startsAt: $start,
            endsAt: $end,
            isAllDay: $isAllDay,
            description: $item['description'] ?? null,
            location: $item['location'] ?? null,
            timezone: $item['start']['timeZone'] ?? $calendarTimezone,
            status: ($item['status'] ?? 'confirmed') === 'tentative' ? 'tentative' : 'confirmed',
            isBusy: ($item['transparency'] ?? 'opaque') !== 'transparent',
            visibility: in_array($item['visibility'] ?? null, ['private', 'confidential'], true) ? 'private' : null,
            organizerEmail: $item['organizer']['email'] ?? null,
            url: $item['htmlLink'] ?? null,
            recurringEventId: $item['recurringEventId'] ?? null,
        );
    }
}
