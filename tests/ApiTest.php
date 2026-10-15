<?php

use PHPUnit\Framework\TestCase;
use WHMCS\Module\Registrar\Skrime\Api;
use WHMCS\Module\Registrar\Skrime\ApiException;

class ApiTest extends TestCase
{
    public function testSuccessfulRequest()
    {
        $captured = [];
        $api = new Api('secret', 'https://skrime.eu/api', 30, function ($method, $url, $headers, $payload) use (&$captured) {
            $captured = compact('method', 'url', 'headers', 'payload');

            return [200, json_encode(['state' => 'success', 'response' => 'ok', 'data' => ['available' => true]])];
        });

        $this->assertSame(['available' => true], $api->call('POST', 'domain/check', ['domain' => 'example.de']));
        $this->assertSame('https://skrime.eu/api/domain/check', $captured['url']);
        $this->assertSame('Bearer secret', $captured['headers']['Authorization']);
        $this->assertSame('{"domain":"example.de"}', $captured['payload']);
    }

    public function testErrorResponses()
    {
        $api = new Api('secret', '', 30, function () {
            return [404, json_encode(['state' => 'error', 'response' => 'No product found'])];
        });

        try {
            $api->call('GET', 'domain/single', ['domain' => 'example.de']);
            $this->fail('Exception expected');
        } catch (ApiException $e) {
            $this->assertTrue($e->isNotFound());
            $this->assertSame('No product found', $e->getMessage());
        }

        $api = new Api('secret', '', 30, function () {
            return [200, json_encode(['state' => 'error', 'response' => 'no product found'])];
        });

        try {
            $api->call('GET', 'domain/single', ['domain' => 'example.de']);
            $this->fail('Exception expected');
        } catch (ApiException $e) {
            $this->assertTrue($e->isNotFound());
        }

        $api = new Api('secret', '', 30, function () {
            return [502, '<html>Bad Gateway</html>'];
        });

        try {
            $api->call('GET', 'domain/pricelist');
            $this->fail('Exception expected');
        } catch (ApiException $e) {
            $this->assertSame(502, $e->getHttpStatus());
        }
    }

    public function testMissingApiKey()
    {
        $this->expectException(ApiException::class);
        (new Api(''))->call('GET', 'domain/pricelist');
    }
}
