<?php

namespace App\Services\Telegram;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class TelegramHttpFactory
{
    public function botRequest(int $timeout = 4, int $connectTimeout = 4): PendingRequest
    {
        $token = (string) config('support.telegram.bot_token');
        $baseUrl = rtrim((string) config('support.telegram.api_base_url', 'https://api.telegram.org'), '/');

        $request = Http::connectTimeout($connectTimeout)
            ->timeout($timeout)
            ->baseUrl("{$baseUrl}/bot{$token}/");

        $resolveEntry = $this->resolveEntry($baseUrl);
        if ($resolveEntry !== null) {
            $request = $request->withOptions([
                'curl' => [
                    CURLOPT_RESOLVE => [$resolveEntry],
                ],
            ]);
        }

        return $request;
    }

    private function resolveEntry(string $baseUrl): ?string
    {
        $ip = trim((string) config('support.telegram.resolve_ip', ''));
        if ($ip === '') {
            return null;
        }

        $host = (string) parse_url($baseUrl, PHP_URL_HOST);
        if ($host === '') {
            return null;
        }

        $scheme = strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME));
        $port = (int) (parse_url($baseUrl, PHP_URL_PORT) ?: ($scheme === 'http' ? 80 : 443));

        return "{$host}:{$port}:{$ip}";
    }
}
