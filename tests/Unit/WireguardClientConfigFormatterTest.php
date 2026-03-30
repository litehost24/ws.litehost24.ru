<?php

namespace Tests\Unit;

use App\Services\VpnAgent\WireguardClientConfigFormatter;
use PHPUnit\Framework\TestCase;

class WireguardClientConfigFormatterTest extends TestCase
{
    public function test_make_amneziawg_compatible_keeps_only_ipv4_addresses_and_routes(): void
    {
        $formatter = new WireguardClientConfigFormatter();

        $source = <<<CONF
[Interface]
PrivateKey = test-private
Address = 10.78.78.3/32, fd78:78:78::3/128
DNS = 10.78.78.1, fd78:78:78::1
Jc = 4

[Peer]
PublicKey = test-public
Endpoint = 45.94.47.139:51820
AllowedIPs = 0.0.0.0/0, ::/0
PersistentKeepalive = 25
CONF;

        $result = $formatter->makeAmneziaWgCompatible($source);

        $this->assertStringContainsString('Address = 10.78.78.3/32', $result);
        $this->assertStringNotContainsString('fd78:78:78::3/128', $result);
        $this->assertStringContainsString('DNS = 10.78.78.1', $result);
        $this->assertStringNotContainsString('fd78:78:78::1', $result);
        $this->assertStringContainsString('AllowedIPs = 0.0.0.0/0', $result);
        $this->assertStringNotContainsString('::/0', $result);
        $this->assertStringContainsString('PublicKey = test-public', $result);
    }
}
