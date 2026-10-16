<?php

namespace WHMCS\Module\Registrar\Skrime;

class Api
{
    const DEFAULT_URL = 'https://skrime.eu/api/';

    private $apiKey;
    private $baseUrl;
    private $timeout;
    private $transport;
    private static $defaultTransport;

    public function __construct($apiKey, $baseUrl = self::DEFAULT_URL, $timeout = 30, $transport = null)
    {
        $this->apiKey = trim((string) $apiKey);
        $baseUrl = trim((string) $baseUrl);
        $this->baseUrl = rtrim($baseUrl === '' ? self::DEFAULT_URL : $baseUrl, '/') . '/';
        $this->timeout = max(5, (int) $timeout);
        $this->transport = $transport;
    }

    public static function fromSettings(Settings $settings)
    {
        return new self(
            $settings->string('apiKey'),
            $settings->string('apiUrl'),
            $settings->int('apiTimeout', 5, 300)
        );
    }

    public static function setDefaultTransport($transport)
    {
        self::$defaultTransport = $transport;
    }

    public function call($method, $endpoint, array $body = [])
    {
        $result = $this->request($method, $endpoint, $body);

        return $result['data'];
    }

    public function request($method, $endpoint, array $body = [])
    {
        if ($this->apiKey === '') {
            throw new ApiException('Kein SKRIME API Key hinterlegt');
        }

        $method = strtoupper($method);
        $url = $this->baseUrl . ltrim($endpoint, '/');
        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => 'SKRIME-WHMCS/' . Settings::VERSION,
        ];
        $payload = empty($body) ? '' : json_encode($body);
        if ($payload === false) {
            $error = json_last_error_msg();
            $this->log($method, $endpoint, $body, 'json_encode: ' . $error, null);
            throw new ApiException('Anfrage an die SKRIME API konnte nicht als JSON kodiert werden (' . $error . '). Bitte die Daten auf ungültige Zeichen prüfen.');
        }

        try {
            list($status, $raw) = $this->send($method, $url, $headers, $payload);
        } catch (ApiException $e) {
            $this->log($method, $endpoint, $body, $e->getMessage(), null);
            throw $e;
        } catch (\Throwable $e) {
            $this->log($method, $endpoint, $body, $e->getMessage(), null);
            throw new ApiException('Verbindung zur SKRIME API fehlgeschlagen: ' . $e->getMessage(), 0, null, $e);
        }

        $decoded = json_decode((string) $raw, true);
        $this->log($method, $endpoint, $body, $raw, $decoded);

        if (!is_array($decoded)) {
            throw new ApiException('Ungültige Antwort der SKRIME API (HTTP ' . $status . ')', $status, $raw);
        }

        $message = isset($decoded['response']) && is_scalar($decoded['response']) ? (string) $decoded['response'] : '';
        $state = isset($decoded['state']) ? strtolower((string) $decoded['state']) : '';

        if ($status >= 400 || $state !== 'success') {
            if ($message === '') {
                $message = 'SKRIME API Fehler (HTTP ' . $status . ')';
            }
            throw new ApiException($message, $status >= 400 ? $status : ($this->looksLikeNotFound($message) ? 404 : $status), $decoded);
        }

        return [
            'status' => $status,
            'message' => $message,
            'data' => array_key_exists('data', $decoded) ? $decoded['data'] : null,
        ];
    }

    private function looksLikeNotFound($message)
    {
        return strpos(strtolower($message), 'no product found') !== false;
    }

    private function send($method, $url, array $headers, $payload)
    {
        $transport = $this->transport ?: self::$defaultTransport;
        if (is_callable($transport)) {
            return call_user_func($transport, $method, $url, $headers, $payload, $this->timeout);
        }

        if (class_exists('\GuzzleHttp\Client') && method_exists('\GuzzleHttp\Client', 'request')) {
            $client = new \GuzzleHttp\Client([
                'timeout' => $this->timeout,
                'connect_timeout' => min(15, $this->timeout),
            ]);
            $options = [
                'headers' => $headers,
                'http_errors' => false,
            ];
            if ($payload !== '') {
                $options['body'] = $payload;
            }
            $response = $client->request($method, $url, $options);

            return [$response->getStatusCode(), (string) $response->getBody()];
        }

        $curlHeaders = [];
        foreach ($headers as $name => $value) {
            $curlHeaders[] = $name . ': ' . $value;
        }
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(15, $this->timeout));
        if ($payload !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new ApiException('Verbindung zur SKRIME API fehlgeschlagen: ' . $error);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$status, $raw];
    }

    private function log($method, $endpoint, $request, $response, $processed)
    {
        if (!function_exists('logModuleCall')) {
            return;
        }
        $secrets = [$this->apiKey];
        if (is_array($request) && isset($request['authCode']) && is_scalar($request['authCode'])) {
            $secrets[] = (string) $request['authCode'];
        }
        if (is_array($processed) && isset($processed['data']['authcode']) && is_scalar($processed['data']['authcode'])) {
            $secrets[] = (string) $processed['data']['authcode'];
        }
        $mask = [];
        foreach ($secrets as $secret) {
            if ($secret === '') {
                continue;
            }
            $mask[] = $secret;
            $encoded = json_encode($secret);
            if (is_string($encoded) && strlen($encoded) > 2) {
                $mask[] = substr($encoded, 1, -1);
            }
        }
        logModuleCall(Settings::MODULE, $method . ' ' . $endpoint, $request, $response, $processed, array_values(array_unique($mask)));
    }
}
