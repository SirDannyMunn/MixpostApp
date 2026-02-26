<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Schema;
use LaundryOS\PhantomBrowseCore\Models\ApiKey;
use LaundryOS\PhantomBrowseCore\Services\ApiKeyService;
use Tests\TestCase;

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
}
