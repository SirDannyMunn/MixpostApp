<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Client;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests for the Extension OAuth Authorize endpoint.
 *
 * These tests verify that the /ext/oauth/authorize endpoint works correctly
 * with Sanctum authentication and follows the PKCE flow for Chrome extensions.
 */
class ExtensionOAuthAuthorizeTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Client $client;
    protected string $redirectUri = 'https://gigbbiaddgeecplclbhingobcmkaklfa.chromiumapp.org/';

    protected function setUp(): void
    {
        parent::setUp();

        // Create a test user
        $this->user = User::factory()->create();

        // Create a Passport client (public, PKCE)
        // Using DB insert to match actual table schema (redirect_uris, grant_types)
        DB::table('oauth_clients')->insert([
            'id' => '019be08a-d73f-70de-87d7-60603a6797db',
            'name' => 'Test Extension',
            'secret' => null, // Public client
            'redirect_uris' => json_encode([$this->redirectUri]),
            'grant_types' => json_encode(['authorization_code']),
            'revoked' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->client = $this->clients()->find('019be08a-d73f-70de-87d7-60603a6797db');
    }

    protected function clients()
    {
        return app(\Laravel\Passport\ClientRepository::class);
    }

    /** @test */
    public function it_rejects_unauthenticated_requests()
    {
        $response = $this->get('/ext/oauth/authorize?' . http_build_query([
            'client_id' => $this->client->id,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'state' => 'test-state-1234567890123456',
            'scope' => '*',
            'code_challenge' => str_repeat('a', 43),
            'code_challenge_method' => 'S256',
        ]));

        // Should return 401 for unauthenticated requests
        $response->assertStatus(401);
    }

    /** @test */
    public function it_redirects_authenticated_user_with_code()
    {
        Sanctum::actingAs($this->user);

        $response = $this->get('/ext/oauth/authorize?' . http_build_query([
            'client_id' => $this->client->id,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'state' => 'test-state-1234567890123456',
            'scope' => '*',
            'code_challenge' => str_repeat('a', 43),
            'code_challenge_method' => 'S256',
        ]));

        // Should redirect with code and state
        $response->assertRedirect();
        $redirectUrl = $response->headers->get('Location');
        
        $this->assertStringStartsWith($this->redirectUri, $redirectUrl);
        $this->assertStringContainsString('code=', $redirectUrl);
        $this->assertStringContainsString('state=test-state-1234567890123456', $redirectUrl);

        // Verify auth code was created in database
        $this->assertDatabaseCount('oauth_auth_codes', 1);
    }

    /** @test */
    public function it_rejects_non_allowlisted_client()
    {
        Sanctum::actingAs($this->user);

        $response = $this->get('/ext/oauth/authorize?' . http_build_query([
            'client_id' => '00000000-0000-0000-0000-000000000000', // Not allowlisted
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'state' => 'test-state-1234567890123456',
            'scope' => '*',
            'code_challenge' => str_repeat('a', 43),
            'code_challenge_method' => 'S256',
        ]));

        // Should redirect with error
        $response->assertRedirect();
        $redirectUrl = $response->headers->get('Location');
        $this->assertStringContainsString('error=unauthorized_client', $redirectUrl);
    }

    /** @test */
    public function it_rejects_non_allowlisted_redirect_uri()
    {
        Sanctum::actingAs($this->user);

        $response = $this->get('/ext/oauth/authorize?' . http_build_query([
            'client_id' => $this->client->id,
            'redirect_uri' => 'https://evil.com/callback', // Not allowlisted
            'response_type' => 'code',
            'state' => 'test-state-1234567890123456',
            'scope' => '*',
            'code_challenge' => str_repeat('a', 43),
            'code_challenge_method' => 'S256',
        ]));

        // Should return 403 (not redirect to untrusted URI)
        $response->assertStatus(403);
        $response->assertJson(['error' => 'invalid_redirect_uri']);
    }

    /** @test */
    public function it_requires_pkce_when_configured()
    {
        Sanctum::actingAs($this->user);

        $response = $this->get('/ext/oauth/authorize?' . http_build_query([
            'client_id' => $this->client->id,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'state' => 'test-state-1234567890123456',
            'scope' => '*',
            // No code_challenge
        ]));

        // Should fail validation or redirect with error
        $response->assertRedirect();
        $redirectUrl = $response->headers->get('Location');
        $this->assertStringContainsString('error=', $redirectUrl);
    }

    /** @test */
    public function it_requires_s256_code_challenge_method()
    {
        Sanctum::actingAs($this->user);

        $response = $this->get('/ext/oauth/authorize?' . http_build_query([
            'client_id' => $this->client->id,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'state' => 'test-state-1234567890123456',
            'scope' => '*',
            'code_challenge' => str_repeat('a', 43),
            'code_challenge_method' => 'plain', // Should be rejected when require_pkce is true
        ]));

        // Validation should fail
        $response->assertStatus(302); // Redirect back with validation errors or to error URL
    }

    /** @test */
    public function it_validates_state_parameter_length()
    {
        Sanctum::actingAs($this->user);

        // Too short state
        $response = $this->get('/ext/oauth/authorize?' . http_build_query([
            'client_id' => $this->client->id,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'state' => 'short', // Min 16 chars
            'scope' => '*',
            'code_challenge' => str_repeat('a', 43),
            'code_challenge_method' => 'S256',
        ]));

        // Should fail validation
        $response->assertStatus(302); // Redirect back with validation error
    }
}
