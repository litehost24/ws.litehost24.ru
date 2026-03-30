<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\ServerMonitorEvent;
use App\Services\Telegram\TelegramHttpFactory;
use App\Services\VpnAgent\VpnAgentClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class MonitorServers extends Command
{
    protected $signature = 'servers:monitor {--once : Run one pass and print details}';

    protected $description = 'Monitor servers availability and write events only on status changes.';

    public function __construct(
        private readonly TelegramHttpFactory $telegramHttpFactory,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $failThreshold = max(1, (int) env('SERVER_MONITOR_FAIL_THRESHOLD', 3));
        $recoverThreshold = max(1, (int) env('SERVER_MONITOR_RECOVER_THRESHOLD', 1));
        $timeoutSec = max(1, (int) env('SERVER_MONITOR_TIMEOUT_SEC', 2));
        $adminEmail = env('MONITOR_ALERT_EMAIL', '4743383@gmail.com');

        $servers = Server::query()->orderBy('id')->get();
        $checked = 0;

        foreach ($servers as $server) {
            if ($server->usesNode1Api()) {
                $checked += $this->checkNode1Api($server, $timeoutSec, $failThreshold, $recoverThreshold, $adminEmail);
            } else {
                $checked += $this->checkNode($server->id, 'node1', $server->ip1, $server->url1, $server->username1, $server->password1, $timeoutSec, $failThreshold, $recoverThreshold, $adminEmail);
            }
            $checked += $this->checkNode($server->id, 'node2', $server->ip2, $server->url2, $server->username2, $server->password2, $timeoutSec, $failThreshold, $recoverThreshold, $adminEmail);
        }

        if ($this->option('once')) {
            $this->info("Checked nodes: {$checked}");
        }

        return self::SUCCESS;
    }

    private function checkNode1Api(
        Server $server,
        int $timeoutSec,
        int $failThreshold,
        int $recoverThreshold,
        ?string $adminEmail
    ): int {
        $serverId = (int) $server->id;
        $node = 'node1';

        // If node1_api_enabled is true but fields are not configured, keep state as N/A.
        $required = [
            'node1_api_url' => (string) ($server->node1_api_url ?? ''),
            'node1_api_ca_path' => (string) ($server->node1_api_ca_path ?? ''),
            'node1_api_cert_path' => (string) ($server->node1_api_cert_path ?? ''),
            'node1_api_key_path' => (string) ($server->node1_api_key_path ?? ''),
        ];
        foreach ($required as $field => $value) {
            if (trim($value) === '') {
                return $this->markNodeNotApplicable($serverId, $node, "node1_api_misconfigured:{$field}");
            }
        }

        [$host, $port] = $this->resolveHostPort($server->ip1, (string) $server->node1_api_url);
        $host = $host ?: 'n/a';

        $httpOk = false;
        $tcpOk = false;
        $isUp = false;
        $error = null;

        try {
            $client = new VpnAgentClient($server, $timeoutSec);
            $res = $client->health();
            $isUp = (bool) ($res['ok'] ?? false);
            $httpOk = $isUp;
            $tcpOk = $isUp;
        } catch (\Throwable $e) {
            $isUp = false;
            $httpOk = false;
            $tcpOk = false;
            $error = 'node1_api_health_failed';
            Log::warning('Server monitor node1 API health failed: ' . $e->getMessage(), [
                'server_id' => $serverId,
                'node' => $node,
            ]);
        }

        $last = ServerMonitorEvent::query()
            ->where('server_id', $serverId)
            ->where('node', $node)
            ->orderByDesc('changed_at')
            ->orderByDesc('id')
            ->first();

        $lastStatus = $last?->status;
        $cachePrefix = "server_monitor:{$serverId}:{$node}";

        if ($isUp) {
            $okStreak = $this->bumpCounter("{$cachePrefix}:ok");
            Cache::forget("{$cachePrefix}:fail");

            if ($lastStatus === null) {
                $this->writeEvent($serverId, $node, 'up', $host, $port, $httpOk, $tcpOk, null);
                return 1;
            }

            // Consider previous N/A state as a recoverable state as well (we used to write N/A when node1 API was enabled).
            if ($lastStatus !== 'up' && $okStreak >= $recoverThreshold) {
                $this->writeEvent($serverId, $node, 'up', $host, $port, $httpOk, $tcpOk, null);
                if ($lastStatus === 'down') {
                    $this->notify($adminEmail, $serverId, $node, 'UP', $host, $port, null);
                    $this->notifyTelegram($serverId, $node, 'UP', $host, $port, null);
                }
                Cache::forget("{$cachePrefix}:ok");
            }

            return 1;
        }

        $failStreak = $this->bumpCounter("{$cachePrefix}:fail");
        Cache::forget("{$cachePrefix}:ok");

        if ($lastStatus === null) {
            if ($failStreak >= $failThreshold) {
                $this->writeEvent($serverId, $node, 'down', $host, $port, $httpOk, $tcpOk, $error);
                $this->notify($adminEmail, $serverId, $node, 'DOWN', $host, $port, $error);
                $this->notifyTelegram($serverId, $node, 'DOWN', $host, $port, $error);
                Cache::forget("{$cachePrefix}:fail");
            }
            return 1;
        }

        if ($lastStatus !== 'down' && $failStreak >= $failThreshold) {
            $this->writeEvent($serverId, $node, 'down', $host, $port, $httpOk, $tcpOk, $error);
            $this->notify($adminEmail, $serverId, $node, 'DOWN', $host, $port, $error);
            Cache::forget("{$cachePrefix}:fail");
        }

        return 1;
    }

    private function checkNode(
        int $serverId,
        string $node,
        ?string $ip,
        ?string $url,
        ?string $username,
        ?string $password,
        int $timeoutSec,
        int $failThreshold,
        int $recoverThreshold,
        ?string $adminEmail
    ): int {
        [$host, $port] = $this->resolveHostPort($ip, $url);
        if (empty($host)) {
            return 0;
        }

        [$tcpOk, $tcpError] = $this->checkTcp($host, $port, $timeoutSec);
        [$httpOk, $httpError] = $this->checkHttp($url, $timeoutSec);
        [$authAttempted, $authOk, $authError] = $this->checkAuthSession($url, $host, $port, $username, $password, $timeoutSec);

        if ($authAttempted) {
            $isUp = $authOk;
            $error = $isUp ? null : ($authError ?: 'auth_failed');
        } else {
            $isUp = $tcpOk || $httpOk;
            $error = $isUp ? null : trim(($tcpError ?: 'tcp_fail') . '; ' . ($httpError ?: 'http_fail') . '; ' . ($authError ?: ''), '; ');
        }

        $last = ServerMonitorEvent::query()
            ->where('server_id', $serverId)
            ->where('node', $node)
            ->orderByDesc('changed_at')
            ->orderByDesc('id')
            ->first();

        $lastStatus = $last?->status;
        $cachePrefix = "server_monitor:{$serverId}:{$node}";

        if ($isUp) {
            $okStreak = $this->bumpCounter("{$cachePrefix}:ok");
            Cache::forget("{$cachePrefix}:fail");

            if ($lastStatus === null) {
                $this->writeEvent($serverId, $node, 'up', $host, $port, $httpOk, $tcpOk, null);
                return 1;
            }

            if ($lastStatus === 'down' && $okStreak >= $recoverThreshold) {
                $this->writeEvent($serverId, $node, 'up', $host, $port, $httpOk, $tcpOk, null);
                $this->notify($adminEmail, $serverId, $node, 'UP', $host, $port, null);
                $this->notifyTelegram($serverId, $node, 'UP', $host, $port, null);
                Cache::forget("{$cachePrefix}:ok");
            }

            return 1;
        }

        $failStreak = $this->bumpCounter("{$cachePrefix}:fail");
        Cache::forget("{$cachePrefix}:ok");

        if ($lastStatus === null) {
            if ($failStreak >= $failThreshold) {
                $this->writeEvent($serverId, $node, 'down', $host, $port, $httpOk, $tcpOk, $error);
                $this->notify($adminEmail, $serverId, $node, 'DOWN', $host, $port, $error);
                $this->notifyTelegram($serverId, $node, 'DOWN', $host, $port, $error);
                Cache::forget("{$cachePrefix}:fail");
            }
            return 1;
        }

        if ($lastStatus !== 'down' && $failStreak >= $failThreshold) {
            $this->writeEvent($serverId, $node, 'down', $host, $port, $httpOk, $tcpOk, $error);
            $this->notify($adminEmail, $serverId, $node, 'DOWN', $host, $port, $error);
            Cache::forget("{$cachePrefix}:fail");
        }

        return 1;
    }

    private function writeEvent(
        int $serverId,
        string $node,
        string $status,
        string $host,
        ?int $port,
        bool $pingOk,
        bool $tcpOk,
        ?string $error
    ): void {
        ServerMonitorEvent::create([
            'server_id' => $serverId,
            'node' => $node,
            'status' => $status,
            'changed_at' => Carbon::now(),
            'host' => $host,
            'port' => $port,
            'ping_ok' => $pingOk,
            'tcp_ok' => $tcpOk,
            'error_message' => $error,
        ]);
    }

    private function markNodeNotApplicable(int $serverId, string $node, string $error = 'node1_api_monitor_disabled'): int
    {
        $last = ServerMonitorEvent::query()
            ->where('server_id', $serverId)
            ->where('node', $node)
            ->orderByDesc('changed_at')
            ->orderByDesc('id')
            ->first();

        if ($last?->status === 'n/a') {
            return 1;
        }

        $this->writeEvent($serverId, $node, 'n/a', 'n/a', null, false, false, $error);
        return 1;
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
        } catch (\Throwable $e) {
            return [false, 'http_error'];
        }
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

        $error = trim($errstr);
        if ($error === '') {
            $error = "tcp_error_{$errno}";
        }

        return [false, $error];
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

    private function notify(?string $email, int $serverId, string $node, string $status, string $host, ?int $port, ?string $error): void
    {
        if (empty($email)) {
            return;
        }

        $subject = "[Server monitor] server #{$serverId} {$node} {$status}";
        $lines = [
            "Server ID: {$serverId}",
            "Node: {$node}",
            "Status: {$status}",
            "Host: {$host}",
            "Port: " . ($port ?: 'n/a'),
            "Time: " . Carbon::now()->toDateTimeString(),
        ];

        if (!empty($error)) {
            $lines[] = "Error: {$error}";
        }

        $body = implode(PHP_EOL, $lines);

        try {
            Mail::raw($body, function ($message) use ($email, $subject) {
                $message->to($email)->subject($subject);
            });
        } catch (\Throwable $e) {
            Log::error('Server monitor mail failed: ' . $e->getMessage());
        }
    }



    private function notifyTelegram(int $serverId, string $node, string $status, string $host, ?int $port, ?string $error): void
    {
        $enabled = (bool) config('support.telegram.monitor_enabled', true);
        $token = (string) config('support.telegram.bot_token');
        $chatId = (string) config('support.telegram.monitor_chat_id');

        if (!$enabled || $token === '' || $chatId === '') {
            return;
        }

        $portText = $port ? (":" . $port) : '';
        $errorText = $error ? ("
Error: {$error}") : '';

        $text = "[Server monitor] server #{$serverId} {$node} {$status}
Host: {$host}{$portText}{$errorText}
Time: " . Carbon::now()->toDateTimeString();

        try {
            $this->telegramHttpFactory
                ->botRequest(timeout: 4, connectTimeout: 4)
                ->asForm()
                ->post('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => $text,
                    'disable_web_page_preview' => true,
                ]);
        } catch (\Throwable $e) {
            Log::error('Server monitor telegram failed: ' . $e->getMessage(), [
                'server_id' => $serverId,
                'node' => $node,
                'status' => $status,
            ]);
        }
    }
    private function bumpCounter(string $key): int
    {
        if (!Cache::has($key)) {
            Cache::put($key, 0, now()->addDay());
        }

        return (int) Cache::increment($key);
    }
}
