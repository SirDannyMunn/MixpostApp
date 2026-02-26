<?php

namespace Tests\Unit\ProxyManagement;

use LaundryOS\ProxyManagement\Providers\WebShare\WebShareProxyNormalizer;
use PHPUnit\Framework\TestCase;

class WebShareProxyNormalizerTest extends TestCase
{
    public function test_normalize_maps_webshare_payload(): void
    {
        $normalizer = new WebShareProxyNormalizer();
        $dto = $normalizer->normalize([
            'id' => 'abc123',
            'proxy_address' => '31.59.20.176',
            'port' => 6754,
            'username' => 'kvgwjzty',
            'password' => 's1w8juxjg02i',
            'country_code' => 'us',
            'valid' => true,
        ]);

        $this->assertSame('webshare', $dto->provider);
        $this->assertSame('abc123', $dto->providerProxyId);
        $this->assertSame('US', $dto->countryCode);
        $this->assertTrue($dto->isActive);
        $this->assertSame('http://kvgwjzty:s1w8juxjg02i@31.59.20.176:6754', $dto->credential->asUrl());
    }
}
