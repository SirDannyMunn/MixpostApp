<?php

/**
 * Extension OAuth Configuration
 *
 * This configuration controls which OAuth clients (Chrome extensions) are allowed
 * to use the Sanctum-authenticated authorization endpoint at /ext/oauth/authorize.
 *
 * This bypasses the standard Passport /oauth/authorize which requires web guard auth,
 * allowing extensions to authenticate users who are already logged into the SPA via Sanctum.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Allowed Client IDs
    |--------------------------------------------------------------------------
    |
    | List of Passport client UUIDs that are allowed to use the extension OAuth
    | authorize endpoint. These should be public clients (no secret) used by
    | Chrome extensions.
    |
    | Format: Array of UUID strings
    |
    */

    'allowed_client_ids' => array_filter(explode(',', env('EXTENSION_OAUTH_CLIENT_IDS', implode(',', [
        '019b9011-e6b0-72d4-b738-c666bbe66d0b', // Production Chrome Extension
        '019be08a-d73f-70de-87d7-60603a6797db', // Development Chrome Extension
    ])))),

    /*
    |--------------------------------------------------------------------------
    | Allowed Redirect URI Origins
    |--------------------------------------------------------------------------
    |
    | List of allowed redirect URI patterns for extension OAuth.
    | These should match the Chrome extension redirect URIs.
    |
    | Chrome extensions use: https://<EXTENSION_ID>.chromiumapp.org/
    |
    | You can use wildcards:
    |   - "*.chromiumapp.org" matches any extension ID
    |   - Exact matches are also supported
    |
    */

    'allowed_redirect_origins' => array_filter(explode(',', env('EXTENSION_OAUTH_REDIRECT_ORIGINS', implode(',', [
        'https://gigbbiaddgeecplclbhingobcmkaklfa.chromiumapp.org',  // Dev extension
        'https://*.chromiumapp.org',  // Allow any Chrome extension (use with caution in production)
    ])))),

    /*
    |--------------------------------------------------------------------------
    | Allowed Scopes
    |--------------------------------------------------------------------------
    |
    | List of OAuth scopes that extension clients are allowed to request.
    | Use '*' to allow all scopes.
    |
    */

    'allowed_scopes' => array_filter(explode(',', env('EXTENSION_OAUTH_SCOPES', '*'))),

    /*
    |--------------------------------------------------------------------------
    | Auto-Approve Mode
    |--------------------------------------------------------------------------
    |
    | When enabled, allowlisted extension clients will be automatically approved
    | without showing a consent screen. This is recommended for first-party
    | extensions where the user has already logged into the SPA.
    |
    | Set to false to require explicit user consent via SPA-owned UI.
    |
    */

    'auto_approve' => env('EXTENSION_OAUTH_AUTO_APPROVE', true),

    /*
    |--------------------------------------------------------------------------
    | Require PKCE
    |--------------------------------------------------------------------------
    |
    | When enabled, the extension OAuth endpoint requires PKCE parameters
    | (code_challenge and code_challenge_method). This should always be true
    | for public clients (Chrome extensions cannot securely store secrets).
    |
    */

    'require_pkce' => env('EXTENSION_OAUTH_REQUIRE_PKCE', true),

    /*
    |--------------------------------------------------------------------------
    | Unauthenticated Redirect
    |--------------------------------------------------------------------------
    |
    | Where to redirect users who are not authenticated via Sanctum.
    | Set to null to return a 401 JSON response instead.
    |
    | Example: '/login' or 'https://app.tryvelocity.app/login'
    |
    */

    'unauthenticated_redirect' => env('EXTENSION_OAUTH_UNAUTH_REDIRECT', null),

];
