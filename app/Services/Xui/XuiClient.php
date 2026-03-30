<?php

namespace App\Services\Xui;

use Exception;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class XuiClient
{
    private string $baseUrl;
    private string $username;
    private string $password;
    private int $timeoutSec;
    private CookieJar $cookieJar;
    private bool $loggedIn = false;

    public function __construct(string $baseUrl, string $username, string $password, int $timeoutSec = 20)
    {
        $baseUrl = rtrim($baseUrl, '/');
        if ($baseUrl === '') {
            throw new Exception('XUI API URL is empty');
        }

        $this->baseUrl = $baseUrl;
        $this->username = $username;
        $this->password = $password;
        $this->timeoutSec = $timeoutSec;
        $this->cookieJar = new CookieJar();
    }

    /**
     * @return array<mixed>
     * @throws Exception
     */
    public function inboundsList(): array
    {
        $this->ensureLoggedIn();

        return $this->getJson('/panel/api/inbounds/list');
    }

    /**
     * @throws Exception
     */
    private function ensureLoggedIn(): void
    {
        if ($this->loggedIn) {
            return;
        }

        $response = $this->http()->post('/login', [
            'username' => $this->username,
            'password' => $this->password,
        ]);

        try {
            $response->throw();
        } catch (RequestException|ConnectionException $e) {
            throw new Exception('XUI login request failed: ' . $e->getMessage(), 0, $e);
        }

        $json = $response->json();
        if (is_array($json) && array_key_exists('success', $json) && !$json['success']) {
            $msg = (string) ($json['msg'] ?? 'unknown_error');
            throw new Exception('XUI login failed: ' . $msg);
        }

        $this->storeSessionCookieFromResponse($response);
        if (!$this->hasSessionCookie()) {
            throw new Exception('XUI login failed: session cookie not found');
        }

        $this->loggedIn = true;
    }

    /**
     * @return array<mixed>
     * @throws Exception
     */
    private function getJson(string $path): array
    {
        $response = $this->http()->get($path);

        try {
            $response->throw();
        } catch (RequestException|ConnectionException $e) {
            throw new Exception('XUI API GET request failed: ' . $e->getMessage(), 0, $e);
        }

        $json = $response->json();
        if (!is_array($json)) {
            throw new Exception('XUI API returned non-JSON response for ' . $path);
        }

        return $json;
    }

    private function hasSessionCookie(): bool
    {
        foreach ($this->cookieJar->toArray() as $cookie) {
            if (($cookie['Name'] ?? '') === '3x-ui') {
                return true;
            }
        }

        return false;
    }

    private function storeSessionCookieFromResponse($response): void
    {
        $setCookie = $response->header('Set-Cookie');
        if (!$setCookie) {
            return;
        }

        if (!preg_match('/3x-ui=([^;]+)/', (string) $setCookie, $matches)) {
            return;
        }

        $host = parse_url($this->baseUrl, PHP_URL_HOST) ?: '';
        $cookie = new SetCookie([
            'Name' => '3x-ui',
            'Value' => $matches[1],
            'Domain' => $host,
            'Path' => '/',
        ]);
        $this->cookieJar->setCookie($cookie);
    }

    private function http()
    {
        return Http::baseUrl($this->baseUrl)
            ->timeout($this->timeoutSec)
            ->connectTimeout(8)
            ->acceptJson()
            ->withOptions([
                'verify' => false,
                'cookies' => $this->cookieJar,
            ]);
    }
}
