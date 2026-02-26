<?php

namespace Tests\Unit\ProxyManagement;

use LaundryOS\ProxyManagement\DTO\ProxyCredentialDto;
use PHPUnit\Framework\TestCase;

class ProxyCredentialDtoTest extends TestCase
{
    public function test_as_url_with_auth_and_without_auth(): void
    {
        $withAuth = new ProxyCredentialDto('http', '31.59.20.176', 6754, 'kvgwjzty', 's1w8juxjg02i');
        $this->assertSame('http://kvgwjzty:s1w8juxjg02i@31.59.20.176:6754', $withAuth->asUrl());

        $withoutAuth = new ProxyCredentialDto('http', '127.0.0.1', 8080);
        $this->assertSame('http://127.0.0.1:8080', $withoutAuth->asUrl());
    }
}
