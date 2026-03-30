<?php

namespace App\Models\components;

use Exception;

/**
 * пїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ 3x-ui
 * пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ, пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
 */
class UserManagerVless
{
    private $apiUrl;
    private $sessionId;
    private $serverIp;
    private $port;
    private $pbk;
    private $fp;
    private $sni;
    private $sid;
    private $spx;
    private $flow;

    public function __construct($apiUrl) {
        if (!filter_var($apiUrl, FILTER_VALIDATE_URL)) {
            throw new Exception("пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ URL API: $apiUrl");
        }
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->sessionId = null;
    }

    /**
     * пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅ
     *
     * @param string $serverIp IP-пїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅ
     * @param int $port пїЅпїЅпїЅпїЅ
     * @param string $pbk пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅ
     * @param string $fp пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
     * @param string $sni SNI
     * @param string $sid SID
     * @param string $spx SPX
     * @param string $flow Flow
     */
    public function setServerConfig($serverIp, $port, $pbk, $fp, $sni, $sid, $spx, $flow) {
        $this->serverIp = $serverIp;
        $this->port = $port;
        $this->pbk = $pbk;
        $this->fp = $fp;
        $this->sni = $sni;
        $this->sid = $sid;
        $this->spx = $spx;
        $this->flow = $flow;
    }

    /**
     * пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅ
     *
     * @param string $username пїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
     * @param string $password пїЅпїЅпїЅпїЅпїЅпїЅ
     * @throws Exception пїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
     */
    public function login($username, $password) {
        $loginUrl = $this->apiUrl . '/login';

        $postData = json_encode([
            'username' => $username,
            'password' => $password
        ]);

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
            CURLOPT_HEADER => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1
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

        // пїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅ пїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ Set-Cookie
        preg_match('/3x-ui=([^;]+)/', $headers, $matches);
        $this->sessionId = $matches[1] ?? null;

        if (!$this->sessionId) {
            $result = json_decode($body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("пїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ JSON пїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ: " . json_last_error_msg());
            }

            if (empty($result['success'])) {
                $msg = $result['msg'] ?? 'пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ';
                throw new Exception("пїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ: $msg");
            }
        }
    }

    /**
     * пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
     *
     * @param array $userData пїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
     * @return array пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
     * @throws Exception пїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
     */
    public function createUser($userData) {
        if (!$this->sessionId) {
            throw new Exception("пїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ");
        }

        $url = $this->apiUrl . '/panel/api/inbounds/addClient';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($userData),
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
            return ['success' => true, 'msg' => 'Empty response (likely successful creation)'];
        }

        $result = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['success' => true, 'msg' => 'Response parsed as successful'];
        }

        return $result;
    }

    /**
     * пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
     *
     * @param string $email Email пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
     * @param string $username пїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
     * @param string $password пїЅпїЅпїЅпїЅпїЅпїЅ
     * @return array пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
     * @throws Exception пїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
     */
    public function enableUser($email, $username, $password) {
        return $this->setUserStatusByEmail($email, true, $username, $password);
    }

    /**
     * пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
     *
     * @param string $email Email пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
     * @param string $username пїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
     * @param string $password пїЅпїЅпїЅпїЅпїЅпїЅ
     * @return array пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
     * @throws Exception пїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
     */
    public function disableUser($email, $username, $password) {
        return $this->setUserStatusByEmail($email, false, $username, $password);
    }

    /**
     * пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅ email
     *
     * @param string $email Email пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
     * @param bool $enabled пїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ (пїЅпїЅпїЅпїЅпїЅпїЅпїЅ/пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ)
     * @param string $username пїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
     * @param string $password пїЅпїЅпїЅпїЅпїЅпїЅ
     * @return array пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
     * @throws Exception пїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
     */
    private function setUserStatusByEmail($email, $enabled, $username, $password) {
        $this->login($username, $password);

        if (!method_exists($this, 'getUsers')) {
            throw new Exception("пїЅпїЅпїЅпїЅпїЅ getUsers пїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ");
        }

        $users = $this->getUsers();
        $targetUser = null;
        $inboundId = '5';
        $targetInbound = null;

        //print_r($users); die;

        foreach ($users as $user) {
            // пїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ clientStats
            if (isset($user['clientStats'])) {
                foreach ($user['clientStats'] as $client) {
                    if ($client['email'] == $email) {
                        $targetUser = $client;
                        $inboundId = $user['id'];
                        $targetInbound = $user;
                        break 2;
                    }
                }
            }

            // пїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ settings.clients
            if (isset($user['settings'])) {
                $settings = json_decode($user['settings'], true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    continue; // пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ, пїЅпїЅпїЅпїЅ пїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
                }

                if (isset($settings['clients'])) {
                    foreach ($settings['clients'] as $client) {
                        if ($client['email'] == $email) {
                            // пїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅ, пїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅ, пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ clientStats
                            $targetUser = [
                                'email' => $client['email'],
                                'uuid' => $client['id'],
                                'id' => null, // пїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅ пїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅ
                                'inboundId' => $user['id']
                            ];
                            $inboundId = $user['id'];
                            $targetInbound = $user;
                            break 2;
                        }
                    }
                }
            }
        }

        if (!$targetUser) {
            throw new Exception("пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅ email $email пїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅ");
        }

        // пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
        $currentSettings = json_decode($targetInbound['settings'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("пїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ: " . json_last_error_msg());
        }

        // пїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅ clients пїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅ
        $clientToUpdate = null;
        //  print_r($currentSettings); die;
        if (isset($currentSettings['clients'])) {
            foreach ($currentSettings['clients'] as &$client) {
                if ($client['id'] == $targetUser['uuid']) {
                    $client['enable'] = $enabled;
                    $clientToUpdate = $client;
                    break;
                }
            }
        }

        //die;

        if (!$clientToUpdate) {
            throw new Exception("пїЅпїЅпїЅпїЅпїЅпїЅ пїЅ uuid {$targetUser['uuid']} пїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅ пїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ");
        }

        // пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
        $dataToSend = [
            'id' => $inboundId,
            'settings' => json_encode(['clients' => [$clientToUpdate]])
        ];

        $url = $this->apiUrl . '/panel/api/inbounds/updateClient/' . $targetUser['uuid'];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($dataToSend),
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
            return ['success' => true, 'msg' => 'Empty response (likely successful update)'];
        }

        $result = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['success' => true, 'msg' => 'Response parsed as successful'];
        }

        return $result;
    }

    /**
     * пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
     *
     * @return array пїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
     * @throws Exception пїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
     */
    public function getUsers() {
        if (!$this->sessionId) {
            throw new Exception("пїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ");
        }

        $url = $this->apiUrl . '/panel/api/inbounds/list';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
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
            throw new Exception("пїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ JSON: " . json_last_error_msg());
        }

        if (isset($data['success']) && $data['success']) {
            $result = $data['obj'] ?? [];
            return $result;
        } else {
            $msg = $data['msg'] ?? 'пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ';
            throw new Exception("пїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ: $msg");
        }
    }

    /**
     * пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
     *
     * @param int $inboundId ID inbound
     * @param string $email Email пїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
     * @param string $username пїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
     * @param string $password пїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
     * @param array $options пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
     * @return array пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ, пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅ URL пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
     * @throws Exception пїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅ
     */
    public function addUser($inboundId, $email, $username, $password, $options = []) {
        // пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
        $originalApiUrl = $this->apiUrl;

        try {
            // пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
            $this->login($username, $password);

            if ($inboundId === null) {
                $inboundId = $this->findInboundIdByProtocol('vless');
            }

            if (!$inboundId) {
                throw new Exception('Inbound ID not found for VLESS');
            }

            // пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ UUID пїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅ
            $clientUuid = $this->generateUUID();

            // пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
            $defaults = [
                'flow' => 'xtls-rprx-vision',
                'limitIp' => 0,
                'totalGB' => 0,
                'expiryTime' => 0,
                'comment' => '',
            ];

            // пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
            $params = array_merge($defaults, $options);

            // пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
            $creationResult = $this->createUser([
                'id' => $inboundId, // ID inbound
                'settings' => json_encode([
                    'clients' => [
                        [
                            'email' => $email, // Email пїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
                            'id' => $clientUuid, // UUID пїЅпїЅпїЅпїЅпїЅпїЅпїЅ
                            'flow' => $params['flow'],
                            'limitIp' => $params['limitIp'],
                            'totalGB' => $params['totalGB'],
                            'expiryTime' => $params['expiryTime'],
                            'enable' => true,
                            'comment' => $params['comment'],
                        ]
                    ]
                ])
            ]);

            // пїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅ
            $userInfo = $this->findUserByEmail($email);
            if ($userInfo) {
                // пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ Reality пїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅ
                $realityParams = $this->extractRealityParams($userInfo['inbound']['streamSettings'] ?? '');

                // пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ flow пїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅ
                $flow = $userInfo['client']['flow'] ?? '';

                // пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ spx пїЅ URL-пїЅпїЅпїЅпїЅпїЅпїЅ
                $encodedSpx = urlencode($realityParams['spx']);

                // пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ IP-пїЅпїЅпїЅпїЅпїЅ пїЅпїЅ api URL, пїЅпїЅпїЅпїЅ serverIp пїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
                $serverIp = $this->serverIp ?: parse_url($this->apiUrl, PHP_URL_HOST);

                // пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ URL пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ, пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅ
                $connectionUrl = "vless://{$clientUuid}@{$serverIp}:{$userInfo['inbound']['port']}?type=tcp&encryption=none&security=reality&pbk={$realityParams['pbk']}&fp={$realityParams['fp']}&sni={$realityParams['sni']}&sid={$realityParams['sid']}&spx={$encodedSpx}&flow={$flow}#vless-{$email}";
            } else {
                // пїЅпїЅпїЅпїЅ пїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅ, пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅ
                $connectionUrl = $this->generateConnectionUrl($clientUuid, $email);
            }

            // пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅ URL пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
            return [
                'creation_result' => $creationResult,
                'connection_url' => $connectionUrl,
                'client_uuid' => $clientUuid,
                'client_email' => $email
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ URL пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅ email, пїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
     */
    public function getUserConnectionUrlByEmail($email, $username, $password): ?string
    {
        $this->login($username, $password);

        $userInfo = $this->findUserByEmail($email);
        if (!$userInfo) {
            return null;
        }

        $clientUuid = $userInfo['client']['id'] ?? null;
        if (!$clientUuid) {
            return null;
        }

        $realityParams = $this->extractRealityParams($userInfo['inbound']['streamSettings'] ?? '');
        $flow = $userInfo['client']['flow'] ?? '';
        $encodedSpx = urlencode($realityParams['spx']);
        $serverIp = $this->serverIp ?: parse_url($this->apiUrl, PHP_URL_HOST);

        return "vless://{$clientUuid}@{$serverIp}:{$userInfo['inbound']['port']}?type=tcp&encryption=none&security=reality&pbk={$realityParams['pbk']}&fp={$realityParams['fp']}&sni={$realityParams['sni']}&sid={$realityParams['sid']}&spx={$encodedSpx}&flow={$flow}#vless-{$email}";
    }

    /**
     * пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ email (max + 1) пїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅ
     */
    public function getNextNumericEmail($username, $password): string
    {
        $this->login($username, $password);
        $inbounds = $this->getUsers();
        $max = 0;

        foreach ($inbounds as $inbound) {
            if (isset($inbound['clientStats']) && is_array($inbound['clientStats'])) {
                foreach ($inbound['clientStats'] as $client) {
                    $max = $this->trackMaxNumericEmail($client['email'] ?? null, $max);
                }
            }

            if (!empty($inbound['settings'])) {
                $settings = json_decode($inbound['settings'], true);
                if (json_last_error() === JSON_ERROR_NONE && isset($settings['clients'])) {
                    foreach ($settings['clients'] as $client) {
                        $max = $this->trackMaxNumericEmail($client['email'] ?? null, $max);
                    }
                }
            }
        }

        return (string) ($max + 1);
    }

    /**
     * Return next numeric email that ends with server id (suffix).
     */
    public function getNextNumericEmailForServer($username, $password, int $serverId): string
    {
        $this->login($username, $password);
        $inbounds = $this->getUsers();

        $suffix = (string) $serverId;
        $suffixLen = strlen($suffix);
        $step = (int) pow(10, $suffixLen);
        $max = 0;

        foreach ($inbounds as $inbound) {
            if (isset($inbound['clientStats']) && is_array($inbound['clientStats'])) {
                foreach ($inbound['clientStats'] as $client) {
                    $max = $this->trackMaxNumericEmailBySuffix($client['email'] ?? null, $suffix, $max);
                }
            }

            if (!empty($inbound['settings'])) {
                $settings = json_decode($inbound['settings'], true);
                if (json_last_error() === JSON_ERROR_NONE && isset($settings['clients'])) {
                    foreach ($settings['clients'] as $client) {
                        $max = $this->trackMaxNumericEmailBySuffix($client['email'] ?? null, $suffix, $max);
                    }
                }
            }
        }

        if ($max === 0) {
            return $suffix;
        }

        return (string) ($max + $step);
    }

    /**
     * пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ URL пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
     *
     * @param string $clientUuid UUID пїЅпїЅпїЅпїЅпїЅпїЅпїЅ
     * @param string $clientEmail Email пїЅпїЅпїЅпїЅпїЅпїЅпїЅ
     * @return string URL пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
     */
    public function generateConnectionUrl($clientUuid, $clientEmail) {
        $url = "vless://{$clientUuid}@{$this->serverIp}:{$this->port}?type=tcp&encryption=none&security=reality&pbk={$this->pbk}&fp={$this->fp}&sni={$this->sni}&sid={$this->sid}&spx={$this->spx}&flow={$this->flow}#vless-{$clientEmail}";
        return $url;
    }

    /**
     * пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ UUID
     *
     * @return string пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ UUID
     */
    private function generateUUID() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    /**
     * пїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅ email
     *
     * @param string $identifier Email пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
     * @return array|null пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅ null, пїЅпїЅпїЅпїЅ пїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅ
     */
    private function findUserByEmail($identifier) {
        $inbounds = $this->getUsers();

        foreach ($inbounds as $inbound) {
            if (!empty($inbound['settings'])) {
                $settings = json_decode($inbound['settings'], true);
                if (!empty($settings['clients'])) {
                    foreach ($settings['clients'] as $client) {
                        if (isset($client['email']) && $client['email'] === $identifier) {
                            return [
                                'client' => $client,
                                'inbound' => $inbound,
                                'inboundId' => $inbound['id'],
                                'allSettings' => $settings
                            ];
                        }
                    }
                }
            }
        }
        return null;
    }

    /**
     * пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ Reality пїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅ
     *
     * @param string $streamSettings пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅ
     * @return array пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ Reality
     */
    private function extractRealityParams($streamSettings) {
        $params = [
            'pbk' => '',
            'fp' => '',
            'sni' => '',
            'sid' => '',
            'spx' => '/',
            'flow' => ''
        ];

        if (!empty($streamSettings)) {
            $settings = json_decode($streamSettings, true);
            if (isset($settings['realitySettings']['settings'])) {
                $realitySettings = $settings['realitySettings']['settings'];
                $params['pbk'] = $realitySettings['publicKey'] ?? '';
                $params['fp'] = $realitySettings['fingerprint'] ?? '';

                // пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ SNI: пїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅ serverName, пїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅ - пїЅпїЅ target
                $params['sni'] = $realitySettings['serverName'] ?? '';
                if (empty($params['sni']) && isset($settings['realitySettings']['target'])) {
                    // пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅ пїЅпїЅ target, пїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅ
                    $target = $settings['realitySettings']['target'];
                    $parsedTarget = parse_url($target);
                    $params['sni'] = $parsedTarget['host'] ?? $target;
                }

                $params['spx'] = $realitySettings['spiderX'] ?? '/';

                // пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ SID пїЅпїЅ shortIds
                if (isset($settings['realitySettings']['shortIds']) && is_array($settings['realitySettings']['shortIds'])) {
                    $params['sid'] = $settings['realitySettings']['shortIds'][0] ?? '';
                }
            }
        }

        return $params;
    }

    private function trackMaxNumericEmail($value, int $currentMax): int
    {
        if (!is_string($value)) {
            return $currentMax;
        }

        if (preg_match('/^\d+$/', $value)) {
            $num = (int) $value;
            return $num > $currentMax ? $num : $currentMax;
        }

        return $currentMax;
    }

    private function trackMaxNumericEmailBySuffix($value, string $suffix, int $currentMax): int
    {
        if (!is_string($value) || !ctype_digit($value)) {
            return $currentMax;
        }

        if (!str_ends_with($value, $suffix)) {
            return $currentMax;
        }

        $num = (int) $value;
        return $num > $currentMax ? $num : $currentMax;
    }

    private function findInboundIdByProtocol(string $protocol): ?int
    {
        $inbounds = $this->getUsers();
        foreach ($inbounds as $inbound) {
            if (isset($inbound['protocol']) && $inbound['protocol'] === $protocol) {
                return (int) $inbound['id'];
            }
        }

        return isset($inbounds[0]['id']) ? (int) $inbounds[0]['id'] : null;
    }
}



// пїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ:
//$userManager = new UserManagerVless('https://79.110.227.174:51406/6PvzVdSpu9xEmI4');

// // пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
// $result = $userManager->addUser(
//     1,  // ID inbound
//     'vvvvv999',  // Email пїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
//     'bQ6nY8OwUA',  // пїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
//     'rL82RaoZCu',  // пїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
//     [  // пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ (пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ)
//         'limitIp' => 0,
//         'totalGB' => 0,  // 10GB
//         'expiryTime' => 0,  // пїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅ
//         'comment' => 'пїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ',
//         'flow' => 'xtls-rprx-vision'
//     ]
// );
//
//echo "URL пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ: " . $result['connection_url'] . "\n";

//// // пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
//$result = $userManager->enableUser('57', 'dfsgw54JJijoi', 'JUJHG65fghGgh');
//echo "пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅ: " . json_encode($result) . "\n";
//
//// // пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ
//$result = $userManager->disableUser('57', 'dfsgw54JJijoi', 'JUJHG65fghGgh');
//echo "пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ пїЅпїЅпїЅпїЅпїЅпїЅпїЅпїЅ: " . json_encode($result) . "\n";

