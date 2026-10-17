<?php

use PHPUnit\Framework\TestCase;
use WHMCS\Module\Registrar\Skrime\Helper;
use WHMCS\Module\Registrar\Skrime\Pricelist;
use WHMCS\Module\Registrar\Skrime\Settings;

class HelperTest extends TestCase
{
    public function testStreetAndNumber()
    {
        $this->assertSame(['Musterstraße', '1a'], Helper::extractStreetAndNumber('Musterstraße 1a'));
        $this->assertSame(['Hauptweg', ''], Helper::extractStreetAndNumber('Hauptweg'));
        $this->assertSame(['Main Street', '123'], Helper::extractStreetAndNumber('123 Main Street'));
        $this->assertSame(['Straße des 17. Juni', '135'], Helper::extractStreetAndNumber('Straße des 17. Juni 135'));
        $this->assertSame(['Am Markt', '3-5'], Helper::extractStreetAndNumber('Am Markt 3-5'));
        $this->assertSame(['Mariahilfer Straße', '45/3/12'], Helper::extractStreetAndNumber('Mariahilfer Straße 45/3/12'));
        $this->assertSame(['Musterstr.', '12, Whg. 3'], Helper::extractStreetAndNumber('Musterstr. 12, Whg. 3'));
        $this->assertSame(['S Wacker Dr', '233'], Helper::extractStreetAndNumber('233 S Wacker Dr'));
        $this->assertSame(['Am 1. Mai', '5'], Helper::extractStreetAndNumber('Am 1. Mai 5'));
        $this->assertSame(['Musterstraße', '1a'], Helper::extractStreetAndNumber('Musterstraße1a'));
    }

    public function testContactFromDetailsKeepsStreetWhenNumberGiven()
    {
        $contact = Helper::contactFromDetails([
            'First Name' => 'Erika',
            'Last Name' => 'Muster',
            'Company Name' => 'Firma (Test)',
            'Address 1' => 'Straße des 17. Juni',
            'Address 2' => '135',
            'Country' => 'de',
        ], []);
        $this->assertSame('Straße des 17. Juni', $contact['street']);
        $this->assertSame('135', $contact['number']);
        $this->assertSame('Firma (Test)', $contact['company']);
        $this->assertSame('DE', $contact['country']);

        $contact = Helper::contactFromDetails(['Address 1' => 'Hauptstraße 5b', 'Address 2' => ''], []);
        $this->assertSame('Hauptstraße', $contact['street']);
        $this->assertSame('5b', $contact['number']);
        $this->assertArrayNotHasKey('company', $contact);
    }

    public function testOrderNameserversAreFilledWithDefaults()
    {
        $settings = new Settings(['defaultNameservers' => 'ns1.default.tld, ns2.default.tld']);
        $this->assertSame(['ns.custom.tld', 'ns1.default.tld'], Helper::orderNameservers(['ns1' => 'NS.custom.tld', 'ns2' => ''], $settings));

        $settings = new Settings(['defaultNameservers' => 'ns1.default.tld,ns2.default.tld', 'forceDefaultNameservers' => 'on']);
        $this->assertSame(['ns1.default.tld', 'ns2.default.tld'], Helper::orderNameservers(['ns1' => 'a.tld', 'ns2' => 'b.tld'], $settings));
    }

    public function testSplitDomain()
    {
        $this->assertSame(['example', 'co.uk'], Helper::splitDomain('example.co.uk', ['uk', 'co.uk']));
        $this->assertSame(['example', 'de'], Helper::splitDomain('example.de'));
    }

    public function testProductHelpers()
    {
        $product = ['productInfo' => ['domain' => 'Example.DE'], 'price' => '7.00', 'duration' => '24', 'priceBeforeDiscount' => '8.00', 'state' => 'active'];
        $this->assertSame('example.de', Helper::productDomain($product));
        $this->assertEqualsWithDelta(7.0, Helper::productCost($product), 0.0001);
        $this->assertEqualsWithDelta(8.0, Helper::productCost($product, 'priceBeforeDiscount'), 0.0001);
        $this->assertNull(Helper::productCost(['price' => '0.00']));
        $this->assertSame([$product], Helper::productList([$product, ['foo' => 'bar']]));
        $this->assertSame('active', Helper::productStatus($product));
        $this->assertSame('cancelled', Helper::productStatus(['state' => 'deleted']));
        $this->assertSame('expired', Helper::productStatus(['state' => 'expired']));
    }

    public function testPrivacyPayload()
    {
        $settings = new Settings(['privacyFields' => 'email,name']);
        $payload = Helper::privacyPayload(true, $settings);
        $this->assertTrue($payload['email']);
        $this->assertTrue($payload['name']);
        $this->assertFalse($payload['city']);
        $this->assertSame(array_fill_keys(Helper::PRIVACY_FIELDS, false), Helper::privacyPayload(false, $settings));
    }

    public function testPricelistOfferHandling()
    {
        $list = Pricelist::normalize([
            ['tld' => '.CO.UK', 'create' => '1.00', 'renew' => '6.00', 'transfer' => '6.00', 'restore' => '30.00', 'offer' => true, 'offerTypes' => ['create']],
        ]);
        $entry = $list['co.uk'];

        $costs = Pricelist::effectiveCosts($entry, new Settings(['offerMode' => 'apply']));
        $this->assertEqualsWithDelta(1.0, $costs['register'], 0.0001);

        $costs = Pricelist::effectiveCosts($entry, new Settings(['offerMode' => 'regular']));
        $this->assertEqualsWithDelta(6.0, $costs['register'], 0.0001);

        $costs = Pricelist::effectiveCosts($entry, new Settings(['offerMode' => 'skip']));
        $this->assertNull($costs['register']);
        $this->assertEqualsWithDelta(6.0, $costs['renew'], 0.0001);
    }
}
