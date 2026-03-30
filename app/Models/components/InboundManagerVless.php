<?php

namespace App\Models\components;

use Exception;

/**
 * Р С™Р В»Р В°РЎРѓРЎРѓ Р Т‘Р В»РЎРЏ РЎС“Р С—РЎР‚Р В°Р Р†Р В»Р ВµР Р…Р С‘РЎРЏ inbounds 3x-ui
 * Р СџР С•Р В·Р Р†Р С•Р В»РЎРЏР ВµРЎвЂљ РЎРѓР С•Р В·Р Т‘Р В°Р Р†Р В°РЎвЂљРЎРЉ, Р Р†Р С”Р В»РЎР‹РЎвЂЎР В°РЎвЂљРЎРЉ Р С‘Р В»Р С‘ Р Р†РЎвЂ№Р С”Р В»РЎР‹РЎвЂЎР В°РЎвЂљРЎРЉ inbounds
 */
class InboundManagerVless
{
    private $apiUrl;
    private $sessionId;

    public function __construct($apiUrl) {
        // Р СџРЎР‚Р С•Р Р†Р ВµРЎР‚РЎРЏР ВµР С, Р Р…Р В°РЎвЂЎР С‘Р Р…Р В°Р ВµРЎвЂљРЎРѓРЎРЏ Р В»Р С‘ URL РЎРѓ Р С—РЎР‚Р С•РЎвЂљР С•Р С”Р С•Р В»Р В°
        if (!preg_match('/^https?:\/\//', $apiUrl)) {
            // Р вЂўРЎРѓР В»Р С‘ Р Р…Р ВµРЎвЂљ Р С—РЎР‚Р С•РЎвЂљР С•Р С”Р С•Р В»Р В°, Р Т‘Р С•Р В±Р В°Р Р†Р В»РЎРЏР ВµР С https
            $apiUrl = 'https://' . $apiUrl;
        }

        if (!filter_var($apiUrl, FILTER_VALIDATE_URL)) {
            throw new Exception("Р СњР ВµР Р†Р В°Р В»Р С‘Р Т‘Р Р…РЎвЂ№Р в„– URL API: $apiUrl");
        }
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->sessionId = null;
    }

    private function makeRequest($endpoint, $method = 'GET', $data = null) {
        $url =  $endpoint;
        $ch = curl_init();

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json, text/plain, */*',
                'X-Requested-with: XMLHttpRequest',
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                "Cookie: 3x-ui=" . $this->sessionId
            ],
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1  // Р Р‡Р Р†Р Р…Р С• РЎС“Р С”Р В°Р В·РЎвЂ№Р Р†Р В°Р ВµР С HTTP/1.1
        ];

        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            if ($data) {
                $options[CURLOPT_POSTFIELDS] = $data;
                // Р вЂ™РЎРѓР ВµР С–Р Т‘Р В° Р С‘РЎРѓР С—Р С•Р В»РЎРЉР В·РЎС“Р ВµР С application/json Р Т‘Р В»РЎРЏ API 3x-ui
                $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json; charset=utf-8';
            } else {
                $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json; charset=utf-8';
            }
        } elseif ($method === 'PUT') {
            $options[CURLOPT_CUSTOMREQUEST] = 'PUT';
            if ($data) {
                $options[CURLOPT_POSTFIELDS] = $data;
                // Р вЂ™РЎРѓР ВµР С–Р Т‘Р В° Р С‘РЎРѓР С—Р С•Р В»РЎРЉР В·РЎС“Р ВµР С application/json Р Т‘Р В»РЎРЏ API 3x-ui
                $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json; charset=utf-8';
            } else {
                $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json; charset=utf-8';
            }
        } else {
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json; charset=utf-8';
        }

        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) throw new Exception('cURL error: ' . $error);
        if ($httpCode >= 400) throw new Exception('HTTP error: ' . $httpCode . ' - Response: ' . $response);

        // Р вЂ™Р С•Р В·Р Р†РЎР‚Р В°РЎвЂ°Р В°Р ВµР С Р С•РЎвЂљР Р†Р ВµРЎвЂљ
        if (empty($response)) {
            return ['success' => true, 'msg' => 'Empty response (likely successful)'];
        }

        $decodedResponse = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('JSON decode error: ' . json_last_error_msg() . ' - Response: ' . $response);
        }

        return $decodedResponse;
    }

    /**
     * Р С’Р Р†РЎвЂљР С•РЎР‚Р С‘Р В·Р В°РЎвЂ Р С‘РЎРЏ Р Р† РЎРѓР С‘РЎРѓРЎвЂљР ВµР СР Вµ
     */
    public function login($username, $password) {
        $loginUrl = $this->apiUrl . '/login';

        $postData = json_encode([
            'username' => $username,
            'password' => $password
        ]);

        // Р ВРЎРѓР С—Р С•Р В»РЎРЉР В·РЎС“Р ВµР С cURL Р Т‘Р В»РЎРЏ Р С—Р С•Р В»РЎС“РЎвЂЎР ВµР Р…Р С‘РЎРЏ РЎвЂљР С•Р С”Р ВµР Р…Р В° Р С‘Р В· Р В·Р В°Р С–Р С•Р В»Р С•Р Р†Р С”Р С•Р Р† Set-Cookie
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $loginUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($postData)
            ],
            CURLOPT_HEADER => true,  // Р вЂ™Р С”Р В»РЎР‹РЎвЂЎР В°Р ВµР С Р В·Р В°Р С–Р С•Р В»Р С•Р Р†Р С”Р С‘ Р Р† Р С•РЎвЂљР Р†Р ВµРЎвЂљ
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1  // Р Р‡Р Р†Р Р…Р С• РЎС“Р С”Р В°Р В·РЎвЂ№Р Р†Р В°Р ВµР С HTTP/1.1
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception('cURL error: ' . $error);
        }

        if ($httpCode >= 400) {
            throw new Exception('HTTP error: ' . $httpCode . ' - Response: ' . $response);
        }

        $headers = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        // Р ВРЎвЂ°Р ВµР С РЎвЂљР С•Р С”Р ВµР Р… Р Р† Р В·Р В°Р С–Р С•Р В»Р С•Р Р†Р С”Р В°РЎвЂ¦ Set-Cookie (Р Р† 3x-ui РЎвЂљР С•Р С”Р ВµР Р… Р СР С•Р В¶Р ВµРЎвЂљ Р В±РЎвЂ№РЎвЂљРЎРЉ Р Р† Р С”РЎС“Р С”Р В°РЎвЂ¦ Р С”Р В°Р С” 3x-ui=...)
        preg_match('/3x-ui=([^;]+)/', $headers, $matches);
        $token = $matches[1] ?? null;

        // Р вЂўРЎРѓР В»Р С‘ Р Р…Р Вµ Р Р…Р В°РЎв‚¬Р В»Р С‘ РЎвЂљР С•Р С”Р ВµР Р… Р Р† Р С”РЎС“Р С”Р В°РЎвЂ¦, Р С—РЎР‚Р С•Р В±РЎС“Р ВµР С Р С—Р С•Р В»РЎС“РЎвЂЎР С‘РЎвЂљРЎРЉ Р С‘Р В· РЎвЂљР ВµР В»Р В° Р С•РЎвЂљР Р†Р ВµРЎвЂљР В°
        if (!$token) {
            $result = json_decode($body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("Р С›РЎв‚¬Р С‘Р В±Р С”Р В° Р Т‘Р ВµР С”Р С•Р Т‘Р С‘РЎР‚Р С•Р Р†Р В°Р Р…Р С‘РЎРЏ JSON Р С—РЎР‚Р С‘ Р В°Р Р†РЎвЂљР С•РЎР‚Р С‘Р В·Р В°РЎвЂ Р С‘Р С‘: " . json_last_error_msg());
            }

            // Р СџРЎР‚Р С•Р Р†Р ВµРЎР‚РЎРЏР ВµР С РЎС“РЎРѓР С—Р ВµРЎв‚¬Р Р…Р С•РЎРѓРЎвЂљРЎРЉ Р В°Р Р†РЎвЂљР С•РЎР‚Р С‘Р В·Р В°РЎвЂ Р С‘Р С‘
            if (empty($result['success'])) {
                $msg = $result['msg'] ?? 'Р Р…Р ВµР С‘Р В·Р Р†Р ВµРЎРѓРЎвЂљР Р…Р В°РЎРЏ Р С•РЎв‚¬Р С‘Р В±Р С”Р В° Р В°Р Р†РЎвЂљР С•РЎР‚Р С‘Р В·Р В°РЎвЂ Р С‘Р С‘';
                throw new Exception("Р С›РЎв‚¬Р С‘Р В±Р С”Р В° Р В°Р Р†РЎвЂљР С•РЎР‚Р С‘Р В·Р В°РЎвЂ Р С‘Р С‘: $msg");
            }

            // Р ВР В·Р Р†Р В»Р ВµР С”Р В°Р ВµР С ID РЎРѓР ВµРЎРѓРЎРѓР С‘Р С‘ Р С‘Р В· cookies
            if (!empty($headers)) {
                foreach (explode("\r\n", $headers) as $header) {
                    if (stripos($header, 'set-cookie:') === 0 || stripos($header, 'Set-Cookie:') === 0) {
                        preg_match('/3x-ui=([^;]+)/', $header, $cookieMatches);
                        if (!empty($cookieMatches[1])) {
                            $this->sessionId = $cookieMatches[1];
                            break;
                        }
                    }
                }
            }

            return $result;
        } else {
            $this->sessionId = $token;
            $result = json_decode($body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("Р С›РЎв‚¬Р С‘Р В±Р С”Р В° Р Т‘Р ВµР С”Р С•Р Т‘Р С‘РЎР‚Р С•Р Р†Р В°Р Р…Р С‘РЎРЏ JSON Р С—РЎР‚Р С‘ Р В°Р Р†РЎвЂљР С•РЎР‚Р С‘Р В·Р В°РЎвЂ Р С‘Р С‘: " . json_last_error_msg());
            }

            return $result;
        }
    }

    /**
     * Р РЋР С•Р В·Р Т‘Р В°РЎвЂљРЎРЉ Р Р…Р С•Р Р†РЎвЂ№Р в„– inbound
     *
     * @param string $remark Р СњР В°Р В·Р Р†Р В°Р Р…Р С‘Р Вµ inbound
     * @param int $port Р СџР С•РЎР‚РЎвЂљ Р Т‘Р В»РЎРЏ inbound
     * @param string $protocol Р СџРЎР‚Р С•РЎвЂљР С•Р С”Р С•Р В» (vmess, vless, trojan, shadowsocks, wireguard)
     * @param string $username Р ВР СРЎРЏ Р С—Р С•Р В»РЎРЉР В·Р С•Р Р†Р В°РЎвЂљР ВµР В»РЎРЏ Р Т‘Р В»РЎРЏ Р В°РЎС“РЎвЂљР ВµР Р…РЎвЂљР С‘РЎвЂћР С‘Р С”Р В°РЎвЂ Р С‘Р С‘
     * @param string $password Р СџР В°РЎР‚Р С•Р В»РЎРЉ Р Т‘Р В»РЎРЏ Р В°РЎС“РЎвЂљР ВµР Р…РЎвЂљР С‘РЎвЂћР С‘Р С”Р В°РЎвЂ Р С‘Р С‘
     * @param array $settings Р СњР В°РЎРѓРЎвЂљРЎР‚Р С•Р в„–Р С”Р С‘ Р С”Р В»Р С‘Р ВµР Р…РЎвЂљР В° Р С‘ Р С—РЎР‚Р С•РЎвЂљР С•Р С”Р С•Р В»Р В°
     * @param array $streamSettings Р СњР В°РЎРѓРЎвЂљРЎР‚Р С•Р в„–Р С”Р С‘ Р С—Р С•РЎвЂљР С•Р С”Р В° (РЎРѓР ВµРЎвЂљРЎРЉ, TLS Р С‘ РЎвЂљ.Р Т‘.)
     * @param array $sniffing Р СњР В°РЎРѓРЎвЂљРЎР‚Р С•Р в„–Р С”Р С‘ РЎРѓР Р…Р С‘РЎвЂћРЎвЂћР С‘Р Р…Р С–Р В°
     * @param string $trafficReset Р РЋР В±РЎР‚Р С•РЎРѓ РЎвЂљРЎР‚Р В°РЎвЂћР С‘Р С”Р В° (Р С—Р С• РЎС“Р СР С•Р В»РЎвЂЎР В°Р Р…Р С‘РЎР‹ 'never')
     * @param int $lastTrafficResetTime Р вЂ™РЎР‚Р ВµР СРЎРЏ Р С—Р С•РЎРѓР В»Р ВµР Т‘Р Р…Р ВµР С–Р С• РЎРѓР В±РЎР‚Р С•РЎРѓР В° РЎвЂљРЎР‚Р В°РЎвЂћР С‘Р С”Р В°
     * @return array Р В Р ВµР В·РЎС“Р В»РЎРЉРЎвЂљР В°РЎвЂљ Р С•Р С—Р ВµРЎР‚Р В°РЎвЂ Р С‘Р С‘
     */
    public function createInbound($remark, $port, $protocol, $username, $password, $settings = [], $streamSettings = [], $sniffing = [], $trafficReset = 'never', $lastTrafficResetTime = 0) {
        $this->login($username, $password);

        // Р СџР С•Р Т‘Р С–Р С•РЎвЂљР С•Р Р†Р С‘Р С Р Т‘Р В°Р Р…Р Р…РЎвЂ№Р Вµ Р Т‘Р В»РЎРЏ Р Р…Р С•Р Р†Р С•Р С–Р С• inbound
        $inboundData = [
            'up' => $settings['up'] ?? 0,
            'down' => $settings['down'] ?? 0,
            'total' => $settings['total'] ?? 0,
            'remark' => $remark,
            'enable' => $settings['enable'] ?? true,
            'expiryTime' => $settings['expiryTime'] ?? 0,
            'listen' => $settings['listen'] ?? '',
            'port' => $port,
            'protocol' => $protocol,
            'settings' => json_encode($settings),
            'streamSettings' => json_encode($streamSettings),
            'sniffing' => json_encode($sniffing),
            'trafficReset' => $trafficReset,
            'lastTrafficResetTime' => $lastTrafficResetTime
        ];

        // Р С›РЎвЂљР С—РЎР‚Р В°Р Р†Р В»РЎРЏР ВµР С Р В·Р В°Р С—РЎР‚Р С•РЎРѓ Р Р…Р В° РЎРѓР С•Р В·Р Т‘Р В°Р Р…Р С‘Р Вµ Р Р…Р С•Р Р†Р С•Р С–Р С• inbound
        $url = $this->apiUrl . '/panel/api/inbounds/add';

        // Р СџРЎР‚Р ВµР С•Р В±РЎР‚Р В°Р В·РЎС“Р ВµР С Р Т‘Р В°Р Р…Р Р…РЎвЂ№Р Вµ Р Р† РЎвЂћР С•РЎР‚Р СР В°РЎвЂљ JSON
        $postData = json_encode($inboundData);

        $result = $this->makeRequest($url, 'POST', $postData);

        // Р вЂўРЎРѓР В»Р С‘ Р С—РЎР‚Р С•РЎвЂљР С•Р С”Р С•Р В» wireguard, Р Р†Р С•Р В·Р Р†РЎР‚Р В°РЎвЂ°Р В°Р ВµР С РЎвЂљР В°Р С”Р В¶Р Вµ Р Т‘Р В°Р Р…Р Р…РЎвЂ№Р Вµ Р Т‘Р В»РЎРЏ Р С—Р С•Р Т‘Р С”Р В»РЎР‹РЎвЂЎР ВµР Р…Р С‘РЎРЏ
        $connectionData = null;
        if ($protocol === 'wireguard' && isset($settings['peers'][0])) {
            $peer = $settings['peers'][0];
            $serverPublicKey = $this->deriveWireguardPublicKey($settings['secretKey'] ?? '');
            if (!$serverPublicKey) {
                $serverPublicKey = $settings['secretKey'] ?? '';
            }
            $connectionData = [
                'type' => 'wireguard',
                'server_ip' => parse_url($this->apiUrl, PHP_URL_HOST),
                'port' => $port,
                'public_key' => $serverPublicKey, // РЎРѓР ВµРЎР‚Р Р†Р ВµРЎР‚Р Р…РЎвЂ№Р в„– Р С—РЎС“Р В±Р В»Р С‘РЎвЂЎР Р…РЎвЂ№Р в„– Р С”Р В»РЎР‹РЎвЂЎ
                'client_private_key' => $peer['privateKey'],
                'client_public_key' => $peer['publicKey'],
                'allowed_ips' => $peer['allowedIPs'],
                'keep_alive' => $peer['keepAlive'],
                'mtu' => $settings['mtu']
            ];

            // Р В¤Р С•РЎР‚Р СР С‘РЎР‚РЎС“Р ВµР С Р С”Р С•Р Р…РЎвЂћР С‘Р С–РЎС“РЎР‚Р В°РЎвЂ Р С‘РЎР‹ Р Р† РЎвЂћР С•РЎР‚Р СР В°РЎвЂљР Вµ, Р С–Р С•РЎвЂљР С•Р Р†Р С•Р С Р Т‘Р В»РЎРЏ Р В·Р В°Р С—Р С‘РЎРѓР С‘ Р Р† РЎвЂћР В°Р в„–Р В»
            $connectionData['config'] = "[Interface]\n";
            $connectionData['config'] .= "PrivateKey = " . $peer['privateKey'] . "\n";
            $connectionData['config'] .= "Address = " . implode(', ', $peer['allowedIPs']) . "\n";
            $connectionData['config'] .= "DNS = 1.1.1.1, 1.0.0.1\n";
            $connectionData['config'] .= "MTU = " . $settings['mtu'] . "\n\n";

            $connectionData['config'] .= "# " . $remark . "-1\n";
            $connectionData['config'] .= "[Peer]\n";
            $connectionData['config'] .= "PublicKey = " . $serverPublicKey . "\n"; // РЎРѓР ВµРЎР‚Р Р†Р ВµРЎР‚Р Р…РЎвЂ№Р в„– Р С—РЎС“Р В±Р В»Р С‘РЎвЂЎР Р…РЎвЂ№Р в„– Р С”Р В»РЎР‹РЎвЂЎ
            $connectionData['config'] .= "AllowedIPs = 0.0.0.0/0, ::/0\n";
            $connectionData['config'] .= "Endpoint = " . parse_url($this->apiUrl, PHP_URL_HOST) . ":" . $port . "\n";

            // Р РЋР С•Р В·Р Т‘Р В°Р ВµР С РЎвЂћР В°Р в„–Р В» Р С”Р С•Р Р…РЎвЂћР С‘Р С–РЎС“РЎР‚Р В°РЎвЂ Р С‘Р С‘
            $filename = $remark . '.conf';
            $filepath = __DIR__ . '/' . $filename;
            file_put_contents($filepath, $connectionData['config']);

            // Р В¤Р С•РЎР‚Р СР С‘РЎР‚РЎС“Р ВµР С URL Р Т‘Р В»РЎРЏ Р Т‘Р С•РЎРѓРЎвЂљРЎС“Р С—Р В° Р С” РЎвЂћР В°Р в„–Р В»РЎС“ (Р ВµРЎРѓР В»Р С‘ Р ВµРЎРѓРЎвЂљРЎРЉ HTTP Р С”Р С•Р Р…РЎвЂљР ВµР С”РЎРѓРЎвЂљ)
            if (isset($_SERVER['HTTP_HOST'])) {
                $currentUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
                $connectionData['config_file_url'] = $currentUrl . '/' . basename($filepath);
            }
            $connectionData['config_file_path'] = $filepath;
        }

        return [
            'success' => true,
            'msg' => "Inbound '$remark' РЎС“РЎРѓР С—Р ВµРЎв‚¬Р Р…Р С• РЎРѓР С•Р В·Р Т‘Р В°Р Р…",
            'inbound_info' => [
                'id' => $result['obj']['id'] ?? 'unknown',
                'remark' => $remark,
                'port' => $port,
                'protocol' => $protocol
            ],
            'connection_data' => $connectionData, // Р вЂќР В°Р Р…Р Р…РЎвЂ№Р Вµ Р Т‘Р В»РЎРЏ Р С—Р С•Р Т‘Р С”Р В»РЎР‹РЎвЂЎР ВµР Р…Р С‘РЎРЏ
            'result' => $result
        ];
    }
    /**
     * Р РЋР С•Р В·Р Т‘Р В°РЎвЂљРЎРЉ Р Р…Р С•Р Р†РЎвЂ№Р в„– inbound Р Т‘Р В»РЎРЏ wireguard РЎРѓ Р В°Р Р†РЎвЂљР С•Р СР В°РЎвЂљР С‘РЎвЂЎР ВµРЎРѓР С”Р С•Р в„– Р С–Р ВµР Р…Р ВµРЎР‚Р В°РЎвЂ Р С‘Р ВµР в„– Р С”Р В»РЎР‹РЎвЂЎР ВµР в„–
     *
     * @param string $remark Р СњР В°Р В·Р Р†Р В°Р Р…Р С‘Р Вµ inbound
     * @param string $username Р ВР СРЎРЏ Р С—Р С•Р В»РЎРЉР В·Р С•Р Р†Р В°РЎвЂљР ВµР В»РЎРЏ Р Т‘Р В»РЎРЏ Р В°РЎС“РЎвЂљР ВµР Р…РЎвЂљР С‘РЎвЂћР С‘Р С”Р В°РЎвЂ Р С‘Р С‘
     * @param string $password Р СџР В°РЎР‚Р С•Р В»РЎРЉ Р Т‘Р В»РЎРЏ Р В°РЎС“РЎвЂљР ВµР Р…РЎвЂљР С‘РЎвЂћР С‘Р С”Р В°РЎвЂ Р С‘Р С‘
     * @param int $port Р СџР С•РЎР‚РЎвЂљ Р Т‘Р В»РЎРЏ inbound (Р С—Р С• РЎС“Р СР С•Р В»РЎвЂЎР В°Р Р…Р С‘РЎР‹ Р В±РЎС“Р Т‘Р ВµРЎвЂљ Р Р†РЎвЂ№Р В±РЎР‚Р В°Р Р… РЎРѓР В»РЎС“РЎвЂЎР В°Р в„–Р Р…РЎвЂ№Р в„–)
     * @return array Р В Р ВµР В·РЎС“Р В»РЎРЉРЎвЂљР В°РЎвЂљ Р С•Р С—Р ВµРЎР‚Р В°РЎвЂ Р С‘Р С‘
     */
    public function createWireguardInbound($remark, $username, $password, $port = null) {
        // Р вЂњР ВµР Р…Р ВµРЎР‚Р В°РЎвЂ Р С‘РЎРЏ РЎРѓР В»РЎС“РЎвЂЎР В°Р в„–Р Р…Р С•Р С–Р С• Р С—Р С•РЎР‚РЎвЂљР В°, Р ВµРЎРѓР В»Р С‘ Р Р…Р Вµ РЎС“Р С”Р В°Р В·Р В°Р Р…
        if ($port === null) {
            $port = rand(10000, 65535);
        }

        // Р вЂњР ВµР Р…Р ВµРЎР‚Р В°РЎвЂ Р С‘РЎРЏ Р С”Р В»РЎР‹РЎвЂЎР ВµР в„– Р Т‘Р В»РЎРЏ wireguard
        $wgKeys = $this->generateWireGuardKeys();
        $serverKeys = $this->generateWireGuardKeys(); // Р С™Р В»РЎР‹РЎвЂЎР С‘ РЎРѓР ВµРЎР‚Р Р†Р ВµРЎР‚Р В°

        // Р РЋР С•Р В·Р Т‘Р В°Р ВµР С inbound РЎРѓ Р В°Р Р†РЎвЂљР С•Р СР В°РЎвЂљР С‘РЎвЂЎР ВµРЎРѓР С”Р С‘ РЎРѓР С–Р ВµР Р…Р ВµРЎР‚Р С‘РЎР‚Р С•Р Р†Р В°Р Р…Р Р…РЎвЂ№Р СР С‘ Р С”Р В»РЎР‹РЎвЂЎР В°Р СР С‘
        return $this->createInbound(
            $remark,      // Р СњР В°Р В·Р Р†Р В°Р Р…Р С‘Р Вµ
            $port,        // Р СџР С•РЎР‚РЎвЂљ
            'wireguard',  // Р СџРЎР‚Р С•РЎвЂљР С•Р С”Р С•Р В»
            $username,    // Р ВР СРЎРЏ Р С—Р С•Р В»РЎРЉР В·Р С•Р Р†Р В°РЎвЂљР ВµР В»РЎРЏ Р Т‘Р В»РЎРЏ Р В°РЎС“РЎвЂљР ВµР Р…РЎвЂљР С‘РЎвЂћР С‘Р С”Р В°РЎвЂ Р С‘Р С‘
            $password,    // Р СџР В°РЎР‚Р С•Р В»РЎРЉ Р Т‘Р В»РЎРЏ Р В°РЎС“РЎвЂљР ВµР Р…РЎвЂљР С‘РЎвЂћР С‘Р С”Р В°РЎвЂ Р С‘Р С‘
            [             // Р СњР В°РЎРѓРЎвЂљРЎР‚Р С•Р в„–Р С”Р С‘ Р Т‘Р В»РЎРЏ wireguard
                'mtu' => 1420,
                'secretKey' => $serverKeys['privateKey'], // Р СџРЎР‚Р С‘Р Р†Р В°РЎвЂљР Р…РЎвЂ№Р в„– Р С”Р В»РЎР‹РЎвЂЎ РЎРѓР ВµРЎР‚Р Р†Р ВµРЎР‚Р В°
                'peers' => [
                    [
                        'privateKey' => $wgKeys['privateKey'], // Р СџРЎР‚Р С‘Р Р†Р В°РЎвЂљР Р…РЎвЂ№Р в„– Р С”Р В»РЎР‹РЎвЂЎ Р С”Р В»Р С‘Р ВµР Р…РЎвЂљР В°
                        'publicKey' => $wgKeys['publicKey'],   // Р СџРЎС“Р В±Р В»Р С‘РЎвЂЎР Р…РЎвЂ№Р в„– Р С”Р В»РЎР‹РЎвЂЎ Р С”Р В»Р С‘Р ВµР Р…РЎвЂљР В°
                        'allowedIPs' => [
                            '10.0.0.2/32'
                        ],
                        'keepAlive' => 0
                    ]
                ],
                'noKernelTun' => false
            ],
            [],           // Stream settings не используются для wireguard
            [             // Sniffing
                'enabled' => true,
                'destOverride' => [
                    'http',
                    'tls',
                    'quic',
                    'fakedns'
                ],
                'metadataOnly' => false,
                'routeOnly' => false
            ],
            'never',      // trafficReset
            0             // lastTrafficResetTime
        );
    }

    /**
     * Р СњР В°Р в„–РЎвЂљР С‘ inbound Р С—Р С• remark, Р В° Р ВµРЎРѓР В»Р С‘ Р Р…Р Вµ Р Р…Р В°Р в„–Р Т‘Р ВµР Р… РІР‚вЂќ РЎРѓР С•Р В·Р Т‘Р В°РЎвЂљРЎРЉ (Р Т‘Р В»РЎРЏ wireguard)
     */
    public function findOrCreateWireguardInbound($remark, $username, $password, $port = null) {
        $existing = $this->findInboundByRemark($remark, $username, $password);
        if ($existing) {
            return [
                'success' => true,
                'msg' => "Inbound '$remark' already exists",
                'inbound_info' => [
                    'id' => $existing['id'] ?? 'unknown',
                    'remark' => $existing['remark'] ?? $remark,
                    'port' => $existing['port'] ?? null,
                    'protocol' => $existing['protocol'] ?? 'wireguard'
                ],
                'connection_data' => null,
                'result' => [
                    'success' => true,
                    'obj' => $existing
                ]
            ];
        }

        return $this->createWireguardInbound($remark, $username, $password, $port);
    }

    /**
     * Build WireGuard config by remark using existing inbound settings
     */
    public function getWireguardConfigByRemark($remark, $username, $password) {
        $inbound = $this->findInboundByRemark($remark, $username, $password);
        if (!$inbound) {
            throw new Exception("Inbound '$remark' not found");
        }

        $settings = json_decode($inbound['settings'] ?? '', true);
        if (json_last_error() !== JSON_ERROR_NONE || empty($settings['peers'][0]) || empty($settings['secretKey'])) {
            throw new Exception('WireGuard settings not found');
        }

        $peer = $settings['peers'][0];
            $serverPublicKey = $this->deriveWireguardPublicKey($settings['secretKey'] ?? '');
            if (!$serverPublicKey) {
                $serverPublicKey = $settings['secretKey'] ?? '';
            }
        $port = $inbound['port'] ?? null;
        if (!$port) {
            throw new Exception('WireGuard inbound port not found');
        }

        $serverHost = parse_url($this->apiUrl, PHP_URL_HOST);
        $config = "[Interface]\n";
        $config .= "PrivateKey = " . ($peer['privateKey'] ?? '') . "\n";
        $allowedIps = $peer['allowedIPs'] ?? ['10.0.0.2/32'];
        $config .= "Address = " . implode(', ', $allowedIps) . "\n";
        $config .= "DNS = 1.1.1.1, 1.0.0.1\n";
        $config .= "MTU = " . ($settings['mtu'] ?? 1420) . "\n\n";

        $config .= "# " . $remark . "-1\n";
        $config .= "[Peer]\n";
        $config .= "PublicKey = " . $serverPublicKey . "\n";
        $config .= "AllowedIPs = 0.0.0.0/0, ::/0\n";
        $config .= "Endpoint = " . $serverHost . ":" . $port . "\n";

        return $config;
    }

    /**
     * Р вЂњР ВµР Р…Р ВµРЎР‚Р В°РЎвЂ Р С‘РЎРЏ WireGuard Р С”Р В»РЎР‹РЎвЂЎР ВµР в„–
     *
     * @return array Р СљР В°РЎРѓРЎРѓР С‘Р Р† РЎРѓ privateKey Р С‘ publicKey
     */
    private function generateWireGuardKeys() {
        // Р вЂњР ВµР Р…Р ВµРЎР‚Р В°РЎвЂ Р С‘РЎРЏ Р С—РЎР‚Р С‘Р Р†Р В°РЎвЂљР Р…Р С•Р С–Р С• Р С”Р В»РЎР‹РЎвЂЎР В° (32 Р В±Р В°Р в„–РЎвЂљР В°, Р В·Р В°Р С”Р С•Р Т‘Р С‘РЎР‚Р С•Р Р†Р В°Р Р…Р Р…РЎвЂ№Р Вµ Р Р† base64)
        $privateKey = base64_encode(random_bytes(32));

        // Р вЂ™РЎвЂ№РЎвЂЎР С‘РЎРѓР В»Р ВµР Р…Р С‘Р Вµ Р С—РЎС“Р В±Р В»Р С‘РЎвЂЎР Р…Р С•Р С–Р С• Р С”Р В»РЎР‹РЎвЂЎР В° РЎРѓ Р С—Р С•Р СР С•РЎвЂ°РЎРЉРЎР‹ libsodium
        if (function_exists('sodium_crypto_scalarmult_base')) {
            $decodedPrivateKey = base64_decode($privateKey);
            $publicKey = sodium_crypto_scalarmult_base($decodedPrivateKey);
            $publicKeyBase64 = base64_encode($publicKey);
        } else {
            // Р С’Р В»РЎРЉРЎвЂљР ВµРЎР‚Р Р…Р В°РЎвЂљР С‘Р Р†Р Р…РЎвЂ№Р в„– РЎРѓР С—Р С•РЎРѓР С•Р В± Р С–Р ВµР Р…Р ВµРЎР‚Р В°РЎвЂ Р С‘Р С‘ Р С”Р В»РЎР‹РЎвЂЎР ВµР в„– (РЎС“Р С—РЎР‚Р С•РЎвЂ°Р ВµР Р…Р Р…РЎвЂ№Р в„–)
            $publicKeyBase64 = base64_encode(random_bytes(32));
        }

        return [
            'privateKey' => $privateKey,
            'publicKey' => $publicKeyBase64
        ];
    }

    private function deriveWireguardPublicKey(string $privateKey): ?string
    {
        if (!$privateKey || !function_exists('sodium_crypto_scalarmult_base')) {
            return null;
        }

        $decoded = base64_decode($privateKey, true);
        if ($decoded === false || strlen($decoded) !== 32) {
            return null;
        }

        $public = sodium_crypto_scalarmult_base($decoded);
        return base64_encode($public);
    }


    public function getInbounds($username, $password) {
        $this->login($username, $password);

        $url = $this->apiUrl . '/panel/api/inbounds/list';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-Requested-With: XMLHttpRequest',
                "Cookie: 3x-ui=" . $this->sessionId
            ],
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception('cURL error: ' . $error);
        }

        if ($httpCode >= 400) {
            throw new Exception('HTTP error: ' . $httpCode . ' - Response: ' . $response);
        }

        if (empty($response)) {
            return [];
        }

        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Р С›РЎв‚¬Р С‘Р В±Р С”Р В° Р Т‘Р ВµР С”Р С•Р Т‘Р С‘РЎР‚Р С•Р Р†Р В°Р Р…Р С‘РЎРЏ JSON: " . json_last_error_msg());
        }

        if (isset($data['success']) && $data['success']) {
            return $data['obj'] ?? [];
        } else {
            $msg = $data['msg'] ?? 'Р Р…Р ВµР С‘Р В·Р Р†Р ВµРЎРѓРЎвЂљР Р…Р В°РЎРЏ Р С•РЎв‚¬Р С‘Р В±Р С”Р В° Р С—РЎР‚Р С‘ Р С—Р С•Р В»РЎС“РЎвЂЎР ВµР Р…Р С‘Р С‘ inbounds';
            throw new Exception("Р С›РЎв‚¬Р С‘Р В±Р С”Р В° Р С—Р С•Р В»РЎС“РЎвЂЎР ВµР Р…Р С‘РЎРЏ inbounds: $msg");
        }
    }

    /**
     * Р СњР В°Р в„–РЎвЂљР С‘ inbound Р С—Р С• Р С—РЎР‚Р С‘Р СР ВµРЎвЂЎР В°Р Р…Р С‘РЎР‹
     */
    public function findInboundByRemark($remark, $username, $password) {
        $inbounds = $this->getInbounds($username, $password);

        foreach ($inbounds as $inbound) {
            if ($inbound['remark'] === $remark) {
                return $inbound;
            }
        }

        return null;
    }

    /**
     * Р вЂ™Р С”Р В»РЎР‹РЎвЂЎР ВµР Р…Р С‘Р Вµ inbound Р С—Р С• Р С—РЎР‚Р С‘Р СР ВµРЎвЂЎР В°Р Р…Р С‘РЎР‹
     */
    public function enableInbound($remark, $username, $password) {
        $this->login($username, $password);

        // Р ВРЎвЂ°Р ВµР С inbound Р С—Р С• Р С—РЎР‚Р С‘Р СР ВµРЎвЂЎР В°Р Р…Р С‘РЎР‹
        $inbound = $this->findInboundByRemark($remark, $username, $password);
        if (!$inbound) {
            throw new Exception("Inbound РЎРѓ Р С—РЎР‚Р С‘Р СР ВµРЎвЂЎР В°Р Р…Р С‘Р ВµР С '$remark' Р Р…Р Вµ Р Р…Р В°Р в„–Р Т‘Р ВµР Р…");
        }
        $inboundId = $inbound['id'];

        // Р СџР С•Р В»РЎС“РЎвЂЎР В°Р ВµР С РЎвЂљР ВµР С”РЎС“РЎвЂ°Р С‘Р Вµ Р Р…Р В°РЎРѓРЎвЂљРЎР‚Р С•Р в„–Р С”Р С‘ inbound
        $inbounds = $this->getInbounds($username, $password);
        $targetInbound = null;

        foreach ($inbounds as $inbound) {
            if ($inbound['id'] == $inboundId) {
                $targetInbound = $inbound;
                break;
            }
        }

        if (!$targetInbound) {
            throw new Exception("Inbound РЎРѓ Р С—РЎР‚Р С‘Р СР ВµРЎвЂЎР В°Р Р…Р С‘Р ВµР С '$remark' Р Р…Р Вµ Р Р…Р В°Р в„–Р Т‘Р ВµР Р…");
        }

        // Р С›Р В±Р Р…Р С•Р Р†Р В»РЎРЏР ВµР С РЎРѓРЎвЂљР В°РЎвЂљРЎС“РЎРѓ inbound
        $targetInbound['enable'] = true;

        // Р СџР С•Р Т‘Р С–Р С•РЎвЂљР С•Р Р†Р С‘Р С Р Т‘Р В°Р Р…Р Р…РЎвЂ№Р Вµ Р Т‘Р В»РЎРЏ Р С•Р В±Р Р…Р С•Р Р†Р В»Р ВµР Р…Р С‘РЎРЏ
        $updateData = $targetInbound;
        $updateData['id'] = $inboundId; // Р Р€Р В±Р ВµР Т‘Р С‘Р СРЎРѓРЎРЏ, РЎвЂЎРЎвЂљР С• ID Р Р†Р С”Р В»РЎР‹РЎвЂЎР ВµР Р… Р Р† Р Т‘Р В°Р Р…Р Р…РЎвЂ№Р Вµ

        // Р ВРЎРѓР С—Р С•Р В»РЎРЉР В·РЎС“Р ВµР С РЎРѓРЎС“РЎвЂ°Р ВµРЎРѓРЎвЂљР Р†РЎС“РЎР‹РЎвЂ°Р С‘Р в„– Р СР ВµРЎвЂљР С•Р Т‘ makeRequest Р Т‘Р В»РЎРЏ Р Р†РЎвЂ№Р С—Р С•Р В»Р Р…Р ВµР Р…Р С‘РЎРЏ Р В·Р В°Р С—РЎР‚Р С•РЎРѓР В°
        // Р СћР ВµР С—Р ВµРЎР‚РЎРЉ Р С‘РЎРѓР С—Р С•Р В»РЎРЉР В·РЎС“Р ВµР С Р С—РЎР‚Р В°Р Р†Р С‘Р В»РЎРЉР Р…РЎвЂ№Р в„– URL Р Т‘Р В»РЎРЏ Р С•Р В±Р Р…Р С•Р Р†Р В»Р ВµР Р…Р С‘РЎРЏ inbound Р С—Р С• ID
        $url = $this->apiUrl . '/panel/api/inbounds/update/' . $inboundId;
        $result = $this->makeRequest($url, 'POST', json_encode($updateData));

        return $result;
    }

    /**
     * Р вЂ™РЎвЂ№Р С”Р В»РЎР‹РЎвЂЎР ВµР Р…Р С‘Р Вµ inbound Р С—Р С• Р С—РЎР‚Р С‘Р СР ВµРЎвЂЎР В°Р Р…Р С‘РЎР‹
     */
    public function disableInbound($remark, $username, $password) {
        $this->login($username, $password);

        // Р ВРЎвЂ°Р ВµР С inbound Р С—Р С• Р С—РЎР‚Р С‘Р СР ВµРЎвЂЎР В°Р Р…Р С‘РЎР‹
        $inbound = $this->findInboundByRemark($remark, $username, $password);
        if (!$inbound) {
            throw new Exception("Inbound РЎРѓ Р С—РЎР‚Р С‘Р СР ВµРЎвЂЎР В°Р Р…Р С‘Р ВµР С '$remark' Р Р…Р Вµ Р Р…Р В°Р в„–Р Т‘Р ВµР Р…");
        }
        $inboundId = $inbound['id'];

        // Р СџР С•Р В»РЎС“РЎвЂЎР В°Р ВµР С РЎвЂљР ВµР С”РЎС“РЎвЂ°Р С‘Р Вµ Р Р…Р В°РЎРѓРЎвЂљРЎР‚Р С•Р в„–Р С”Р С‘ inbound
        $inbounds = $this->getInbounds($username, $password);
        $targetInbound = null;

        foreach ($inbounds as $inbound) {
            if ($inbound['id'] == $inboundId) {
                $targetInbound = $inbound;
                break;
            }
        }

        if (!$targetInbound) {
            throw new Exception("Inbound РЎРѓ Р С—РЎР‚Р С‘Р СР ВµРЎвЂЎР В°Р Р…Р С‘Р ВµР С '$remark' Р Р…Р Вµ Р Р…Р В°Р в„–Р Т‘Р ВµР Р…");
        }

        // Р С›Р В±Р Р…Р С•Р Р†Р В»РЎРЏР ВµР С РЎРѓРЎвЂљР В°РЎвЂљРЎС“РЎРѓ inbound
        $targetInbound['enable'] = false;

        // Р СџР С•Р Т‘Р С–Р С•РЎвЂљР С•Р Р†Р С‘Р С Р Т‘Р В°Р Р…Р Р…РЎвЂ№Р Вµ Р Т‘Р В»РЎРЏ Р С•Р В±Р Р…Р С•Р Р†Р В»Р ВµР Р…Р С‘РЎРЏ
        $updateData = $targetInbound;
        $updateData['id'] = $inboundId; // Р Р€Р В±Р ВµР Т‘Р С‘Р СРЎРѓРЎРЏ, РЎвЂЎРЎвЂљР С• ID Р Р†Р С”Р В»РЎР‹РЎвЂЎР ВµР Р… Р Р† Р Т‘Р В°Р Р…Р Р…РЎвЂ№Р Вµ

        // Р ВРЎРѓР С—Р С•Р В»РЎРЉР В·РЎС“Р ВµР С РЎРѓРЎС“РЎвЂ°Р ВµРЎРѓРЎвЂљР Р†РЎС“РЎР‹РЎвЂ°Р С‘Р в„– Р СР ВµРЎвЂљР С•Р Т‘ makeRequest Р Т‘Р В»РЎРЏ Р Р†РЎвЂ№Р С—Р С•Р В»Р Р…Р ВµР Р…Р С‘РЎРЏ Р В·Р В°Р С—РЎР‚Р С•РЎРѓР В°
        // Р СћР ВµР С—Р ВµРЎР‚РЎРЉ Р С‘РЎРѓР С—Р С•Р В»РЎРЉР В·РЎС“Р ВµР С Р С—РЎР‚Р В°Р Р†Р С‘Р В»РЎРЉР Р…РЎвЂ№Р в„– URL Р Т‘Р В»РЎРЏ Р С•Р В±Р Р…Р С•Р Р†Р В»Р ВµР Р…Р С‘РЎРЏ inbound Р С—Р С• ID
        $url = $this->apiUrl . '/panel/api/inbounds/update/' . $inboundId;
        $result = $this->makeRequest($url, 'POST', json_encode($updateData));

        return $result;
    }

    /**
     * Р СџР ВµРЎР‚Р ВµР С”Р В»РЎР‹РЎвЂЎР ВµР Р…Р С‘Р Вµ РЎРѓРЎвЂљР В°РЎвЂљРЎС“РЎРѓР В° inbound (Р Р†Р С”Р В»/Р Р†РЎвЂ№Р С”Р В») Р С—Р С• Р С—РЎР‚Р С‘Р СР ВµРЎвЂЎР В°Р Р…Р С‘РЎР‹
     */
    public function toggleInbound($remark, $username, $password) {
        $this->login($username, $password);

        // Р ВРЎвЂ°Р ВµР С inbound Р С—Р С• Р С—РЎР‚Р С‘Р СР ВµРЎвЂЎР В°Р Р…Р С‘РЎР‹
        $inbound = $this->findInboundByRemark($remark, $username, $password);
        if (!$inbound) {
            throw new Exception("Inbound РЎРѓ Р С—РЎР‚Р С‘Р СР ВµРЎвЂЎР В°Р Р…Р С‘Р ВµР С '$remark' Р Р…Р Вµ Р Р…Р В°Р в„–Р Т‘Р ВµР Р…");
        }

        // Р С›Р С—РЎР‚Р ВµР Т‘Р ВµР В»РЎРЏР ВµР С РЎвЂљР ВµР С”РЎС“РЎвЂ°Р С‘Р в„– РЎРѓРЎвЂљР В°РЎвЂљРЎС“РЎРѓ Р С‘ Р С—Р ВµРЎР‚Р ВµР С”Р В»РЎР‹РЎвЂЎР В°Р ВµР С
        if ($inbound['enable']) {
            return $this->disableInbound($remark, $username, $password);
        } else {
            return $this->enableInbound($remark, $username, $password);
        }
    }

    /**
     * Р СџР С•Р В»РЎС“РЎвЂЎР С‘РЎвЂљРЎРЉ РЎРѓРЎвЂљР В°РЎвЂљРЎС“РЎРѓ inbound Р С—Р С• Р С—РЎР‚Р С‘Р СР ВµРЎвЂЎР В°Р Р…Р С‘РЎР‹
     */
    public function getInboundStatus($remark, $username, $password) {
        $inbound = $this->findInboundByRemark($remark, $username, $password);
        if (!$inbound) {
            throw new Exception("Inbound РЎРѓ Р С—РЎР‚Р С‘Р СР ВµРЎвЂЎР В°Р Р…Р С‘Р ВµР С '$remark' Р Р…Р Вµ Р Р…Р В°Р в„–Р Т‘Р ВµР Р…");
        }

        return [
            'remark' => $inbound['remark'],
            'id' => $inbound['id'],
            'status' => $inbound['enable'] ? 'enabled' : 'disabled',
            'enabled' => $inbound['enable']
        ];
    }

    /**
     * Р РЋР С•РЎвЂ¦РЎР‚Р В°Р Р…Р С‘РЎвЂљРЎРЉ Р Т‘Р В°Р Р…Р Р…РЎвЂ№Р Вµ Р Т‘Р В»РЎРЏ Р С—Р С•Р Т‘Р С”Р В»РЎР‹РЎвЂЎР ВµР Р…Р С‘РЎРЏ Р Р† Р В±Р В°Р В·РЎС“ Р Т‘Р В°Р Р…Р Р…РЎвЂ№РЎвЂ¦
     *
     * @param int $userId ID Р С—Р С•Р В»РЎРЉР В·Р С•Р Р†Р В°РЎвЂљР ВµР В»РЎРЏ
     * @param int $subscriptionId ID Р С—Р С•Р Т‘Р С—Р С‘РЎРѓР С”Р С‘
     * @param string $config Р С™Р С•Р Р…РЎвЂћР С‘Р С–РЎС“РЎР‚Р В°РЎвЂ Р С‘РЎРЏ Р Т‘Р В»РЎРЏ Р С—Р С•Р Т‘Р С”Р В»РЎР‹РЎвЂЎР ВµР Р…Р С‘РЎРЏ
     * @return bool Р Р€РЎРѓР С—Р ВµРЎв‚¬Р Р…Р С•РЎРѓРЎвЂљРЎРЉ РЎРѓР С•РЎвЂ¦РЎР‚Р В°Р Р…Р ВµР Р…Р С‘РЎРЏ
     */
    public function saveConnectionConfig($userId, $subscriptionId, $config) {
        // Р СџР С•Р Т‘Р С”Р В»РЎР‹РЎвЂЎР В°Р ВµР С Р СР С•Р Т‘Р ВµР В»РЎРЉ UserSubscription
        require_once __DIR__.'/../vendor/autoload.php';

        // Р ВР Р…Р С‘РЎвЂ Р С‘Р В°Р В»Р С‘Р В·Р С‘РЎР‚РЎС“Р ВµР С Р С—РЎР‚Р С‘Р В»Р С•Р В¶Р ВµР Р…Р С‘Р Вµ Laravel
        $app = require_once __DIR__.'/../bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        // Р СџР С•Р Т‘Р С”Р В»РЎР‹РЎвЂЎР В°Р ВµР С Р СР С•Р Т‘Р ВµР В»РЎРЉ
        $userSubscription = \App\Models\UserSubscription::where('user_id', $userId)
            ->where('subscription_id', $subscriptionId)
            ->latest() // Р вЂР ВµРЎР‚Р ВµР С Р С—Р С•РЎРѓР В»Р ВµР Т‘Р Р…РЎР‹РЎР‹ Р В·Р В°Р С—Р С‘РЎРѓРЎРЉ
            ->first();

        if ($userSubscription) {
            $userSubscription->connection_config = $config;
            return $userSubscription->save();
        }

        // Р вЂўРЎРѓР В»Р С‘ Р Р…Р Вµ Р Р…Р В°РЎв‚¬Р В»Р С‘ Р В·Р В°Р С—Р С‘РЎРѓРЎРЉ, РЎРѓР С•Р В·Р Т‘Р В°Р ВµР С Р Р…Р С•Р Р†РЎС“РЎР‹
        $userSubscription = new \App\Models\UserSubscription();
        $userSubscription->user_id = $userId;
        $userSubscription->subscription_id = $subscriptionId;
        $userSubscription->connection_config = $config;
        return $userSubscription->save();
    }
}

// Р СџРЎР‚Р С‘Р СР ВµРЎР‚ Р С‘РЎРѓР С—Р С•Р В»РЎРЉР В·Р С•Р Р†Р В°Р Р…Р С‘РЎРЏ:
//if (php_sapi_name() === 'cli') {
//    try {
//        // Р ВРЎРѓР С—Р С•Р В»РЎРЉР В·РЎС“Р ВµР С Р С—Р С•Р В»Р Р…РЎвЂ№Р в„– URL Р С”Р В°Р С” Р Р† F12
//        $inboundManager = new \App\Models\components\InboundManagerVless('https://5.39.253.235:7119/NXT4eYY3t0og1yi');
//
//        // Р СџР В°РЎР‚Р В°Р СР ВµРЎвЂљРЎР‚РЎвЂ№ Р Т‘Р В»РЎРЏ Р С—Р С•Р Т‘Р С”Р В»РЎР‹РЎвЂЎР ВµР Р…Р С‘РЎРЏ
//        $username = 'bQ6nY8OwUA';
//        $password = 'rL82RaoZCu';
//        $remark = '78788787';
//
//        // Р вЂ™РЎвЂ№Р Р†Р С•Р Т‘Р С‘Р С Р СР ВµР Р…РЎР‹
//        echo "=== Р Р€Р С—РЎР‚Р В°Р Р†Р В»Р ВµР Р…Р С‘Р Вµ inbounds ===\n";
//        echo "1. Включить inbound\n";
//        echo "2. Выключить inbound\n";
//        echo "3. Переключить статус inbound\n";
//        echo "4. Получить статус inbound\n";
//        echo "5. Р вЂ™РЎвЂ№Р Р†Р ВµРЎРѓРЎвЂљР С‘ РЎРѓР С—Р С‘РЎРѓР С•Р С” Р Р†РЎРѓР ВµРЎвЂ¦ inbounds\n";
//        echo "6. Р РЋР С•Р В·Р Т‘Р В°РЎвЂљРЎРЉ Р Р…Р С•Р Р†РЎвЂ№Р в„– wireguard inbound\n";
//        echo "Р вЂ™РЎвЂ№Р В±Р ВµРЎР‚Р С‘РЎвЂљР Вµ Р Т‘Р ВµР в„–РЎРѓРЎвЂљР Р†Р С‘Р Вµ (1-6): ";
//        $handle = fopen("php://stdin", "r");
//        $choice = trim(fgets($handle));
//
//        switch ($choice) {
//            case '1':
//                $result = $inboundManager->enableInbound($remark, $username, $password);
//                echo "Результат включения: " . json_encode($result) . "\n";
//                break;
//            case '2':
//                $result = $inboundManager->disableInbound($remark, $username, $password);
//                echo "Результат выключения: " . json_encode($result) . "\n";
//                break;
//            case '3':
//                $result = $inboundManager->toggleInbound($remark, $username, $password);
//                echo "Результат переключения: " . json_encode($result) . "\n";
//                break;
//            case '4':
//                $result = $inboundManager->getInboundStatus($remark, $username, $password);
//                echo "Статус inbound: " . json_encode($result) . "\n";
//                break;
//            case '5':
//                $inbounds = $inboundManager->getInbounds($username, $password);
//                echo "Р РЋР С—Р С‘РЎРѓР С•Р С” inbounds:\n";
//                foreach ($inbounds as $inbound) {
//                    $status = $inbound['enable'] ? 'Р Р†Р С”Р В»РЎР‹РЎвЂЎР ВµР Р…' : 'Р Р†РЎвЂ№Р С”Р В»РЎР‹РЎвЂЎР ВµР Р…';
//                    echo "ID: {$inbound['id']}, Р СџРЎР‚Р С‘Р СР ВµРЎвЂЎР В°Р Р…Р С‘Р Вµ: {$inbound['remark']}, Р РЋРЎвЂљР В°РЎвЂљРЎС“РЎРѓ: $status\n";
//                }
//                break;
//            case '6':
//                echo "Р вЂ™Р Р†Р ВµР Т‘Р С‘РЎвЂљР Вµ Р Р…Р В°Р В·Р Р†Р В°Р Р…Р С‘Р Вµ Р Т‘Р В»РЎРЏ Р Р…Р С•Р Р†Р С•Р С–Р С• wireguard inbound: ";
//                $newRemark = trim(fgets($handle));
//
//                $result = $inboundManager->createWireguardInbound($newRemark, $username, $password);
//                echo "Результат создания: " . json_encode($result) . "\n";
//                break;
//            default:
//                echo "Р СњР ВµР Р†Р ВµРЎР‚Р Р…РЎвЂ№Р в„– Р Р†РЎвЂ№Р В±Р С•РЎР‚!\n";
//        }
//
//        fclose($handle);
//
//    } catch (Exception $e) {
//        echo "Р С›РЎв‚¬Р С‘Р В±Р С”Р В°: " . $e->getMessage() . "\n";
//    }
//}


 // Р СџРЎР‚Р С‘Р СР ВµРЎР‚РЎвЂ№ Р С—РЎР‚Р С•Р С–РЎР‚Р В°Р СР СР Р…Р С•Р С–Р С• Р С‘РЎРѓР С—Р С•Р В»РЎРЉР В·Р С•Р Р†Р В°Р Р…Р С‘РЎРЏ:
 //
  // Р СџР С•Р Т‘Р С”Р В»РЎР‹РЎвЂЎР ВµР Р…Р С‘Р Вµ Р С”Р В»Р В°РЎРѓРЎРѓР В°
 // require_once 'InboundManagerVless.php';
 //
 // Р РЋР С•Р В·Р Т‘Р В°Р Р…Р С‘Р Вµ РЎРЊР С”Р В·Р ВµР СР С—Р В»РЎРЏРЎР‚Р В° Р С”Р В»Р В°РЎРѓРЎРѓР В°
  //$inboundManager = new \App\Models\components\InboundManagerVless('https://5.39.253.235:7119/NXT4eYY3t0og1yi');

 // Р вЂ™Р С”Р В»РЎР‹РЎвЂЎР С‘РЎвЂљРЎРЉ inbound Р С—Р С• Р С—РЎР‚Р С‘Р СР ВµРЎвЂЎР В°Р Р…Р С‘РЎР‹
 //$result = $inboundManager->enableInbound('2222356', 'bQ6nY8OwUA', 'rL82RaoZCu');

// Р вЂ™РЎвЂ№Р С”Р В»РЎР‹РЎвЂЎР С‘РЎвЂљРЎРЉ inbound Р С—Р С• Р С—РЎР‚Р С‘Р СР ВµРЎвЂЎР В°Р Р…Р С‘РЎР‹
 //$result = $inboundManager->disableInbound('2222356', 'bQ6nY8OwUA', 'rL82RaoZCu');
 //
  // Переключить статус inbound
 // $result = $inboundManager->toggleInbound('Р Р…Р В°Р В·Р Р†Р В°Р Р…Р С‘Р Вµ_inbound', 'username', 'password');
 //
 // // Получить статус inbound
 // $status = $inboundManager->getInboundStatus('Р Р…Р В°Р В·Р Р†Р В°Р Р…Р С‘Р Вµ_inbound', 'username', 'password');
//
 // // Р СџР С•Р В»РЎС“РЎвЂЎР С‘РЎвЂљРЎРЉ РЎРѓР С—Р С‘РЎРѓР С•Р С” Р Р†РЎРѓР ВµРЎвЂ¦ inbounds
 // $inbounds = $inboundManager->getInbounds('username', 'password');
//
// // Р РЋР С•Р В·Р Т‘Р В°РЎвЂљРЎРЉ Р Р…Р С•Р Р†РЎвЂ№Р в„– wireguard inbound
 //$result = $inboundManager->createWireguardInbound('8888888', 'bQ6nY8OwUA', 'rL82RaoZCu');
 //
  // Создать обычный inbound
//  $result = $inboundManager->createInbound(
//      'Р Р…Р В°Р В·Р Р†Р В°Р Р…Р С‘Р Вµ',           // Р С—РЎР‚Р С‘Р СР ВµРЎвЂЎР В°Р Р…Р С‘Р Вµ
//      20000,               // Р С—Р С•РЎР‚РЎвЂљ
//      'vless',             // Р С—РЎР‚Р С•РЎвЂљР С•Р С”Р С•Р В»
//      'username',          // Р С‘Р СРЎРЏ Р С—Р С•Р В»РЎРЉР В·Р С•Р Р†Р В°РЎвЂљР ВµР В»РЎРЏ Р Т‘Р В»РЎРЏ Р В°РЎС“РЎвЂљР ВµР Р…РЎвЂљР С‘РЎвЂћР С‘Р С”Р В°РЎвЂ Р С‘Р С‘
//      'password',          // Р С—Р В°РЎР‚Р С•Р В»РЎРЉ Р Т‘Р В»РЎРЏ Р В°РЎС“РЎвЂљР ВµР Р…РЎвЂљР С‘РЎвЂћР С‘Р С”Р В°РЎвЂ Р С‘Р С‘
//      $settings,           // Р Р…Р В°РЎРѓРЎвЂљРЎР‚Р С•Р в„–Р С”Р С‘
//      $streamSettings,     // Р Р…Р В°РЎРѓРЎвЂљРЎР‚Р С•Р в„–Р С”Р С‘ Р С—Р С•РЎвЂљР С•Р С”Р В°
//      $sniffing            // Р Р…Р В°РЎРѓРЎвЂљРЎР‚Р С•Р в„–Р С”Р С‘ РЎРѓР Р…Р С‘РЎвЂћРЎвЂћР С‘Р Р…Р С–Р В°
//  );
















