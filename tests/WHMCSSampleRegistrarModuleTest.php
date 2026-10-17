<?php

use PHPUnit\Framework\TestCase;

class WHMCSSampleRegistrarModuleTest extends TestCase
{
    public function testCoreModuleFunctionsExist()
    {
        $functions = [
            'MetaData',
            'getConfigArray',
            'config_validate',
            'RegisterDomain',
            'TransferDomain',
            'RenewDomain',
            'GetNameservers',
            'SaveNameservers',
            'GetContactDetails',
            'SaveContactDetails',
            'CheckAvailability',
            'GetDNS',
            'SaveDNS',
            'IDProtectToggle',
            'GetEPPCode',
            'Sync',
            'TransferSync',
            'GetTldPricing',
            'AdminCustomButtonArray',
            'SyncDomainPrice',
            'SyncAllDomainPrices',
            'SyncTldPrices',
            'ClientAreaCustomButtonArray',
            'dnssec',
            'dnszone',
        ];

        foreach ($functions as $function) {
            $this->assertTrue(function_exists('skrime_' . $function), 'skrime_' . $function);
        }
    }

    public function testAdminButtonsPointToExistingFunctions()
    {
        foreach (skrime_AdminCustomButtonArray() as $function) {
            $this->assertTrue(function_exists('skrime_' . $function), 'skrime_' . $function);
        }
    }

    public function testDnsRecordParsing()
    {
        $this->assertSame('10 5 5060 sip.example.com', skrime_dnsRecord('_sip._tcp', 'SRV', '5 5060 sip.example.com', '', false)[0]['data']);
        $this->assertSame('10 mx.example.com', skrime_dnsRecord('@', 'MX', '10 mx.example.com', '10', false)[0]['data']);
        $this->assertNotNull(skrime_dnsRecord('@', 'MX', '10 mx.example.com', '20', false)[1]);
        $this->assertNotNull(skrime_dnsRecord('@', 'A', '999.1.1.1', '', false)[1]);
        $this->assertSame(['name' => 'www', 'type' => 'AAAA', 'data' => '2001:db8::1'], skrime_dnsRecord('www', 'AAAA', '2001:db8::1', '', false)[0]);
        $this->assertSame([['name' => '@', 'type' => 'MX', 'data' => 'mx.example.com', 'priority' => '10']], skrime_dnsRows([['name' => '@', 'type' => 'MX', 'data' => '10 mx.example.com']], false));
    }

    public function testConfigArrayDefaultsAreValid()
    {
        $config = skrime_getConfigArray();
        $this->assertSame('SKRIME', $config['FriendlyName']['Value']);
        $this->assertArrayHasKey('apiKey', $config);

        foreach ($config as $key => $definition) {
            if (isset($definition['Type']) && $definition['Type'] === 'dropdown') {
                $this->assertArrayHasKey($definition['Default'], $definition['Options'], $key);
            }
        }
    }
}
