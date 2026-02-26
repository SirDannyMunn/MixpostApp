<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Schema;
use LaundryOS\PhantomBrowseCore\Models\ApiKey;
use LaundryOS\PhantomBrowseCore\Services\ApiKeyService;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

#[RunTestsInSeparateProcesses]
class PhantomBrowseLocalServiceKeyTest extends TestCase
{
    public function test_it_resolves_local_service_key(): void
    {
        if (!Schema::hasTable('pb_api_keys')) {
            $this->markTestSkipped('pb_api_keys table does not exist in the current test database');
        }

        /** @var ApiKeyService $service */
        $service = app(ApiKeyService::class);

        $apiKey = $service->resolveLocalServiceKey();

        $this->assertNotEmpty($apiKey->id);
        $this->assertNotEmpty($apiKey->key_hash);
        $this->assertTrue($apiKey->is_active);

        $expectedName = (string) config('phantombrowse-core.local_service.key_name', 'browseruse-local-service');
        $this->assertSame($expectedName, $apiKey->name);

        $this->assertTrue(
            ApiKey::query()->where('id', $apiKey->id)->exists(),
            'Expected local service ApiKey row to exist'
        );
    }

    public function test_it_can_create_a_plaintext_api_key_token_pair(): void
    {
        if (!Schema::hasTable('pb_api_keys')) {
            $this->markTestSkipped('pb_api_keys table does not exist in the current test database');
        }

        /** @var ApiKeyService $service */
        $service = app(ApiKeyService::class);

        $name = 'test-created-key-' . now()->format('YmdHis');
        $result = $service->createApiKey(name: $name, rateLimit: 1234);

        /** @var ApiKey $apiKey */
        $apiKey = $result['apiKey'];
        $token = (string) ($result['token'] ?? '');

        $this->assertNotEmpty($token);
        $this->assertSame($name, $apiKey->name);
        $this->assertSame(1234, (int) $apiKey->rate_limit);
        $this->assertSame(hash('sha256', $token), $apiKey->key_hash);
        $this->assertTrue($apiKey->is_active);

        $resolved = $service->fromToken($token);
        $this->assertSame($apiKey->id, $resolved->id);
    }
}
