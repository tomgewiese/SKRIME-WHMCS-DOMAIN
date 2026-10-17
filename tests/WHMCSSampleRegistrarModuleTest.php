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
