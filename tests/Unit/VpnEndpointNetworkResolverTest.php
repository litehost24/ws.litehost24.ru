<?php

namespace Tests\Unit;

use App\Services\VpnEndpointNetworkResolver;
use PHPUnit\Framework\TestCase;

class VpnEndpointNetworkResolverTest extends TestCase
{
    public function test_normalize_ip_accepts_public_and_rejects_private(): void
    {
        $this->assertSame('91.78.145.202', VpnEndpointNetworkResolver::normalizeIp('91.78.145.202'));
        $this->assertNull(VpnEndpointNetworkResolver::normalizeIp('10.66.66.206'));
        $this->assertNull(VpnEndpointNetworkResolver::normalizeIp(''));
    }

    public function test_classifies_common_mobile_and_fixed_networks(): void
    {
        $this->assertSame('mobile', VpnEndpointNetworkResolver::classifyAsName('MTS, RU'));
        $this->assertSame('mobile', VpnEndpointNetworkResolver::classifyAsName('BEE-AS Russia, RU'));
        $this->assertSame('mobile', VpnEndpointNetworkResolver::classifyAsName('MF-KAVKAZ-AS, RU'));
        $this->assertSame('fixed', VpnEndpointNetworkResolver::classifyAsName('ROSTELECOM-AS PJSC Rostelecom. Technical Team, RU'));
        $this->assertSame('hosting', VpnEndpointNetworkResolver::classifyAsName('HOST-INDUSTRY, GB'));
    }

    public function test_operator_label_maps_major_networks(): void
    {
        $this->assertSame('MTS', VpnEndpointNetworkResolver::operatorLabel('MTS, RU'));
        $this->assertSame('T2/Tele2', VpnEndpointNetworkResolver::operatorLabel('T2-ROSTOV-AS T2 Russia Network, RU'));
        $this->assertSame('Beeline', VpnEndpointNetworkResolver::operatorLabel('BEE-AS Russia, RU'));
        $this->assertSame('MegaFon', VpnEndpointNetworkResolver::operatorLabel('MF-KAVKAZ-AS, RU'));
        $this->assertSame('Rostelecom', VpnEndpointNetworkResolver::operatorLabel('ROSTELECOM-AS PJSC Rostelecom. Technical Team, RU'));
        $this->assertSame('Host Industry', VpnEndpointNetworkResolver::operatorLabel('HOST-INDUSTRY, GB'));
    }
}
