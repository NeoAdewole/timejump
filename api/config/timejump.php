<?php

return [

    'sync' => [
        // Events are imported for this window around "now".
        'past_days' => (int) env('TIMEJUMP_SYNC_PAST_DAYS', 30),
        'future_days' => (int) env('TIMEJUMP_SYNC_FUTURE_DAYS', 365),
    ],

    'oauth' => [
        // How long a "connect" link stays valid, in seconds.
        'state_ttl' => (int) env('TIMEJUMP_OAUTH_STATE_TTL', 600),

        // When set, the OAuth callback redirects here (with ?status=&message=&account_id=)
        // instead of returning JSON. Useful for a SPA / mobile front end.
        'frontend_redirect' => env('TIMEJUMP_OAUTH_FRONTEND_REDIRECT'),
    ],

    'ical' => [
        'max_bytes' => (int) env('TIMEJUMP_ICAL_MAX_BYTES', 10 * 1024 * 1024),
        'timeout' => (int) env('TIMEJUMP_ICAL_TIMEOUT', 20),
        'max_redirects' => 3,

        // Blocks feeds on private/loopback/link-local addresses (SSRF protection).
        // Only enable for local development.
        'allow_private_hosts' => (bool) env('TIMEJUMP_ICAL_ALLOW_PRIVATE_HOSTS', false),
    ],

    'events' => [
        // Largest date range that can be requested from the events endpoints.
        'max_range_days' => (int) env('TIMEJUMP_EVENTS_MAX_RANGE_DAYS', 366),
        'default_range_days' => 30,
    ],

];
