<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Handles OAuth state parameter encoding/decoding for cross-domain OAuth flows.
 * 
 * The state parameter is the canonical transport for context in OAuth flows,
 * eliminating session coupling and enabling stateless, horizontally-scalable deployments.
 */
class OAuthStateService
{
    /**
     * Issuer identifier for state validation
     */
    protected const ISSUER = 'velocity-social-scheduler';

    /**
     * State validity duration in seconds (5 minutes)
     */
    protected const STATE_TTL = 300;

    /**
     * Allowed return URL domains (allowlist)
     */
    protected array $allowedReturnDomains = [
        'velocity.app',
        'www.velocity.app',
        'tryvelocity.app',
        'www.tryvelocity.app',
        'localhost',
        '127.0.0.1',
        // Figma preview domains
        'figma.site',
        'figmaiframepreview.figma.site',
        'https://trait-viral-96814077.figma.site',
        // Development domains
        'social-scheduler-dev.usewebmania.com',
    ];

    /**
     * Allowed client types
     */
    protected const ALLOWED_CLIENTS = ['web', 'figma', 'chrome_ext'];

    /**
     * Create an encrypted state payload for OAuth flow.
     *
     * @param string $returnUrl The URL to redirect to after OAuth completes
     * @param string|int $organizationId The organization UUID/ID
     * @param string|int $userId The user UUID/ID
     * @param string $client The client type (web|figma|chrome_ext)
     * @return string Encrypted state string
     * @throws InvalidArgumentException If return URL is not in allowlist
     */
    public function encode(
        string $returnUrl,
        string|int $organizationId,
        string|int $userId,
        string $client = 'web'
    ): string {
        // Validate return URL against allowlist
        $this->validateReturnUrl($returnUrl);

        // Validate client type
        if (!in_array($client, self::ALLOWED_CLIENTS, true)) {
            throw new InvalidArgumentException("Invalid client type: {$client}");
        }

        $payload = [
            'iss' => self::ISSUER,
            'return_url' => $returnUrl,
            'org_id' => (string) $organizationId,
            'user_id' => (string) $userId,
            'client' => $client,
            'nonce' => Str::random(32),
            'iat' => time(),
            'exp' => time() + self::STATE_TTL,
        ];

        return Crypt::encryptString(json_encode($payload));
    }

    /**
     * Decode and validate an encrypted state payload.
     *
     * @param string $encryptedState The encrypted state string from OAuth callback
     * @return array The decoded payload
     * @throws InvalidArgumentException If state is invalid, expired, or tampered
     */
    public function decode(string $encryptedState): array
    {
        try {
            $json = Crypt::decryptString($encryptedState);
            $payload = json_decode($json, true);

            if (!is_array($payload)) {
                throw new InvalidArgumentException('Invalid state payload format');
            }

            // Validate required fields
            $requiredFields = ['iss', 'return_url', 'org_id', 'user_id', 'client', 'iat', 'exp'];
            foreach ($requiredFields as $field) {
                if (!isset($payload[$field])) {
                    throw new InvalidArgumentException("Missing required field: {$field}");
                }
            }

            // Validate issuer
            if ($payload['iss'] !== self::ISSUER) {
                throw new InvalidArgumentException('Invalid state issuer');
            }

            // Validate client type
            if (!in_array($payload['client'], self::ALLOWED_CLIENTS, true)) {
                throw new InvalidArgumentException('Invalid client type in state');
            }

            // Validate expiration
            if ($payload['exp'] < time()) {
                throw new InvalidArgumentException('State has expired');
            }

            // Validate return URL (re-validate in case of tampering)
            $this->validateReturnUrl($payload['return_url']);

            return $payload;

        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            throw new InvalidArgumentException('Failed to decrypt state: ' . $e->getMessage());
        }
    }

    /**
     * Validate that a return URL is in the allowlist.
     *
     * @param string $url The URL to validate
     * @throws InvalidArgumentException If URL is not allowed
     */
    protected function validateReturnUrl(string $url): void
    {
        $parsed = parse_url($url);

        if (!isset($parsed['host'])) {
            throw new InvalidArgumentException('Invalid return URL: missing host');
        }

        $host = strtolower($parsed['host']);

        // Check direct match
        if (in_array($host, $this->allowedReturnDomains, true)) {
            return;
        }

        // Check subdomain patterns (e.g., *.figma.site)
        foreach ($this->allowedReturnDomains as $allowedDomain) {
            if (str_ends_with($host, '.' . $allowedDomain)) {
                return;
            }
        }

        throw new InvalidArgumentException("Return URL domain not allowed: {$host}");
    }

    /**
     * Add a domain to the allowlist at runtime.
     *
     * @param string $domain The domain to allow
     * @return static
     */
    public function allowDomain(string $domain): static
    {
        $this->allowedReturnDomains[] = strtolower($domain);
        return $this;
    }

    /**
     * Set allowed domains from configuration.
     *
     * @param array $domains Array of allowed domains
     * @return static
     */
    public function setAllowedDomains(array $domains): static
    {
        $this->allowedReturnDomains = array_map('strtolower', $domains);
        return $this;
    }

    /**
     * Check if a state string is valid without throwing exceptions.
     *
     * @param string $encryptedState The encrypted state string
     * @return bool True if valid, false otherwise
     */
    public function isValid(string $encryptedState): bool
    {
        try {
            $this->decode($encryptedState);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
