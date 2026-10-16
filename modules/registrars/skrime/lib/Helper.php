<?php

namespace WHMCS\Module\Registrar\Skrime;

class Helper
{
    const PRIVACY_FIELDS = ['organization', 'name', 'email', 'voice', 'addressLine', 'city', 'postalCode'];

    public static function domainName(array $params)
    {
        $sld = isset($params['sld']) ? trim((string) $params['sld']) : '';
        $tld = isset($params['tld']) ? ltrim(trim((string) $params['tld']), '.') : '';
        if ($sld === '' && isset($params['domainname'])) {
            return strtolower(trim((string) $params['domainname']));
        }

        return strtolower($sld . '.' . $tld);
    }

    public static function extractStreetAndNumber($address)
    {
        $address = trim((string) $address);
        if (preg_match('/^(\d+[a-zA-Z]?(?:\s?[-\/]\s?\d+[a-zA-Z]?)?),?\s+(\D.*)$/u', $address, $matches)) {
            return [trim($matches[2]), trim($matches[1])];
        }
        if (preg_match('/^(.+?)\s+(\d+(?![\d.]).*)$/u', $address, $matches)) {
            return [trim($matches[1]), trim($matches[2])];
        }
        if (preg_match('/^(.+?)\s?(\d+.*)$/u', $address, $matches)) {
            return [trim($matches[1]), trim($matches[2])];
        }

        return [$address, ''];
    }

    public static function cleanOrganizationName($name)
    {
        return preg_replace('/[()ÄÖÜ]/u', '', (string) $name);
    }

    public static function collectNameservers(array $params)
    {
        $nameservers = [];
        for ($i = 1; $i <= 6; $i++) {
            $key = 'ns' . $i;
            if (!isset($params[$key])) {
                continue;
            }
            $ns = strtolower(trim((string) $params[$key]));
            if ($ns !== '' && !in_array($ns, $nameservers, true)) {
                $nameservers[] = $ns;
            }
        }

        return $nameservers;
    }

    public static function orderNameservers(array $params, Settings $settings)
    {
        $defaults = $settings->csv('defaultNameservers');
        $nameservers = $settings->bool('forceDefaultNameservers') && count($defaults) >= 2
            ? $defaults
            : self::collectNameservers($params);

        foreach ($defaults as $ns) {
            if (count($nameservers) >= 2) {
                break;
            }
            if (!in_array($ns, $nameservers, true)) {
                $nameservers[] = $ns;
            }
        }

        return array_slice($nameservers, 0, 6);
    }

    public static function contactFromParams(array $params, Settings $settings, $clean = true)
    {
        $address1 = isset($params['address1']) ? $params['address1'] : '';
        $address2 = isset($params['address2']) ? trim((string) $params['address2']) : '';
        list($street, $number) = self::extractStreetAndNumber($address1);
        if ($number === '' && $address2 !== '' && preg_match('/^\d/', $address2)) {
            $number = $address2;
        } elseif ($address2 !== '' && $number !== '') {
            $number .= ', ' . $address2;
        } elseif ($address2 !== '') {
            $street = trim($street . ', ' . $address2, ', ');
        }

        $company = isset($params['companyname']) ? (string) $params['companyname'] : '';
        if ($clean && $settings->bool('cleanCompanyName')) {
            $company = self::cleanOrganizationName($company);
        }

        return [
            'company' => trim($company),
            'firstname' => self::value($params, 'firstname'),
            'lastname' => self::value($params, 'lastname'),
            'street' => $street,
            'number' => $number,
            'postcode' => self::value($params, 'postcode'),
            'city' => self::value($params, 'city'),
            'state' => self::value($params, 'state'),
            'country' => strtoupper(self::value($params, 'countrycode')),
            'email' => self::value($params, 'email'),
            'phone' => self::value($params, 'fullphonenumber'),
        ];
    }

    public static function contactFromDetails(array $details, array $params)
    {
        $street = trim((string) self::pick($details, ['Address 1', 'Street'], ''));
        $number = trim((string) self::pick($details, ['Address 2', 'House Number'], ''));
        if ($number === '') {
            list($street, $number) = self::extractStreetAndNumber($street);
        }

        $company = (string) self::pick($details, ['Company Name', 'Organisation Name', 'Organization Name'], '');

        $phone = trim((string) self::pick($details, ['Phone Number', 'Phone'], ''));
        if ($phone !== '' && strpos($phone, '+') !== 0 && isset($params['phonecc']) && $params['phonecc'] !== '') {
            $phone = '+' . ltrim((string) $params['phonecc'], '+') . '.' . ltrim(preg_replace('/[^\d]/', '', $phone), '0');
        }

        $contact = [
            'firstname' => trim((string) self::pick($details, ['First Name'], '')),
            'lastname' => trim((string) self::pick($details, ['Last Name'], '')),
            'street' => $street,
            'number' => $number,
            'postcode' => trim((string) self::pick($details, ['Postcode', 'ZIP Code', 'Zip Code'], '')),
            'city' => trim((string) self::pick($details, ['City'], '')),
            'state' => trim((string) self::pick($details, ['State', 'Region'], '')),
            'country' => strtoupper(trim((string) self::pick($details, ['Country', 'Country Code'], ''))),
            'email' => trim((string) self::pick($details, ['Email Address', 'Email'], '')),
            'phone' => $phone,
        ];
        if (trim($company) !== '') {
            $contact = ['company' => trim($company)] + $contact;
        }

        return $contact;
    }

    public static function privacyPayload($enable, Settings $settings)
    {
        $selected = [];
        foreach ($settings->csv('privacyFields', false) as $field) {
            foreach (self::PRIVACY_FIELDS as $known) {
                if (strtolower($known) === strtolower($field)) {
                    $selected[] = $known;
                }
            }
        }
        if (empty($selected)) {
            $selected = self::PRIVACY_FIELDS;
        }

        $privacy = [];
        foreach (self::PRIVACY_FIELDS as $field) {
            $privacy[$field] = $enable && in_array($field, $selected, true);
        }

        return $privacy;
    }

    public static function splitDomain($domain, array $knownTlds = [])
    {
        $domain = strtolower(trim((string) $domain, " .\t\n\r"));
        $best = '';
        foreach ($knownTlds as $tld) {
            $tld = strtolower(ltrim((string) $tld, '.'));
            if ($tld === '' || strlen($tld) <= strlen($best)) {
                continue;
            }
            $suffix = '.' . $tld;
            if (strlen($domain) > strlen($suffix) && substr($domain, -strlen($suffix)) === $suffix) {
                $best = $tld;
            }
        }
        if ($best !== '') {
            return [substr($domain, 0, -strlen($best) - 1), $best];
        }
        $position = strpos($domain, '.');
        if ($position === false) {
            return [$domain, ''];
        }

        return [substr($domain, 0, $position), substr($domain, $position + 1)];
    }

    public static function productDomain($product)
    {
        if (!is_array($product)) {
            return '';
        }
        foreach ([['productInfo', 'domain'], ['domain'], ['name'], ['productInfo', 'name']] as $path) {
            $value = $product;
            foreach ($path as $segment) {
                if (!is_array($value) || !array_key_exists($segment, $value)) {
                    $value = null;
                    break;
                }
                $value = $value[$segment];
            }
            if (is_string($value) && $value !== '') {
                return strtolower(trim($value));
            }
        }

        return '';
    }

    public static function productList($data)
    {
        if (!is_array($data)) {
            return [];
        }
        foreach (['products', 'domains', 'items', 'data'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                return self::productList($data[$key]);
            }
        }
        if (self::productDomain($data) !== '') {
            return [$data];
        }

        $list = [];
        foreach ($data as $item) {
            if (is_array($item) && self::productDomain($item) !== '') {
                $list[] = $item;
            }
        }

        return $list;
    }

    public static function productYearlyCost($product, $field = 'price')
    {
        if (!is_array($product)) {
            return null;
        }
        $price = null;
        if ($field === 'priceBeforeDiscount' && isset($product['priceBeforeDiscount']) && is_numeric($product['priceBeforeDiscount'])
            && (float) $product['priceBeforeDiscount'] > 0) {
            $price = (float) $product['priceBeforeDiscount'];
        } elseif (isset($product['price']) && is_numeric($product['price'])) {
            $price = (float) $product['price'];
        }
        if ($price === null || $price <= 0) {
            return null;
        }

        $months = isset($product['duration']) && is_numeric($product['duration']) ? (float) $product['duration'] : 0.0;
        if ($months <= 0 && isset($product['days']) && is_numeric($product['days']) && (float) $product['days'] > 0) {
            $months = (float) $product['days'] / 365 * 12;
        }
        if ($months <= 0) {
            $months = 12.0;
        }

        return round($price * 12 / $months, 4);
    }

    public static function productStatus($product)
    {
        $state = is_array($product) && isset($product['state']) && is_scalar($product['state'])
            ? strtolower(trim((string) $product['state']))
            : '';
        $map = [
            'expired' => 'expired',
            'deleted' => 'cancelled',
            'cancelled' => 'cancelled',
            'canceled' => 'cancelled',
            'terminated' => 'cancelled',
            'transferred' => 'transferred',
            'transferred_away' => 'transferred',
            'transferredaway' => 'transferred',
            'transfer_out' => 'transferred',
            'outgoing' => 'transferred',
            'pending' => 'pending',
            'pending_transfer' => 'pending',
            'transfer' => 'pending',
            'transferring' => 'pending',
            'failed' => 'failed',
            'rejected' => 'failed',
        ];

        return isset($map[$state]) ? $map[$state] : 'active';
    }

    public static function formatAmount($amount)
    {
        return number_format((float) $amount, 2, ',', '.');
    }

    public static function toDate($value)
    {
        if (!is_string($value) || trim($value) === '' || strpos($value, '0000-00-00') === 0) {
            return null;
        }
        $time = strtotime($value);

        return $time ? date('Y-m-d', $time) : null;
    }

    private static function value(array $params, $key)
    {
        return isset($params[$key]) ? trim((string) $params[$key]) : '';
    }

    private static function pick(array $data, array $keys, $default)
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && $data[$key] !== '') {
                return $data[$key];
            }
        }

        return $default;
    }
}
