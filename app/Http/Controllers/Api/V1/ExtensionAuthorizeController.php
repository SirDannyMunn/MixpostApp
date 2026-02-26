<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use DateInterval;
use DateTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;

/**
 * Extension OAuth Authorize Controller
 *
 * Handles OAuth2 Authorization Code + PKCE flow for Chrome extensions
 * when the user is authenticated via Sanctum (SPA login) instead of
 * the traditional web guard.
 *
 * This controller provides a dedicated endpoint that:
 * 1. Uses Sanctum authentication (not web guard)
 * 2. Only allows allowlisted extension clients
 * 3. Auto-approves trusted first-party extensions
 * 4. Never redirects to /login
 *
 * The auth code format is designed to be compatible with Passport's
 * /oauth/token endpoint for code exchange.
 */
class ExtensionAuthorizeController extends Controller
{
    public function __construct(
        protected ClientRepository $clients,
    ) {}

    /**
     * Handle the authorization request from a Chrome extension.
     *
     * @param Request $request
     * @return RedirectResponse|JsonResponse
     */
    public function handleAuthorize(Request $request): RedirectResponse|JsonResponse
    {
        // 1. Validate required OAuth parameters
        $validated = $request->validate([
            'client_id' => 'required|string|uuid',
            'redirect_uri' => 'required|url',
            'response_type' => 'required|in:code',
            'state' => 'required|string|min:16|max:128',
            'scope' => 'nullable|string',
            'code_challenge' => config('extension_oauth.require_pkce') ? 'required|string|min:43|max:128' : 'nullable|string',
            'code_challenge_method' => config('extension_oauth.require_pkce') ? 'required|in:S256' : 'nullable|in:S256,plain',
        ]);

        $clientId = $validated['client_id'];
        $redirectUri = $validated['redirect_uri'];
        $state = $validated['state'];
        $scope = $validated['scope'] ?? '*';
        $codeChallenge = $validated['code_challenge'] ?? null;
        $codeChallengeMethod = $validated['code_challenge_method'] ?? 'S256';

        Log::info('Extension OAuth authorize request', [
            'client_id' => $clientId,
            'redirect_uri' => $this->maskUri($redirectUri),
            'scope' => $scope,
            'has_pkce' => !empty($codeChallenge),
        ]);

        // 2. Validate client is allowlisted
        $allowedClients = config('extension_oauth.allowed_client_ids', []);
        if (!in_array($clientId, $allowedClients, true)) {
            Log::warning('Extension OAuth: Rejected non-allowlisted client', [
                'client_id' => $clientId,
            ]);
            return $this->errorRedirect($redirectUri, $state, 'unauthorized_client', 'This client is not authorized to use extension OAuth.');
        }

        // 3. Validate redirect URI is allowlisted
        if (!$this->isRedirectUriAllowed($redirectUri)) {
            Log::warning('Extension OAuth: Rejected non-allowlisted redirect URI', [
                'redirect_uri' => $redirectUri,
            ]);
            // Don't redirect to untrusted URI - return JSON error
            return response()->json([
                'error' => 'invalid_redirect_uri',
                'error_description' => 'The redirect URI is not allowlisted for extension OAuth.',
            ], 403);
        }

        // 4. Validate the Passport client exists and redirect URI matches
        $client = $this->clients->find($clientId);
        if (!$client) {
            Log::warning('Extension OAuth: Client not found', ['client_id' => $clientId]);
            return $this->errorRedirect($redirectUri, $state, 'invalid_client', 'The OAuth client was not found.');
        }

        if (!$this->clientRedirectUriMatches($client, $redirectUri)) {
            Log::warning('Extension OAuth: Redirect URI mismatch', [
                'client_id' => $clientId,
                'requested_uri' => $redirectUri,
            ]);
            return $this->errorRedirect($redirectUri, $state, 'invalid_redirect_uri', 'The redirect URI does not match the client configuration.');
        }

        // 5. Validate scope is allowed
        if (!$this->isScopeAllowed($scope)) {
            Log::warning('Extension OAuth: Scope not allowed', ['scope' => $scope]);
            return $this->errorRedirect($redirectUri, $state, 'invalid_scope', 'The requested scope is not allowed for extension OAuth.');
        }

        // 6. Validate PKCE if required
        if (config('extension_oauth.require_pkce') && empty($codeChallenge)) {
            Log::warning('Extension OAuth: PKCE required but not provided');
            return $this->errorRedirect($redirectUri, $state, 'invalid_request', 'PKCE (code_challenge) is required for extension OAuth.');
        }

        // 7. Get the authenticated user
        // Try multiple auth guards: web session (after login redirect) and Sanctum (API/SPA)
        $user = $request->user('web') ?? $request->user('sanctum') ?? $request->user();
        if (!$user) {
            Log::info('Extension OAuth: User not authenticated, redirecting to login');
            
            // Redirect to the Velocity login page - after login, Laravel's intended redirect will bring them back
            return redirect()->guest('/login');
        }

        // 8. Auto-approve or show consent (based on config)
        if (!config('extension_oauth.auto_approve')) {
            // Mode 2: SPA-owned consent - not implemented in this version
            // Would redirect to SPA consent page which then calls back to approve
            return response()->json([
                'error' => 'consent_required',
                'error_description' => 'User consent is required. Auto-approve is disabled.',
                'consent_url' => null, // SPA would handle this
            ], 403);
        }

        // 9. Create the authorization code
        try {
            $authCode = $this->createAuthorizationCode(
                $client,
                $user,
                $scope,
                $redirectUri,
                $codeChallenge,
                $codeChallengeMethod
            );
        } catch (\Exception $e) {
            Log::error('Extension OAuth: Failed to create auth code', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->errorRedirect($redirectUri, $state, 'server_error', 'Failed to create authorization code.');
        }

        Log::info('Extension OAuth: Authorization successful', [
            'client_id' => $clientId,
            'user_id' => $user->id,
        ]);

        // 10. Redirect back to extension with code
        $params = http_build_query([
            'code' => $authCode,
            'state' => $state,
        ]);

        $separator = str_contains($redirectUri, '?') ? '&' : '?';
        return redirect()->to($redirectUri . $separator . $params);
    }

    /**
     * Create a Passport-compatible authorization code for the user.
     *
     * This method creates an auth code that can be exchanged at POST /oauth/token.
     * Passport v13 uses encrypted JSON payloads for auth codes.
     */
    protected function createAuthorizationCode(
        $client,
        $user,
        string $scope,
        string $redirectUri,
        ?string $codeChallenge,
        string $codeChallengeMethod
    ): string {
        // Generate a unique auth code identifier
        $authCodeId = Str::random(80);

        // Calculate expiry (10 minutes is standard for auth codes)
        $expiresAt = now()->addMinutes(10);

        // Parse scopes
        $scopes = $scope === '*' ? ['*'] : array_filter(explode(' ', $scope));

        // Store the auth code in the database (Passport's oauth_auth_codes table)
        DB::table('oauth_auth_codes')->insert([
            'id' => $authCodeId,
            'user_id' => $user->id,
            'client_id' => $client->id,
            'scopes' => json_encode($scopes),
            'revoked' => false,
            'expires_at' => $expiresAt,
        ]);

        // Build the auth code payload that Passport expects
        // This mirrors what League OAuth2 Server creates
        $payload = [
            'client_id' => $client->id,
            'redirect_uri' => $redirectUri,
            'auth_code_id' => $authCodeId,
            'scopes' => $scopes,
            'user_id' => $user->id,
            'expire_time' => $expiresAt->getTimestamp(),
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => $codeChallengeMethod,
        ];

        // Encrypt the payload using Passport's encryption key
        $encryptedCode = $this->encryptAuthCode($payload);

        Log::debug('Extension OAuth: Created auth code', [
            'auth_code_id' => $authCodeId,
            'client_id' => $client->id,
            'user_id' => $user->id,
            'expires_at' => $expiresAt->toIso8601String(),
        ]);

        return $encryptedCode;
    }

    /**
     * Encrypt the auth code payload using Passport's encryption.
     *
     * Passport uses Defuse\Crypto for encryption in v13+.
     * The output is already ASCII-safe (hex-encoded), no base64 needed.
     */
    protected function encryptAuthCode(array $payload): string
    {
        $json = json_encode($payload);

        // Get the encryption key the same way Passport does
        $encrypter = app('encrypter');
        $encryptionKey = \Laravel\Passport\Passport::tokenEncryptionKey($encrypter);

        // Use Defuse crypto (what Passport/League OAuth2 Server uses internally)
        // The output is hex-encoded and starts with "def5" - already ASCII-safe
        return \Defuse\Crypto\Crypto::encryptWithPassword($json, $encryptionKey);
    }

    /**
     * Check if a redirect URI is allowed by configuration.
     */
    protected function isRedirectUriAllowed(string $redirectUri): bool
    {
        $allowedOrigins = config('extension_oauth.allowed_redirect_origins', []);
        
        $parsedUri = parse_url($redirectUri);
        $host = $parsedUri['host'] ?? '';
        $scheme = $parsedUri['scheme'] ?? '';
        
        foreach ($allowedOrigins as $allowed) {
            // Handle exact match
            if (str_starts_with($redirectUri, $allowed)) {
                return true;
            }
            
            // Handle wildcard patterns like "https://*.chromiumapp.org"
            $parsedAllowed = parse_url($allowed);
            $allowedHost = $parsedAllowed['host'] ?? '';
            $allowedScheme = $parsedAllowed['scheme'] ?? '';
            
            if ($scheme === $allowedScheme) {
                // Check wildcard host
                if (str_starts_with($allowedHost, '*.')) {
                    $suffix = substr($allowedHost, 1); // Get ".chromiumapp.org"
                    if (str_ends_with($host, $suffix)) {
                        return true;
                    }
                } elseif ($host === $allowedHost) {
                    return true;
                }
            }
        }
        
        return false;
    }

    /**
     * Check if the redirect URI matches the client's registered URIs.
     */
    protected function clientRedirectUriMatches($client, string $redirectUri): bool
    {
        // Passport v13 stores redirect_uris as JSON array in the database
        // The model may have it as a string or already decoded array
        $clientRedirects = $client->redirect_uris ?? $client->redirect ?? null;
        
        if (is_string($clientRedirects)) {
            // Try JSON decode first
            $decoded = json_decode($clientRedirects, true);
            if (is_array($decoded)) {
                $clientRedirects = $decoded;
            } else {
                // Single redirect URI
                $clientRedirects = [$clientRedirects];
            }
        }
        
        if (!is_array($clientRedirects)) {
            $clientRedirects = $clientRedirects ? [$clientRedirects] : [];
        }
        
        foreach ($clientRedirects as $allowed) {
            // Normalize URIs (remove trailing slash for comparison)
            $normalizedAllowed = rtrim($allowed, '/');
            $normalizedRequested = rtrim($redirectUri, '/');
            
            if ($normalizedAllowed === $normalizedRequested) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Check if the requested scope is allowed.
     */
    protected function isScopeAllowed(string $scope): bool
    {
        $allowedScopes = config('extension_oauth.allowed_scopes', ['*']);
        
        // If '*' is in allowed scopes, everything is allowed
        if (in_array('*', $allowedScopes, true)) {
            return true;
        }
        
        // Check each requested scope
        $requestedScopes = $scope === '*' ? ['*'] : array_filter(explode(' ', $scope));
        
        foreach ($requestedScopes as $requested) {
            if (!in_array($requested, $allowedScopes, true)) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Build an error redirect response.
     */
    protected function errorRedirect(string $redirectUri, string $state, string $error, string $description): RedirectResponse
    {
        $params = http_build_query([
            'error' => $error,
            'error_description' => $description,
            'state' => $state,
        ]);
        
        $separator = str_contains($redirectUri, '?') ? '&' : '?';
        return redirect()->to($redirectUri . $separator . $params);
    }

    /**
     * Mask a URI for logging (hide sensitive parts).
     */
    protected function maskUri(string $uri): string
    {
        $parsed = parse_url($uri);
        return ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? 'unknown') . '/...';
    }
}
