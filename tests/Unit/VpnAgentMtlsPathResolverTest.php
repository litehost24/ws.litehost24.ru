<?php

namespace Tests\Unit;

use App\Services\VpnAgent\VpnAgentMtlsPathResolver;
use Tests\TestCase;

class VpnAgentMtlsPathResolverTest extends TestCase
{
    public function test_resolve_keeps_existing_path(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'mtls_');
        $this->assertNotFalse($tmpFile);

        try {
            $resolved = (new VpnAgentMtlsPathResolver())->resolve($tmpFile);

            $this->assertSame($tmpFile, $resolved);
        } finally {
            @unlink($tmpFile);
        }
    }

    public function test_resolve_maps_production_repo_path_to_local_base_path(): void
    {
        $resolved = (new VpnAgentMtlsPathResolver())->resolve(
            '/home/admin/web/ws.litehost24.ru/public_html/vpn-agent-mtls/ca.crt'
        );

        $this->assertSame(base_path('vpn-agent-mtls/ca.crt'), $resolved);
    }

    public function test_resolve_keeps_production_path_when_local_counterpart_is_missing(): void
    {
        $path = '/home/admin/web/ws.litehost24.ru/public_html/vpn-agent-mtls/missing.crt';

        $resolved = (new VpnAgentMtlsPathResolver())->resolve($path);

        $this->assertSame($path, $resolved);
    }
}
