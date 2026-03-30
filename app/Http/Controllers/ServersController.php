<?php

namespace App\Http\Controllers;

use App\Models\ProjectSetting;
use App\Models\Server;
use App\Models\ServerAwgSummary;
use App\Models\ServerMonitorEvent;
use App\Models\ServerNodeMetric;
use App\Services\VpnAgent\VpnAgentClient;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ServersController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $servers = Server::query()->orderBy('id')->get();

        $latestEvents = ServerMonitorEvent::query()
            ->orderByDesc('id')
            ->get()
            ->groupBy(function ($item) {
                return $item->server_id . ':' . $item->node;
            })
            ->map(function ($group) {
                return $group->first();
            });

        $monitorState = [];
        foreach ($servers as $server) {
            foreach (['node1', 'node2'] as $node) {
                $key = $server->id . ':' . $node;
                $event = $latestEvents->get($key);

                $monitorState[$server->id][$node] = [
                    'status' => $event?->status ?? 'n/a',
                    'changed_at' => $event?->changed_at
                        ? Carbon::parse($event->changed_at)->format('Y-m-d H:i')
                        : null,
                ];
            }
        }

        $latestMetrics = ServerNodeMetric::query()
            ->orderByDesc('collected_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy(function ($item) {
                return $item->server_id . ':' . $item->node;
            })
            ->map(function ($group) {
                return $group->first();
            });

        $serverMetrics = [];
        foreach ($servers as $server) {
            $metric = $latestMetrics->get($server->id . ':node1');
            $serverMetrics[$server->id]['node1'] = $metric;
        }

        $serverAwgSummaries = [];
        if (Schema::hasTable('server_awg_summaries')) {
            $latestAwgSummaries = ServerAwgSummary::query()
                ->orderByDesc('collected_at')
                ->orderByDesc('id')
                ->get()
                ->keyBy('server_id');

            foreach ($servers as $server) {
                $serverAwgSummaries[$server->id] = $latestAwgSummaries->get($server->id);
            }
        }

        $bundleServerOptions = [
            Server::VPN_ACCESS_WHITE_IP => $servers
                ->filter(fn (Server $server) => $server->getVpnAccessMode() === Server::VPN_ACCESS_WHITE_IP)
                ->values(),
            Server::VPN_ACCESS_REGULAR => $servers
                ->filter(fn (Server $server) => $server->getVpnAccessMode() === Server::VPN_ACCESS_REGULAR)
                ->values(),
        ];

        $configuredBundleServerIds = [
            Server::VPN_ACCESS_WHITE_IP => ProjectSetting::getInt(Server::CURRENT_WHITE_IP_SERVER_SETTING, 0),
            Server::VPN_ACCESS_REGULAR => ProjectSetting::getInt(Server::CURRENT_REGULAR_SERVER_SETTING, 0),
        ];

        $effectiveBundleServers = [
            Server::VPN_ACCESS_WHITE_IP => Server::resolvePurchaseServer(Server::VPN_ACCESS_WHITE_IP),
            Server::VPN_ACCESS_REGULAR => Server::resolvePurchaseServer(Server::VPN_ACCESS_REGULAR),
        ];

        return view('servers.index', compact(
            'servers',
            'monitorState',
            'serverMetrics',
            'serverAwgSummaries',
            'bundleServerOptions',
            'configuredBundleServerIds',
            'effectiveBundleServers',
        ));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        return view('servers.create');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $request->validate([
            'ip1' => 'nullable|ip',
            'username1' => 'nullable|string|max:255',
            'password1' => 'nullable|string|max:255',
            'webwasepath1' => 'nullable|string|max:255',
            'url1' => 'nullable|url',
            'node1_api_url' => 'nullable|url',
            'node1_api_ca_path' => 'nullable|string|max:255',
            'node1_api_cert_path' => 'nullable|string|max:255',
            'node1_api_key_path' => 'nullable|string|max:255',
            'node1_api_enabled' => 'nullable|boolean',
            'vpn_access_mode' => 'nullable|string|in:white_ip,regular',
            'ip2' => 'nullable|ip',
            'username2' => 'nullable|string|max:255',
            'password2' => 'nullable|string|max:255',
            'webwasepath2' => 'nullable|string|max:255',
            'url2' => 'nullable|url',
            'vless_inbound_id' => 'nullable|integer|min:1',
        ]);
        $payload = $request->all();
        $payload['node1_api_enabled'] = $request->boolean('node1_api_enabled');
        $payload['vpn_access_mode'] = Server::normalizeVpnAccessMode($request->input('vpn_access_mode'));
        Server::create($payload);

        return redirect()->route('servers.index')
                         ->with('success', 'Server created successfully.');
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Server  $server
     * @return \Illuminate\Http\Response
     */
    public function show(Server $server)
    {
        return view('servers.show', compact('server'));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Server  $server
     * @return \Illuminate\Http\Response
     */
    public function edit(Server $server)
    {
        return view('servers.edit', compact('server'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Server  $server
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Server $server)
    {
        $request->validate([
            'ip1' => 'nullable|ip',
            'username1' => 'nullable|string|max:255',
            'password1' => 'nullable|string|max:255',
            'webwasepath1' => 'nullable|string|max:255',
            'url1' => 'nullable|url',
            'node1_api_url' => 'nullable|url',
            'node1_api_ca_path' => 'nullable|string|max:255',
            'node1_api_cert_path' => 'nullable|string|max:255',
            'node1_api_key_path' => 'nullable|string|max:255',
            'node1_api_enabled' => 'nullable|boolean',
            'vpn_access_mode' => 'nullable|string|in:white_ip,regular',
            'ip2' => 'nullable|ip',
            'username2' => 'nullable|string|max:255',
            'password2' => 'nullable|string|max:255',
            'webwasepath2' => 'nullable|string|max:255',
            'url2' => 'nullable|url',
            'vless_inbound_id' => 'nullable|integer|min:1',
        ]);
        $payload = $request->all();
        $payload['node1_api_enabled'] = $request->boolean('node1_api_enabled');
        $payload['vpn_access_mode'] = Server::normalizeVpnAccessMode($request->input('vpn_access_mode'));
        $server->update($payload);

        return redirect()->route('servers.index')
                         ->with('success', 'Server updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Server  $server
     * @return \Illuminate\Http\Response
     */
    public function destroy(Server $server)
    {
        $server->delete();

        return redirect()->route('servers.index')
                         ->with('success', 'Server deleted successfully.');
    }

    public function updateCurrentBundles(Request $request)
    {
        $data = $request->validate([
            'white_ip_server_id' => [
                'nullable',
                'integer',
                'exists:servers,id',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (empty($value)) {
                        return;
                    }

                    $server = Server::query()->find((int) $value);
                    if (!$server || $server->getVpnAccessMode() !== Server::VPN_ACCESS_WHITE_IP) {
                        $fail('Для режима white_ip нужно выбрать сервер с таким же VPN bundle.');
                    }
                },
            ],
            'regular_server_id' => [
                'nullable',
                'integer',
                'exists:servers,id',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (empty($value)) {
                        return;
                    }

                    $server = Server::query()->find((int) $value);
                    if (!$server || $server->getVpnAccessMode() !== Server::VPN_ACCESS_REGULAR) {
                        $fail('Для режима regular нужно выбрать сервер с таким же VPN bundle.');
                    }
                },
            ],
        ]);

        ProjectSetting::setValue(
            Server::CURRENT_WHITE_IP_SERVER_SETTING,
            (string) ((int) ($data['white_ip_server_id'] ?? 0)),
            Auth::id() ? (int) Auth::id() : null
        );

        ProjectSetting::setValue(
            Server::CURRENT_REGULAR_SERVER_SETTING,
            (string) ((int) ($data['regular_server_id'] ?? 0)),
            Auth::id() ? (int) Auth::id() : null
        );

        return redirect()->route('servers.index')
            ->with('success', 'Текущие VPN bundle обновлены.');
    }

    public function monitorCheck(Server $server): JsonResponse
    {
        $nodes = [
            'node1' => [$server->ip1, $server->url1, $server->username1, $server->password1],
            'node2' => [$server->ip2, $server->url2, $server->username2, $server->password2],
        ];

        $result = [];
        foreach ($nodes as $node => [$ip, $url, $username, $password]) {
            if ($node === 'node1' && $server->usesNode1Api()) {
                $configured = !empty($server->node1_api_url)
                    && !empty($server->node1_api_ca_path)
                    && !empty($server->node1_api_cert_path)
                    && !empty($server->node1_api_key_path);

                if (!$configured) {
                    $result[$node] = [
                        'status' => 'n/a',
                        'changed_at' => null,
                        'tcp_ok' => false,
                        'http_ok' => false,
                        'auth_checked' => false,
                        'auth_ok' => null,
                        'error_message' => 'node1_api_misconfigured',
                    ];
                    continue;
                }

                // mTLS API health check
                [$host, $port] = $this->resolveHostPort($server->ip1, (string) $server->node1_api_url);
                if (empty($host)) {
                    $result[$node] = [
                        'status' => 'n/a',
                        'changed_at' => null,
                        'tcp_ok' => false,
                        'http_ok' => false,
                        'auth_checked' => false,
                        'auth_ok' => null,
                        'error_message' => 'node1_api_host_missing',
                    ];
                    continue;
                }

                $httpOk = false;
                $error = null;
                try {
                    $client = new VpnAgentClient($server, 6);
                    $res = $client->health();
                    $httpOk = (bool) ($res['ok'] ?? false);
                } catch (\Throwable $e) {
                    $httpOk = false;
                    $error = 'node1_api_health_failed';
                    Log::warning('servers.monitor-check node1 API health failed: ' . $e->getMessage(), [
                        'server_id' => $server->id,
                    ]);
                }

                $status = $httpOk ? 'up' : 'down';

                $last = ServerMonitorEvent::query()
                    ->where('server_id', $server->id)
                    ->where('node', $node)
                    ->orderByDesc('id')
                    ->first();

                if (!$last || $last->status !== $status) {
                    $event = ServerMonitorEvent::create([
                        'server_id' => $server->id,
                        'node' => $node,
                        'status' => $status,
                        'changed_at' => Carbon::now(),
                        'host' => $host,
                        'port' => $port,
                        'ping_ok' => $httpOk,
                        'tcp_ok' => $httpOk,
                        'error_message' => $status === 'up' ? null : ($error ?: 'node1_api_check_failed'),
                    ]);

                    $changedAt = optional($event->changed_at)->format('Y-m-d H:i');
                } else {
                    $changedAt = optional($last->changed_at)->format('Y-m-d H:i');
                }

                $result[$node] = [
                    'status' => $status,
                    'changed_at' => $changedAt,
                    'tcp_ok' => $httpOk,
                    'http_ok' => $httpOk,
                    'auth_checked' => true,
                    'auth_ok' => $httpOk,
                ];

                continue;
            }

            [$host, $port] = $this->resolveHostPort($ip, $url);

            if (empty($host)) {
                $result[$node] = [
                    'status' => 'n/a',
                    'changed_at' => null,
                    'tcp_ok' => false,
                    'http_ok' => false,
                ];
                continue;
            }

            [$tcpOk] = $this->checkTcp($host, $port, 2);
            [$httpOk] = $this->checkHttp($url, 2);
            [$authAttempted, $authOk] = $this->checkAuthSession($url, $host, $port, $username, $password, 2);
            $status = $authAttempted ? ($authOk ? 'up' : 'down') : (($tcpOk || $httpOk) ? 'up' : 'down');

            $last = ServerMonitorEvent::query()
                ->where('server_id', $server->id)
                ->where('node', $node)
                ->orderByDesc('id')
                ->first();

            if (!$last || $last->status !== $status) {
                $event = ServerMonitorEvent::create([
                    'server_id' => $server->id,
                    'node' => $node,
                    'status' => $status,
                    'changed_at' => Carbon::now(),
                    'host' => $host,
                    'port' => $port,
                    'ping_ok' => $httpOk,
                    'tcp_ok' => $tcpOk,
                    'error_message' => $status === 'up' ? null : 'manual_check_failed',
                ]);

                $changedAt = optional($event->changed_at)->format('Y-m-d H:i');
            } else {
                $changedAt = optional($last->changed_at)->format('Y-m-d H:i');
            }

            $result[$node] = [
                'status' => $status,
                'changed_at' => $changedAt,
                'tcp_ok' => $tcpOk,
                'http_ok' => $httpOk,
                'auth_checked' => $authAttempted,
                'auth_ok' => $authAttempted ? $authOk : null,
            ];
        }

        return response()->json([
            'ok' => true,
            'server_id' => $server->id,
            'checked_at' => Carbon::now()->format('Y-m-d H:i'),
            'nodes' => $result,
        ]);
    }

    private function resolveHostPort(?string $ip, ?string $url): array
    {
        $host = null;
        $port = null;

        $ip = trim((string) $ip);
        if ($ip !== '') {
            $host = $ip;
        }

        $url = trim((string) $url);
        if ($url !== '') {
            $parsedHost = parse_url($url, PHP_URL_HOST);
            $parsedPort = parse_url($url, PHP_URL_PORT);
            $scheme = parse_url($url, PHP_URL_SCHEME);

            if (!$host && is_string($parsedHost) && $parsedHost !== '') {
                $host = $parsedHost;
            }

            if (is_int($parsedPort) || ctype_digit((string) $parsedPort)) {
                $port = (int) $parsedPort;
            } elseif (is_string($scheme)) {
                $scheme = strtolower($scheme);
                if ($scheme === 'https') {
                    $port = 443;
                } elseif ($scheme === 'http') {
                    $port = 80;
                }
            }
        }

        return [$host, $port];
    }

    private function checkTcp(string $host, ?int $port, int $timeoutSec): array
    {
        if (!$port) {
            return [false, 'port_unknown'];
        }

        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($host, $port, $errno, $errstr, $timeoutSec);
        if (is_resource($socket)) {
            fclose($socket);
            return [true, null];
        }

        return [false, trim($errstr) ?: "tcp_error_{$errno}"];
    }

    private function checkHttp(?string $url, int $timeoutSec): array
    {
        $url = trim((string) $url);
        if ($url === '') {
            return [false, 'url_missing'];
        }

        if (!function_exists('curl_init')) {
            return [false, 'curl_missing'];
        }

        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_NOBODY => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_CONNECTTIMEOUT => $timeoutSec,
                CURLOPT_TIMEOUT => $timeoutSec,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
            ]);

            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($code >= 200 && $code < 500) {
                return [true, null];
            }

            if ($error !== '') {
                return [false, 'curl_' . $error];
            }

            return [false, 'http_' . $code];
        } catch (\Throwable) {
            return [false, 'http_error'];
        }
    }

    private function checkAuthSession(
        ?string $url,
        string $host,
        ?int $port,
        ?string $username,
        ?string $password,
        int $timeoutSec
    ): array {
        $username = trim((string) $username);
        $password = (string) $password;
        if ($username === '' || $password === '') {
            return [false, false, 'auth_credentials_missing'];
        }

        $scheme = strtolower((string) parse_url((string) $url, PHP_URL_SCHEME));
        if ($scheme === 'ftp') {
            if (!function_exists('ftp_connect')) {
                return [false, false, 'ftp_extension_missing'];
            }

            $ftp = @ftp_connect($host, $port ?: 21, $timeoutSec);
            if (!$ftp) {
                return [true, false, 'ftp_connect_failed'];
            }

            $ok = @ftp_login($ftp, $username, $password);
            @ftp_close($ftp);
            return [true, (bool) $ok, $ok ? null : 'ftp_auth_failed'];
        }

        $shouldTrySsh = ($scheme === 'sftp' || $scheme === 'ssh' || $port === 22);
        if (!$shouldTrySsh) {
            return [false, false, 'auth_protocol_not_supported'];
        }

        if (!function_exists('ssh2_connect')) {
            return [false, false, 'ssh2_extension_missing'];
        }

        $connection = @ssh2_connect($host, $port ?: 22);
        if (!$connection) {
            return [true, false, 'ssh2_connect_failed'];
        }

        $ok = @ssh2_auth_password($connection, $username, $password);
        if (!$ok) {
            return [true, false, 'ssh2_auth_failed'];
        }

        return [true, true, null];
    }
}
