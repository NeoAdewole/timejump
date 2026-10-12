<?php

namespace App\Http\Controllers\Api;

use App\Enums\AccountStatus;
use App\Enums\Provider;
use App\Http\Controllers\Controller;
use App\Http\Resources\CalendarAccountResource;
use App\Jobs\SyncCalendarAccount;
use App\Models\CalendarAccount;
use App\Models\User;
use App\Services\Calendar\CalendarProviderManager;
use App\Services\Calendar\CalendarSyncService;
use App\Services\Calendar\Contracts\OAuthProvider;
use App\Services\Calendar\Exceptions\InvalidFeedException;
use App\Services\Calendar\Exceptions\ProviderException;
use App\Services\Calendar\UrlGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Connecting external calendar sources: Google / Outlook via OAuth, iCal via URL or upload.
 */
class IntegrationController extends Controller
{
    public function __construct(private readonly CalendarProviderManager $providers) {}

    /**
     * List the supported providers and whether each is ready to use on this server.
     */
    public function index(): JsonResponse
    {
        $data = array_map(fn (Provider $provider) => [
            'provider' => $provider->value,
            'label' => $provider->label(),
            'auth' => $provider->usesOAuth() ? 'oauth' : 'url_or_file',
            'configured' => $provider->usesOAuth() ? $this->providers->oauth($provider)->isConfigured() : true,
        ], Provider::cases());

        return response()->json(['data' => $data]);
    }

    /**
     * Step 1 of OAuth: return the provider's consent URL. The client opens it in a browser.
     */
    public function connect(Request $request, string $provider): JsonResponse
    {
        [$provider, $oauth] = $this->resolveOAuth($provider);

        abort_unless($oauth->isConfigured(), 503, "{$provider->label()} integration is not configured on this server.");

        $state = Str::random(48);
        $ttl = config('timejump.oauth.state_ttl');

        Cache::put($this->stateKey($state), [
            'user_id' => $request->user()->id,
            'provider' => $provider->value,
        ], $ttl);

        return response()->json([
            'provider' => $provider->value,
            'authorization_url' => $oauth->authorizationUrl($state),
            'expires_in' => $ttl,
        ]);
    }

    /**
     * Step 2 of OAuth: the provider redirects the browser here with ?code=&state=.
     * The one-time `state` identifies the Timejump user, so this route needs no bearer token.
     */
    public function callback(Request $request, string $provider): JsonResponse|RedirectResponse
    {
        [$provider, $oauth] = $this->resolveOAuth($provider);

        $state = Cache::pull($this->stateKey((string) $request->query('state', '')));

        if (! is_array($state) || $state['provider'] !== $provider->value) {
            return $this->callbackResult(false, 'Invalid or expired authorization state. Please start the connection again.', 400);
        }

        if ($request->filled('error')) {
            return $this->callbackResult(false, 'Authorization was not granted: '.$request->query('error_description', $request->query('error')), 400);
        }

        $user = User::find($state['user_id']);

        if (! $user || ! $request->filled('code')) {
            return $this->callbackResult(false, 'The authorization response was incomplete.', 400);
        }

        try {
            $result = $oauth->exchangeCode((string) $request->query('code'));
        } catch (ProviderException $e) {
            return $this->callbackResult(false, $e->getMessage(), 502);
        }

        // Reconnecting the same remote account updates it in place (fixes reauth_required).
        $account = $user->calendarAccounts()->firstOrNew([
            'provider' => $provider->value,
            'provider_account_id' => $result->accountId,
        ]);

        $account->fill([
            'email' => $result->email,
            'display_name' => $result->name ?? $result->email,
            'access_token' => $result->accessToken,
            'token_expires_at' => $result->expiresAt,
            'scopes' => $result->scopes,
            'status' => AccountStatus::Active,
            'last_error' => null,
        ]);

        if ($result->refreshToken !== null) {
            $account->refresh_token = $result->refreshToken;
        }

        $account->save();

        SyncCalendarAccount::dispatch($account);

        return $this->callbackResult(true, "{$provider->label()} account connected. Calendars are being imported.", 201, $account);
    }

    /**
     * Import an iCal feed by URL (http, https or webcal), or by uploading an .ics file.
     * The feed is fetched and imported immediately so problems are reported straight away.
     */
    public function importIcal(Request $request, CalendarSyncService $sync, UrlGuard $guard): JsonResponse
    {
        $data = $request->validate([
            'url' => ['required_without:file', 'nullable', 'string', 'max:2048'],
            'file' => ['required_without:url', 'nullable', 'file', 'max:'.intdiv(config('timejump.ical.max_bytes'), 1024)],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $request->user();
        $field = filled($data['url'] ?? null) ? 'url' : 'file';

        $account = new CalendarAccount([
            'provider' => Provider::Ical,
            'display_name' => $data['name'] ?? null,
            'status' => AccountStatus::Active,
        ]);
        $account->user()->associate($user);

        if ($field === 'url') {
            try {
                $url = $guard->normalize($data['url']);
            } catch (InvalidFeedException $e) {
                throw ValidationException::withMessages(['url' => $e->getMessage()]);
            }

            $account->source_url = $url;
            $account->provider_account_id = hash('sha256', $url);
            $account->display_name ??= parse_url($url, PHP_URL_HOST);

            $exists = $user->calendarAccounts()
                ->where('provider', Provider::Ical->value)
                ->where('provider_account_id', $account->provider_account_id)
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages(['url' => 'This iCal feed has already been imported.']);
            }
        } else {
            $file = $request->file('file');
            $content = $file->get();
            $account->provider_account_id = 'upload:'.Str::uuid();
            $account->display_name ??= pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) ?: 'Uploaded calendar';
        }

        $account->save();

        if (isset($content)) {
            Storage::disk('local')->put($account->icalStoragePath(), $content);
        }

        try {
            $summary = $sync->syncAccount($account);
        } catch (ProviderException $e) {
            $account->delete();

            throw ValidationException::withMessages([$field => $e->getMessage()]);
        }

        return (new CalendarAccountResource($account->fresh()->load('calendars.account')))
            ->additional(['sync' => $summary])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * @return array{0: Provider, 1: OAuthProvider}
     */
    private function resolveOAuth(string $provider): array
    {
        $provider = Provider::tryFrom($provider);

        abort_if($provider === null || ! $provider->usesOAuth(), 404, 'Unknown OAuth provider.');

        return [$provider, $this->providers->oauth($provider)];
    }

    private function stateKey(string $state): string
    {
        return 'oauth_state:'.hash('sha256', $state);
    }

    private function callbackResult(bool $ok, string $message, int $status, ?CalendarAccount $account = null): JsonResponse|RedirectResponse
    {
        if ($redirect = config('timejump.oauth.frontend_redirect')) {
            $query = http_build_query(array_filter([
                'status' => $ok ? 'connected' : 'error',
                'message' => $message,
                'account_id' => $account?->id,
            ]));

            return redirect()->away($redirect.(str_contains($redirect, '?') ? '&' : '?').$query);
        }

        return response()->json(array_filter([
            'message' => $message,
            'account' => $account ? new CalendarAccountResource($account->fresh()->load('calendars')) : null,
        ]), $status);
    }
}
