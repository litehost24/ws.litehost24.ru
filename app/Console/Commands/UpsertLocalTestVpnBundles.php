<?php

namespace App\Console\Commands;

use App\Models\ProjectSetting;
use App\Models\Server;
use Illuminate\Console\Command;
use Throwable;

class UpsertLocalTestVpnBundles extends Command
{
    protected $signature = 'servers:upsert-local-test-bundles
        {--host=85.143.220.175 : Test bundle host for both temporary modes}
        {--api-url=https://85.143.220.175 : Node1 API URL}
        {--panel-url=https://85.143.220.175:61943/tZNtiqQTcTmCcT1iwe : 3x-ui panel base URL}
        {--panel-user= : 3x-ui username; can also be provided via LOCAL_TEST_XUI_USER}
        {--panel-pass= : 3x-ui password; can also be provided via LOCAL_TEST_XUI_PASS}
        {--vless-inbound-id= : Optional explicit VLESS inbound ID}';

    protected $description = 'Upsert temporary local VPN bundle server records for white_ip and regular modes using the test node.';

    public function handle(): int
    {
        $host = trim((string) $this->option('host'));
        $apiUrl = trim((string) $this->option('api-url'));
        $panelUrl = trim((string) $this->option('panel-url'));
        $panelUser = trim((string) ($this->option('panel-user') ?: env('LOCAL_TEST_XUI_USER', '')));
        $panelPass = trim((string) ($this->option('panel-pass') ?: env('LOCAL_TEST_XUI_PASS', '')));
        $vlessInboundId = $this->option('vless-inbound-id');

        if ($host === '' || $apiUrl === '' || $panelUrl === '') {
            $this->error('Host, api-url and panel-url are required.');
            return self::FAILURE;
        }

        if ($panelUser === '' || $panelPass === '') {
            $this->error('3x-ui credentials are required. Pass --panel-user/--panel-pass or set LOCAL_TEST_XUI_USER / LOCAL_TEST_XUI_PASS.');
            return self::FAILURE;
        }

        $mtlsDir = base_path('vpn-agent-mtls/node-' . $host . '-mtls');
        $caPath = $mtlsDir . '/ca.crt';
        $certPath = $mtlsDir . '/laravel-client.crt';
        $keyPath = $mtlsDir . '/laravel-client.key';

        foreach ([$caPath, $certPath, $keyPath] as $path) {
            if (!is_file($path)) {
                $this->error('Missing mTLS file: ' . $path);
                return self::FAILURE;
            }
        }

        $payload = [
            'ip1' => $host,
            'username1' => null,
            'password1' => null,
            'webwasepath1' => null,
            'url1' => null,
            'node1_api_url' => $apiUrl,
            'node1_api_ca_path' => $caPath,
            'node1_api_cert_path' => $certPath,
            'node1_api_key_path' => $keyPath,
            'node1_api_enabled' => true,
            'ip2' => $host,
            'username2' => $panelUser,
            'password2' => $panelPass,
            'webwasepath2' => null,
            'url2' => $panelUrl,
            'vless_inbound_id' => $vlessInboundId !== null && $vlessInboundId !== '' ? (int) $vlessInboundId : null,
        ];

        $rows = [
            Server::VPN_ACCESS_WHITE_IP => array_merge($payload, [
                'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
            ]),
            Server::VPN_ACCESS_REGULAR => array_merge($payload, [
                'vpn_access_mode' => Server::VPN_ACCESS_REGULAR,
            ]),
        ];

        try {
            $currentIds = [];

            foreach ($rows as $mode => $row) {
                $server = Server::query()->updateOrCreate(
                    [
                        'vpn_access_mode' => $mode,
                        'node1_api_url' => $apiUrl,
                    ],
                    $row
                );

                $this->info(sprintf(
                    '[%s] server_id=%d host=%s panel=%s',
                    $mode,
                    (int) $server->id,
                    $host,
                    $panelUrl
                ));

                $currentIds[$mode] = (int) $server->id;
            }

            foreach ($currentIds as $mode => $serverId) {
                ProjectSetting::setValue(
                    Server::currentBundleServerSettingKey($mode),
                    (string) $serverId
                );

                $this->line(sprintf(
                    'set %s=%d',
                    Server::currentBundleServerSettingKey($mode),
                    $serverId
                ));
            }
        } catch (Throwable $e) {
            $this->error('Local database is not reachable from the current shell.');
            $this->line('Expected DB host from .env: ' . (string) config('database.connections.mysql.host'));
            $this->line('Original error: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->warn('Temporary local setup: both bundle modes point to the same test node until dedicated nodes are ready.');

        return self::SUCCESS;
    }
}
