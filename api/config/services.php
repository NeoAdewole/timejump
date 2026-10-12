<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Calendar provider OAuth clients
    |--------------------------------------------------------------------------
    |
    | Google: create an OAuth 2.0 "Web application" client in Google Cloud
    | Console with the Calendar API enabled.
    | Microsoft: register an app in Microsoft Entra ID (Azure AD) with the
    | delegated Graph permissions User.Read, Calendars.Read, offline_access.
    |
    */

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', rtrim((string) env('APP_URL', 'http://localhost:8080'), '/').'/api/integrations/google/callback'),
    ],

    'microsoft' => [
        'client_id' => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        'tenant' => env('MICROSOFT_TENANT', 'common'),
        'redirect' => env('MICROSOFT_REDIRECT_URI', rtrim((string) env('APP_URL', 'http://localhost:8080'), '/').'/api/integrations/outlook/callback'),
    ],

];
