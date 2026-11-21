<?php

/*
 * Sign-in with SeAT for the intel archive (OAuth 2.0 authorization code + PKCE).
 *
 * All three values live in the server environment only. With any of them
 * empty, or a redirect address that is not https, the
 * /custom-plugin/oauth/* addresses answer 404.
 */
return [
    'intel' => [
        'client_id' => env('CUSTOM_PLUGIN_OAUTH_INTEL_CLIENT_ID'),
        'client_secret' => env('CUSTOM_PLUGIN_OAUTH_INTEL_CLIENT_SECRET'),
        // must match the redirect_uri the intel archive sends, byte for byte
        'redirect_uri' => env('CUSTOM_PLUGIN_OAUTH_INTEL_REDIRECT_URI'),
    ],
];
