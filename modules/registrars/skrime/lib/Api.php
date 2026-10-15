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

        if (class_exists('\GuzzleHttp\Client')) {
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
        logModuleCall(Settings::MODULE, $method . ' ' . $endpoint, $request, $response, $processed, [$this->apiKey]);
    }
}
