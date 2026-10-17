<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

require_once __DIR__ . '/lib/autoload.php';

use WHMCS\Database\Capsule;
use WHMCS\Domains\DomainLookup\ResultsList;
use WHMCS\Domains\DomainLookup\SearchResult;
use WHMCS\Domain\TopLevel\ImportItem;
use WHMCS\Module\Registrar\Skrime\Api;
use WHMCS\Module\Registrar\Skrime\ApiException;
use WHMCS\Module\Registrar\Skrime\DomainPriceSync;
use WHMCS\Module\Registrar\Skrime\Helper;
use WHMCS\Module\Registrar\Skrime\Pricelist;
use WHMCS\Module\Registrar\Skrime\Settings;
use WHMCS\Module\Registrar\Skrime\Storage;
use WHMCS\Module\Registrar\Skrime\TldPriceSync;

const SKRIME_API_DNS_TYPES = ['A', 'AAAA', 'CNAME', 'ALIAS', 'MX', 'SRV', 'TXT', 'CAA', 'PTR', 'TLSA', 'DS', 'DNSKEY'];
const SKRIME_WHMCS_DNS_TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT'];
const SKRIME_DNSSEC_ALGORITHMS = [
    3 => 'DSA/SHA1',
    5 => 'RSA/SHA-1',
    6 => 'DSA-NSEC3-SHA1',
    7 => 'RSASHA1-NSEC3-SHA1',
    8 => 'RSA/SHA-256',
    10 => 'RSA/SHA-512',
    12 => 'GOST R 34.10-2001',
    13 => 'ECDSA P-256/SHA-256',
    14 => 'ECDSA P-384/SHA-384',
    15 => 'Ed25519',
    16 => 'Ed448',
    17 => 'SM2/SM3',
    23 => 'ECC-GOST12',
];

function skrime_MetaData()
{
    return array(
        'DisplayName' => 'SKRIME',
        'APIVersion' => '1.1',
    );
}

function skrime_getConfigArray()
{
    return Settings::configArray();
}

function skrime_config_validate($params)
{
    $settings = Settings::fromParams($params);
    if (!$settings->bool('validateOnSave')) {
        return;
    }

    $exception = class_exists('WHMCS\Exception\Module\InvalidConfiguration')
        ? 'WHMCS\Exception\Module\InvalidConfiguration'
        : 'Exception';

    if ($settings->string('apiKey') === '') {
        throw new $exception('Bitte einen SKRIME API Key hinterlegen.');
    }

    try {
        Api::fromSettings($settings)->call('GET', 'domain/pricelist');
    } catch (\Throwable $e) {
        throw new $exception('Verbindung zur SKRIME API fehlgeschlagen: ' . $e->getMessage());
    }
}

function skrime_api(array $params)
{
    return Api::fromSettings(Settings::fromParams($params));
}

function skrime_error(\Throwable $e)
{
    return ['error' => $e->getMessage()];
}

function skrime_years(array $params)
{
    return max(1, isset($params['regperiod']) ? (int) $params['regperiod'] : 1);
}

function skrime_orderDomain(array $params, $authCode)
{
    $settings = Settings::fromParams($params);
    $domain = Helper::domainName($params);
    $years = skrime_years($params);
    $isTransfer = (string) $authCode !== '';
    if ($years > 1 && $isTransfer) {
        return ['error' => 'SKRIME transferiert Domains je Vorgang für 1 Jahr. Die gewählte Laufzeit von ' . $years . ' Jahren wird nicht unterstützt.'];
    }
    if ($years > 1 && $settings->choice('multiYearMode') !== 'renew') {
        return ['error' => 'SKRIME registriert Domains je Vorgang für 1 Jahr. Die gewählte Laufzeit von ' . $years . ' Jahren wird nicht unterstützt (Einstellung "Mehrjährige Laufzeiten").'];
    }
    $nameservers = Helper::orderNameservers($params, $settings);
    if (count($nameservers) < 2) {
        return ['error' => 'Es werden mindestens 2 Nameserver benötigt. Bitte Standard-Nameserver in den Registrar-Einstellungen hinterlegen.'];
    }

    $api = Api::fromSettings($settings);
    if (!$isTransfer && skrime_premiumBlocked($params, $settings)) {
        try {
            $check = $api->call('POST', 'domain/check', ['domain' => $domain]);
        } catch (\Throwable $e) {
            return skrime_error($e);
        }
        if (is_array($check) && !empty($check['premium'])) {
            return ['error' => 'Die Domain ' . $domain . ' ist eine Premium-Domain und wird gemäß Einstellung "Premium-Domains" nicht registriert.'];
        }
    }

    $postfields = [
        'domain' => $domain,
        'authCode' => (string) $authCode,
        'contact' => Helper::contactFromParams($params, $settings),
        'nameserver' => $nameservers,
        'tos' => true,
        'cancellation' => true,
    ];

    try {
        $product = $api->call('POST', 'domain/order', $postfields);
    } catch (\Throwable $e) {
        return skrime_error($e);
    }

    if ($settings->bool('idProtectionOnOrder') && !empty($params['idprotection'])) {
        try {
            $api->call('POST', 'domain/whois', [
                'domain' => $domain,
                'privacy' => Helper::privacyPayload(true, $settings),
            ]);
        } catch (\Throwable $e) {
            if (function_exists('logActivity')) {
                logActivity('SKRIME WHOIS-Privacy für ' . $domain . ' konnte nicht aktiviert werden: ' . $e->getMessage());
            }
        }
    }

    skrime_rememberOrder($params, $settings, $domain, $product);

    for ($year = 1; $year < $years; $year++) {
        try {
            $api->call('POST', 'product/renew', ['domain' => $domain]);
        } catch (\Throwable $e) {
            $message = 'Die Domain wurde für ' . $year . ' von ' . $years . ' Jahr(en) registriert, die weitere Verlängerung ist fehlgeschlagen: ' . $e->getMessage();
            if (function_exists('logActivity')) {
                logActivity('SKRIME ' . $domain . ': ' . $message);
            }
            skrime_storageSet(skrime_renewKey($params, $domain), json_encode(['requested' => $years, 'remaining' => $years - $year, 'at' => time()]));
            skrime_event($params, $domain, 'register_partial', 'Registrierung nur teilweise verlängert, "Verlängern" mit gleicher Laufzeit bucht nur die fehlenden ' . ($years - $year) . ' Jahr(e)', $year . ' von ' . $years . ' Jahren', 'fehlen ' . ($years - $year));
            break;
        }
    }

    return ['success' => true];
}

function skrime_event(array $params, $domain, $type, $message, $old, $new)
{
    if (empty($params['domainid'])) {
        return;
    }
    try {
        Storage::addEvent((int) $params['domainid'], $domain, $type, $message, $old, $new, true);
    } catch (\Throwable $e) {
        if (function_exists('logActivity')) {
            logActivity('SKRIME Hinweis für ' . $domain . ' konnte nicht gespeichert werden: ' . $e->getMessage());
        }
    }
}

function skrime_rememberOrder(array $params, Settings $settings, $domain, $product)
{
    if (!$settings->bool('domainSyncEnabled') || !is_array($product) || empty($params['domainid'])) {
        return;
    }
    try {
        $expire = isset($product['expireAt']) ? Helper::toDate($product['expireAt']) : null;
        Storage::saveDomainState((int) $params['domainid'], [
            'domain' => substr((string) $domain, 0, 191),
            'product_id' => isset($product['id']) && is_scalar($product['id']) ? substr((string) $product['id'], 0, 64) : '',
            'expire_at' => $expire !== null ? $expire . ' 00:00:00' : null,
        ]);
    } catch (\Throwable $e) {
        if (function_exists('logActivity')) {
            logActivity('SKRIME Produktdaten für ' . $domain . ' konnten nicht gespeichert werden: ' . $e->getMessage());
        }
    }
}

function skrime_RegisterDomain($params)
{
    return skrime_orderDomain($params, '');
}

function skrime_TransferDomain($params)
{
    $eppCode = isset($params['eppcode']) ? html_entity_decode((string) $params['eppcode'], ENT_QUOTES, 'UTF-8') : '';
    if (trim($eppCode) === '') {
        return ['error' => 'Für den Transfer wird ein Auth-Code benötigt.'];
    }

    return skrime_orderDomain($params, $eppCode);
}

function skrime_RenewDomain($params)
{
    $settings = Settings::fromParams($params);
    $years = skrime_years($params);
    if ($years > 1 && $settings->choice('multiYearMode') !== 'renew') {
        return ['error' => 'SKRIME verlängert Domains je Vorgang um 1 Jahr. Die gewählte Laufzeit von ' . $years . ' Jahren wird nicht unterstützt (Einstellung "Mehrjährige Laufzeiten").'];
    }

    $api = Api::fromSettings($settings);
    $domain = Helper::domainName($params);
    $key = skrime_renewKey($params, $domain);
    $todo = $years;
    $done = 0;
    $pending = skrime_storageGet($key);
    $pending = $pending === '' ? null : json_decode($pending, true);
    if (is_array($pending) && isset($pending['requested'], $pending['remaining'], $pending['at'])
        && (int) $pending['requested'] === $years && time() - (int) $pending['at'] < 30 * 86400
        && (int) $pending['remaining'] > 0 && (int) $pending['remaining'] < $years) {
        $todo = (int) $pending['remaining'];
        $done = $years - $todo;
    }

    for ($year = 0; $year < $todo; $year++) {
        try {
            $api->call('POST', 'product/renew', ['domain' => $domain]);
        } catch (\Throwable $e) {
            $booked = $done + $year;
            if ($booked === 0) {
                return skrime_error($e);
            }
            skrime_storageSet($key, json_encode(['requested' => $years, 'remaining' => $years - $booked, 'at' => time()]));
            skrime_event($params, $domain, 'renew_partial', 'Verlängerung unvollständig, erneuter Versuch bucht nur die fehlenden Jahre', $booked . ' von ' . $years . ' Jahren', 'fehlen ' . ($years - $booked));

            return ['error' => 'Die Domain wurde um ' . $booked . ' von ' . $years . ' Jahr(en) verlängert, die weitere Verlängerung ist fehlgeschlagen: ' . $e->getMessage() . ' Ein erneuter Versuch bucht nur die fehlenden Jahre.'];
        }
    }

    if ($done > 0 || is_array($pending)) {
        skrime_storageSet($key, '');
    }

    return ['success' => true];
}

function skrime_renewKey(array $params, $domain)
{
    return 'renew_pending_' . (!empty($params['domainid']) ? (int) $params['domainid'] : $domain);
}

function skrime_input($value)
{
    return is_string($value) ? html_entity_decode($value, ENT_QUOTES, 'UTF-8') : '';
}

function skrime_storageGet($key)
{
    try {
        return (string) Storage::get($key, '');
    } catch (\Throwable $e) {
        return '';
    }
}

function skrime_storageSet($key, $value)
{
    try {
        Storage::set($key, $value);
    } catch (\Throwable $e) {
        if (function_exists('logActivity')) {
            logActivity('SKRIME Status ' . $key . ' konnte nicht gespeichert werden: ' . $e->getMessage());
        }
    }
}

function skrime_GetNameservers($params)
{
    try {
        $data = skrime_api($params)->call('GET', 'domain/nameserver', [
            'domain' => Helper::domainName($params),
        ]);
        $nameservers = isset($data['nameserver']) && is_array($data['nameserver']) ? array_values($data['nameserver']) : [];
        $result = [];
        for ($i = 0; $i < 5; $i++) {
            $result['ns' . ($i + 1)] = isset($nameservers[$i]) ? (string) $nameservers[$i] : '';
        }

        return $result;
    } catch (\Throwable $e) {
        return skrime_error($e);
    }
}

function skrime_SaveNameservers($params)
{
    $nameservers = Helper::collectNameservers($params);
    if (count($nameservers) < 2) {
        return ['error' => 'Es müssen mindestens 2 Nameserver angegeben werden.'];
    }

    try {
        skrime_api($params)->call('POST', 'domain/nameserver', [
            'domain' => Helper::domainName($params),
            'nameserver' => array_slice($nameservers, 0, 6),
        ]);

        return ['success' => true];
    } catch (\Throwable $e) {
        return skrime_error($e);
    }
}

function skrime_GetContactDetails($params)
{
    try {
        $data = skrime_api($params)->call('GET', 'domain/contact', [
            'domain' => Helper::domainName($params),
        ]);
        $contact = isset($data['contact']) && is_array($data['contact']) ? $data['contact'] : [];
        $value = function ($key) use ($contact) {
            return isset($contact[$key]) && is_scalar($contact[$key]) ? (string) $contact[$key] : '';
        };

        return [
            'Registrant' => [
                'First Name' => $value('firstname'),
                'Last Name' => $value('lastname'),
                'Company Name' => $value('company'),
                'Email Address' => $value('email'),
                'Address 1' => $value('street'),
                'Address 2' => $value('number'),
                'City' => $value('city'),
                'State' => $value('state'),
                'Postcode' => $value('postcode'),
                'Country' => $value('country'),
                'Phone Number' => $value('phone'),
            ],
        ];
    } catch (\Throwable $e) {
        return skrime_error($e);
    }
}

function skrime_SaveContactDetails($params)
{
    $settings = Settings::fromParams($params);
    $details = isset($params['contactdetails']['Registrant']) && is_array($params['contactdetails']['Registrant'])
        ? $params['contactdetails']['Registrant']
        : null;
    if ($details === null) {
        $contact = Helper::contactFromParams($params, $settings, false);
        if ($contact['company'] === '') {
            unset($contact['company']);
        }
    } else {
        $contact = Helper::contactFromDetails($details, $params);
    }

    try {
        skrime_api($params)->call('POST', 'domain/contact', [
            'domain' => Helper::domainName($params),
            'contact' => $contact,
        ]);

        return ['success' => true];
    } catch (\Throwable $e) {
        return skrime_error($e);
    }
}

function skrime_IDProtectToggle($params)
{
    $settings = Settings::fromParams($params);
    try {
        Api::fromSettings($settings)->call('POST', 'domain/whois', [
            'domain' => Helper::domainName($params),
            'privacy' => Helper::privacyPayload(!empty($params['protectenable']), $settings),
        ]);

        return ['success' => true];
    } catch (\Throwable $e) {
        return skrime_error($e);
    }
}

function skrime_premiumBlocked(array $params, Settings $settings)
{
    return $settings->choice('premiumHandling') === 'unavailable'
        || (array_key_exists('premiumEnabled', $params) && empty($params['premiumEnabled']));
}

function skrime_searchStatus($name, $fallback)
{
    $constant = SearchResult::class . '::' . $name;

    return defined($constant) ? constant($constant) : constant(SearchResult::class . '::' . $fallback);
}

function skrime_CheckAvailability($params)
{
    $settings = Settings::fromParams($params);
    $searchTerm = isset($params['searchTerm']) ? (string) $params['searchTerm'] : '';
    $label = !empty($params['isIdnDomain']) && !empty($params['punyCodeSearchTerm'])
        ? (string) $params['punyCodeSearchTerm']
        : $searchTerm;
    $tlds = isset($params['tldsToInclude']) && is_array($params['tldsToInclude']) ? $params['tldsToInclude'] : [];

    $premiumBlocked = skrime_premiumBlocked($params, $settings);

    try {
        $api = Api::fromSettings($settings);
        $results = new ResultsList();
        $lastError = null;
        $checked = 0;
        $offline = false;

        foreach ($tlds as $tld) {
            $searchResult = new SearchResult($searchTerm, $tld);
            if ($offline) {
                $searchResult->setStatus(skrime_searchStatus('STATUS_UNKNOWN', 'STATUS_TLD_NOT_SUPPORTED'));
                $results->append($searchResult);
                continue;
            }
            try {
                $data = $api->call('POST', 'domain/check', [
                    'domain' => strtolower($label . '.' . ltrim((string) $tld, '.')),
                ]);
                $checked++;
                if (!empty($data['available'])) {
                    $status = !empty($data['premium']) && $premiumBlocked
                        ? SearchResult::STATUS_RESERVED
                        : SearchResult::STATUS_NOT_REGISTERED;
                } else {
                    $status = SearchResult::STATUS_REGISTERED;
                }
            } catch (ApiException $e) {
                $lastError = $e;
                $offline = $e->getHttpStatus() === 0;
                $status = skrime_searchStatus('STATUS_UNKNOWN', 'STATUS_TLD_NOT_SUPPORTED');
            }
            $searchResult->setStatus($status);
            $results->append($searchResult);
        }

        if ($checked === 0 && $lastError !== null) {
            return skrime_error($lastError);
        }

        return $results;
    } catch (\Throwable $e) {
        return skrime_error($e);
    }
}

function skrime_GetDNS($params)
{
    $settings = Settings::fromParams($params);
    $fieldMode = $settings->choice('dnsMxMode') === 'field';
    $hideUnsupported = $settings->bool('dnsKeepUnsupported');

    try {
        $data = Api::fromSettings($settings)->call('GET', 'domain/dns', [
            'domain' => Helper::domainName($params),
        ]);
        $records = [];
        foreach (isset($data['records']) && is_array($data['records']) ? $data['records'] : [] as $record) {
            if (!is_array($record)) {
                continue;
            }
            $type = strtoupper(isset($record['type']) ? (string) $record['type'] : '');
            if ($hideUnsupported && !in_array($type, SKRIME_WHMCS_DNS_TYPES, true)) {
                continue;
            }
            $address = isset($record['data']) ? (string) $record['data'] : '';
            $priority = '';
            if ($type === 'MX') {
                if ($fieldMode && isset($record['priority']) && $record['priority'] !== '') {
                    $priority = (string) $record['priority'];
                } elseif (preg_match('/^(\d+)\s+(.+)$/', trim($address), $matches)) {
                    $priority = $matches[1];
                    $address = $matches[2];
                }
            } elseif (isset($record['priority']) && $record['priority'] !== '') {
                $priority = (string) $record['priority'];
            }
            $records[] = [
                'hostname' => isset($record['name']) ? (string) $record['name'] : '@',
                'type' => $type,
                'address' => $address,
                'priority' => $priority,
            ];
        }

        return $records;
    } catch (\Throwable $e) {
        return skrime_error($e);
    }
}

function skrime_SaveDNS($params)
{
    $settings = Settings::fromParams($params);
    $fieldMode = $settings->choice('dnsMxMode') === 'field';
    $domain = Helper::domainName($params);

    $api = Api::fromSettings($settings);
    try {
        $current = $api->call('GET', 'domain/dns', ['domain' => $domain]);
    } catch (ApiException $e) {
        if (!$e->isNotFound()) {
            return skrime_error($e);
        }
        $current = [];
    } catch (\Throwable $e) {
        return skrime_error($e);
    }

    $protected = [];
    foreach (isset($current['records']) && is_array($current['records']) ? $current['records'] : [] as $record) {
        if (!is_array($record)) {
            continue;
        }
        $type = strtoupper(isset($record['type']) ? (string) $record['type'] : '');
        if (in_array($type, SKRIME_WHMCS_DNS_TYPES, true)) {
            continue;
        }
        $key = strtolower(isset($record['name']) ? (string) $record['name'] : '@') . '|' . trim(isset($record['data']) ? (string) $record['data'] : '');
        $protected[$key][] = $record;
    }

    $records = [];
    foreach (isset($params['dnsrecords']) && is_array($params['dnsrecords']) ? $params['dnsrecords'] : [] as $record) {
        $address = isset($record['address']) ? trim(skrime_input((string) $record['address'])) : '';
        if ($address === '') {
            continue;
        }
        $name = isset($record['hostname']) ? trim(skrime_input((string) $record['hostname'])) : '';
        $name = $name === '' ? '@' : $name;
        $key = strtolower($name) . '|' . $address;
        if (!$settings->bool('dnsKeepUnsupported') && !empty($protected[$key])) {
            $records[] = array_shift($protected[$key]);
            continue;
        }
        $type = strtoupper(isset($record['type']) ? trim((string) $record['type']) : '');
        if (!in_array($type, SKRIME_API_DNS_TYPES, true)) {
            return ['error' => 'Der Record-Typ ' . $type . ' wird von SKRIME nicht unterstützt.'];
        }
        $entry = [
            'name' => $name,
            'type' => $type,
            'data' => $address,
        ];
        $priority = isset($record['priority']) ? trim((string) $record['priority']) : '';
        if (in_array($type, ['MX', 'SRV'], true) && $priority !== '' && ctype_digit($priority)) {
            if ($fieldMode) {
                $entry['priority'] = (int) $priority;
            } elseif (!preg_match('/^\d+\s+/', $address)) {
                $entry['data'] = (int) $priority . ' ' . $address;
            }
        }
        $records[] = $entry;
    }

    if ($settings->bool('dnsKeepUnsupported')) {
        foreach ($protected as $remaining) {
            foreach ($remaining as $record) {
                $records[] = $record;
            }
        }
    }

    try {
        $api->call('POST', 'domain/dns', [
            'domain' => $domain,
            'records' => $records,
        ]);

        return ['success' => true];
    } catch (\Throwable $e) {
        return skrime_error($e);
    }
}

function skrime_GetEPPCode($params)
{
    try {
        $data = skrime_api($params)->call('GET', 'domain/authcode', [
            'domain' => Helper::domainName($params),
        ]);
        if (!empty($data['authcode'])) {
            return ['eppcode' => (string) $data['authcode']];
        }

        return ['success' => true];
    } catch (\Throwable $e) {
        return skrime_error($e);
    }
}

function skrime_GetAllDomains($params)
{
    try {
        return skrime_api($params)->call('GET', 'product/all', ['type' => 'domain']);
    } catch (\Throwable $e) {
        return skrime_error($e);
    }
}

function skrime_GetDomainPricelist($params)
{
    try {
        return skrime_api($params)->call('GET', 'domain/pricelist');
    } catch (\Throwable $e) {
        return skrime_error($e);
    }
}

function skrime_fetchProduct(Api $api, $domain)
{
    $data = $api->call('GET', 'product/single', ['domain' => $domain]);
    $list = Helper::productList($data);
    if (!empty($list)) {
        return $list[0];
    }
    if (is_array($data) && (isset($data['expireAt']) || isset($data['state']) || isset($data['id']))) {
        return $data;
    }

    return [];
}

function skrime_Sync($params)
{
    $settings = Settings::fromParams($params);
    $domain = Helper::domainName($params);

    try {
        $product = skrime_fetchProduct(Api::fromSettings($settings), $domain);
    } catch (ApiException $e) {
        if ($e->isProductMissing() && $settings->bool('syncMissingAsCancelled')) {
            return [
                'expirydate' => date('Y-m-d'),
                'active' => false,
                'cancelled' => true,
                'transferredAway' => false,
            ];
        }

        return skrime_error($e);
    } catch (\Throwable $e) {
        return skrime_error($e);
    }

    if ($settings->bool('domainSyncEnabled') && $settings->bool('domainSyncOnSync') && !empty($params['domainid']) && !empty($product)) {
        try {
            (new DomainPriceSync($settings))->runForDomain((int) $params['domainid'], $product, false);
        } catch (\Throwable $e) {
            if (function_exists('logActivity')) {
                logActivity('SKRIME Domain-Preisabgleich für ' . $domain . ' fehlgeschlagen: ' . $e->getMessage());
            }
        }
    }

    $status = Helper::productStatus($product);
    $result = [
        'active' => $status === 'active',
        'cancelled' => $status === 'cancelled',
        'transferredAway' => $status === 'transferred',
    ];
    if ($status === 'expired') {
        $result['expired'] = true;
    }
    $expiry = isset($product['expireAt']) ? Helper::toDate($product['expireAt']) : null;
    if ($expiry !== null) {
        $result['expirydate'] = $expiry;
    }

    return $result;
}

function skrime_TransferSync($params)
{
    $settings = Settings::fromParams($params);
    $domain = Helper::domainName($params);

    try {
        $product = skrime_fetchProduct(Api::fromSettings($settings), $domain);
    } catch (ApiException $e) {
        if ($e->isNotFound()) {
            return [];
        }

        return skrime_error($e);
    } catch (\Throwable $e) {
        return skrime_error($e);
    }

    $state = isset($product['state']) && is_scalar($product['state']) ? strtolower(trim((string) $product['state'])) : '';
    $status = $state === '' ? 'pending' : Helper::productStatus($product);
    if ($state === 'active') {
        $result = ['completed' => true];
        $expiry = isset($product['expireAt']) ? Helper::toDate($product['expireAt']) : null;
        if ($expiry !== null) {
            $result['expirydate'] = $expiry;
        }
        skrime_rememberOrder($params, $settings, $domain, $product);

        return $result;
    }
    if (in_array($status, ['failed', 'cancelled'], true)) {
        $reason = isset($product['productInfo']['view']['reason']) && is_scalar($product['productInfo']['view']['reason'])
            ? trim((string) $product['productInfo']['view']['reason'])
            : '';

        return [
            'failed' => true,
            'reason' => $reason !== '' ? $reason : 'Transfer bei SKRIME fehlgeschlagen',
        ];
    }

    return [];
}

function skrime_GetTldPricing(array $params)
{
    $settings = Settings::fromParams($params);

    try {
        $pricelist = Pricelist::fetch(Api::fromSettings($settings));
    } catch (\Throwable $e) {
        if (function_exists('logActivity')) {
            logActivity('SKRIME GetTldPricing Error: ' . $e->getMessage());
        }

        return skrime_error($e);
    }

    $maxYears = $settings->maxYears();
    $results = new ResultsList();

    foreach ($pricelist as $entry) {
        $costs = Pricelist::effectiveCosts($entry, $settings);
        $regular = $entry['renew'] !== null && $entry['renew'] > 0 ? $entry['renew'] : null;
        $register = $costs['register'] !== null ? $costs['register'] : ($regular !== null ? $regular : $entry['register']);
        $transfer = $costs['transfer'] !== null ? $costs['transfer'] : ($regular !== null ? $regular : $entry['transfer']);
        $renew = $costs['renew'] !== null ? $costs['renew'] : $entry['renew'];
        if ($register === null || $renew === null) {
            continue;
        }

        $item = (new ImportItem())
            ->setExtension($entry['tld'])
            ->setMinYears(1)
            ->setMaxYears($maxYears)
            ->setRegisterPrice((float) $register)
            ->setRenewPrice((float) $renew)
            ->setTransferPrice((float) ($transfer !== null ? $transfer : $register))
            ->setRedemptionFeeDays(30)
            ->setRedemptionFeePrice((float) $entry['restore'])
            ->setCurrency('EUR')
            ->setEppRequired(true);

        $results[] = $item;
    }

    return $results;
}

function skrime_AdminCustomButtonArray($params = [])
{
    return [
        'SKRIME: Domainpreis abgleichen' => 'SyncDomainPrice',
        'SKRIME: Alle Domainpreise abgleichen' => 'SyncAllDomainPrices',
        'SKRIME: TLD-Preise abgleichen' => 'SyncTldPrices',
    ];
}

function skrime_withLock(callable $callback)
{
    Storage::ensureSchema();
    if (!Storage::acquireLock('cron', 1800)) {
        return ['error' => 'Ein SKRIME-Preisabgleich läuft bereits. Bitte später erneut versuchen.'];
    }
    try {
        return $callback();
    } finally {
        Storage::releaseLock('cron');
    }
}

function skrime_SyncDomainPrice($params)
{
    try {
        return skrime_withLock(function () use ($params) {
            $report = (new DomainPriceSync(Settings::fromParams($params)))->runForDomain((int) $params['domainid'], null, true);
            if (!empty($report->errors())) {
                return ['error' => implode(' ', $report->errors())];
            }

            return ['success' => true];
        });
    } catch (\Throwable $e) {
        return skrime_error($e);
    }
}

function skrime_SyncAllDomainPrices($params)
{
    try {
        return skrime_withLock(function () use ($params) {
            $result = (new DomainPriceSync(Settings::fromParams($params)))->runAll(120);
            if (!empty($result['errors'])) {
                return ['error' => $result['changes'] . ' Preisänderung(en), Fehler: ' . implode(' ', array_slice($result['errors'], 0, 5))];
            }
            if (!$result['complete']) {
                return ['error' => $result['changes'] . ' Preisänderung(en). Zeitlimit erreicht, der Rest wird beim nächsten Cron-Lauf abgeglichen.'];
            }

            return ['success' => true];
        });
    } catch (\Throwable $e) {
        return skrime_error($e);
    }
}

function skrime_SyncTldPrices($params)
{
    try {
        return skrime_withLock(function () use ($params) {
            $report = (new TldPriceSync(Settings::fromParams($params)))->run(true);
            if (!empty($report->errors())) {
                return ['error' => count($report->changes()) . ' Preisänderung(en), Fehler: ' . implode(' ', array_slice($report->errors(), 0, 5))];
            }

            return ['success' => true];
        });
    } catch (\Throwable $e) {
        return skrime_error($e);
    }
}

function skrime_ClientAreaCustomButtonArray($params = [])
{
    $settings = is_array($params) && array_key_exists('apiKey', $params)
        ? Settings::fromParams($params)
        : Settings::fromRegistrar();
    if (!$settings) {
        return [];
    }
    $buttons = [];
    if ($settings->choice('dnsEditor') === 'skrime' && skrime_dnsAllowed(is_array($params) ? $params : [], $settings)) {
        $buttons['DNS-Verwaltung'] = 'dnszone';
    }
    if ($settings->bool('dnssecClientArea')) {
        $buttons['DNSSEC'] = 'dnssec';
    }

    return $buttons;
}

function skrime_dnsAllowed(array $params, Settings $settings)
{
    if (!$settings->bool('dnsEditorRequireAddon')) {
        return true;
    }
    $domainId = isset($params['domainid']) ? (int) $params['domainid'] : 0;
    if ($domainId <= 0) {
        return !empty($params['dnsmanagement']);
    }
    try {
        return !empty(Capsule::table('tbldomains')->where('id', $domainId)->value('dnsmanagement'));
    } catch (\Throwable $e) {
        return !empty($params['dnsmanagement']);
    }
}

function skrime_dnsHash(array $rows)
{
    return sha1(json_encode(array_values($rows)));
}

function skrime_dnsRows(array $records, $fieldMode)
{
    $rows = [];
    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }
        $type = strtoupper(isset($record['type']) ? (string) $record['type'] : '');
        $data = isset($record['data']) ? (string) $record['data'] : '';
        $priority = '';
        if (in_array($type, ['MX', 'SRV'], true)) {
            if ($fieldMode && isset($record['priority']) && $record['priority'] !== '') {
                $priority = (string) $record['priority'];
            } elseif (preg_match('/^(\d+)\s+(.+)$/', trim($data), $matches)) {
                $priority = $matches[1];
                $data = $matches[2];
            }
        }
        $rows[] = [
            'name' => isset($record['name']) && (string) $record['name'] !== '' ? (string) $record['name'] : '@',
            'type' => $type,
            'data' => $data,
            'priority' => $priority,
        ];
    }

    return $rows;
}

function skrime_dnsRecord($name, $type, $data, $priority, $fieldMode)
{
    $name = trim((string) $name);
    $name = $name === '' ? '@' : $name;
    $type = strtoupper(trim((string) $type));
    $data = trim((string) $data);
    $priority = trim((string) $priority);

    if (!in_array($type, SKRIME_API_DNS_TYPES, true)) {
        return [null, 'Der Record-Typ ' . $type . ' wird nicht unterstützt.'];
    }
    if (!preg_match('/^[A-Za-z0-9_.*@-]+$/', $name)) {
        return [null, 'Ungültiger Name "' . $name . '".'];
    }
    if ($data === '') {
        return [null, 'Für ' . $name . ' (' . $type . ') fehlt der Wert.'];
    }
    if ($type === 'A' && filter_var($data, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        return [null, '"' . $data . '" ist keine gültige IPv4-Adresse.'];
    }
    if ($type === 'AAAA' && filter_var($data, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
        return [null, '"' . $data . '" ist keine gültige IPv6-Adresse.'];
    }

    $record = ['name' => $name, 'type' => $type, 'data' => $data];
    if (in_array($type, ['MX', 'SRV'], true)) {
        if ($priority !== '' && !ctype_digit($priority)) {
            return [null, 'Ungültige Priorität für ' . $name . ' (' . $type . ').'];
        }
        $parts = preg_split('/\s+/', $data);
        $fields = $type === 'MX' ? 2 : 4;
        if (count($parts) === $fields && ctype_digit($parts[0])) {
            if ($priority !== '' && (int) $priority !== (int) $parts[0]) {
                return [null, 'Die Priorität für ' . $name . ' (' . $type . ') ist doppelt angegeben.'];
            }
            $priority = array_shift($parts);
        }
        if (count($parts) !== $fields - 1 || ($type === 'SRV' && (!ctype_digit($parts[0]) || !ctype_digit($parts[1])))) {
            return [null, $type === 'MX'
                ? 'Ungültiger MX-Wert für ' . $name . ' (erwartet: Mailserver).'
                : 'Ungültiger SRV-Wert für ' . $name . ' (erwartet: Gewichtung Port Ziel).'];
        }
        $data = implode(' ', $parts);
        $priority = $priority === '' ? '10' : $priority;
        if ($fieldMode) {
            $record['data'] = $data;
            $record['priority'] = (int) $priority;
        } else {
            $record['data'] = (int) $priority . ' ' . $data;
        }
    }

    return [$record, null];
}

function skrime_dnszone($params)
{
    $settings = Settings::fromParams($params);
    $fieldMode = $settings->choice('dnsMxMode') === 'field';
    $domain = Helper::domainName($params);
    $domainId = isset($params['domainid']) ? (int) $params['domainid'] : 0;
    $vars = [
        'domain' => $domain,
        'domainid' => $domainId,
        'types' => SKRIME_API_DNS_TYPES,
        'rows' => [],
        'newRows' => 3,
        'zoneHash' => '',
        'loaded' => false,
        'successMessage' => '',
        'errorMessage' => '',
        'enabled' => $settings->choice('dnsEditor') === 'skrime',
    ];
    $output = function () use (&$vars, $domainId) {
        return [
            'templatefile' => 'dnszone',
            'breadcrumb' => [
                'clientarea.php?action=domaindetails&id=' . $domainId . '&modop=custom&a=dnszone' => 'DNS-Verwaltung',
            ],
            'vars' => $vars,
        ];
    };

    if (!$vars['enabled']) {
        $vars['errorMessage'] = 'Der SKRIME DNS-Editor ist deaktiviert.';

        return $output();
    }
    if (!skrime_dnsAllowed($params, $settings)) {
        $vars['enabled'] = false;
        $vars['errorMessage'] = 'DNS-Verwaltung ist für diese Domain nicht gebucht.';

        return $output();
    }

    $api = Api::fromSettings($settings);
    $loadZone = function () use ($api, $domain, $fieldMode) {
        try {
            $zone = $api->call('GET', 'domain/dns', ['domain' => $domain]);

            return skrime_dnsRows(isset($zone['records']) && is_array($zone['records']) ? $zone['records'] : [], $fieldMode);
        } catch (ApiException $e) {
            if ($e->isNotFound()) {
                return [];
            }
            throw $e;
        }
    };
    $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';

    if ($method === 'POST' && isset($_POST['skrime_action']) && $_POST['skrime_action'] === 'savezone') {
        if (function_exists('check_token')) {
            check_token('WHMCS.default');
        }
        if (!isset($_POST['dns_complete'])) {
            $vars['errorMessage'] = 'Die Zone wurde nicht vollständig übertragen (zu viele Einträge für die PHP-Einstellung max_input_vars). Es wurde nichts gespeichert.';
        } else {
            $decode = function ($key) {
                return isset($_POST[$key]) && is_array($_POST[$key]) ? array_map('skrime_input', $_POST[$key]) : [];
            };
            $names = $decode('dns_name');
            $types = $decode('dns_type');
            $datas = $decode('dns_data');
            $priorities = $decode('dns_priority');
            $deletes = isset($_POST['dns_delete']) && is_array($_POST['dns_delete']) ? $_POST['dns_delete'] : [];
            $postedHash = isset($_POST['zone_hash']) ? (string) $_POST['zone_hash'] : '';

            $records = [];
            $errors = [];
            $posted = [];
            foreach ($names as $index => $name) {
                $data = isset($datas[$index]) ? (string) $datas[$index] : '';
                $type = isset($types[$index]) ? (string) $types[$index] : '';
                $priority = isset($priorities[$index]) ? (string) $priorities[$index] : '';
                if (isset($deletes[$index]) || trim($data) === '') {
                    continue;
                }
                $posted[] = ['name' => (string) $name, 'type' => strtoupper($type), 'data' => $data, 'priority' => $priority];
                list($record, $error) = skrime_dnsRecord($name, $type, $data, $priority, $fieldMode);
                if ($error !== null) {
                    $errors[] = $error;
                    continue;
                }
                $records[] = $record;
            }

            if (!empty($errors)) {
                $vars['errorMessage'] = implode(' ', array_slice($errors, 0, 10));
                $vars['rows'] = $posted;
                $vars['zoneHash'] = $postedHash;
                $vars['loaded'] = true;

                return $output();
            }

            try {
                $current = $loadZone();
            } catch (\Throwable $e) {
                $vars['errorMessage'] = 'Die aktuelle Zone konnte nicht geladen werden, es wurde nichts gespeichert: ' . $e->getMessage();

                return $output();
            }
            if ($postedHash === '' || !hash_equals(skrime_dnsHash($current), $postedHash)) {
                $vars['errorMessage'] = 'Die Zone wurde zwischenzeitlich geändert. Bitte die Änderungen erneut eintragen.';
                $vars['rows'] = $current;
                $vars['zoneHash'] = skrime_dnsHash($current);
                $vars['loaded'] = true;

                return $output();
            }

            try {
                $api->call('POST', 'domain/dns', ['domain' => $domain, 'records' => $records]);
                $vars['successMessage'] = 'Die DNS-Zone wurde gespeichert.';
            } catch (\Throwable $e) {
                $vars['errorMessage'] = $e->getMessage();
                $vars['rows'] = $posted;
                $vars['zoneHash'] = $postedHash;
                $vars['loaded'] = true;

                return $output();
            }
        }
    }

    try {
        $vars['rows'] = $loadZone();
        $vars['zoneHash'] = skrime_dnsHash($vars['rows']);
        $vars['loaded'] = true;
    } catch (\Throwable $e) {
        if ($vars['errorMessage'] === '') {
            $vars['errorMessage'] = 'Die DNS-Zone konnte nicht geladen werden: ' . $e->getMessage();
        }
    }

    return $output();
}

function skrime_dnssec($params)
{
    $settings = Settings::fromParams($params);
    $domain = Helper::domainName($params);
    $domainId = isset($params['domainid']) ? (int) $params['domainid'] : 0;
    $vars = [
        'domain' => $domain,
        'domainid' => $domainId,
        'algorithms' => SKRIME_DNSSEC_ALGORITHMS,
        'flagOptions' => [257 => '257 (KSK)', 256 => '256 (ZSK)'],
        'enabled' => $settings->bool('dnssecClientArea'),
        'dnssec' => null,
        'successMessage' => '',
        'errorMessage' => '',
    ];

    if ($vars['enabled']) {
        $api = Api::fromSettings($settings);
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
        $action = isset($_POST['skrime_action']) ? (string) $_POST['skrime_action'] : '';

        if ($method === 'POST' && in_array($action, ['save', 'delete'], true)) {
            if (function_exists('check_token')) {
                check_token('WHMCS.default');
            }
            try {
                if ($action === 'delete') {
                    $api->call('DELETE', 'domain/dnssec', ['domain' => $domain]);
                    $vars['successMessage'] = 'DNSSEC wurde deaktiviert.';
                } else {
                    $flags = isset($_POST['flags']) ? (int) $_POST['flags'] : 0;
                    $algorithm = isset($_POST['algorithm']) ? (int) $_POST['algorithm'] : 0;
                    $publicKey = isset($_POST['publicKey']) ? preg_replace('/\s+/', '', (string) $_POST['publicKey']) : '';
                    if (!in_array($flags, [256, 257], true)) {
                        throw new \InvalidArgumentException('Ungültige Flags.');
                    }
                    if (!array_key_exists($algorithm, SKRIME_DNSSEC_ALGORITHMS)) {
                        throw new \InvalidArgumentException('Ungültiger Algorithmus.');
                    }
                    if (strlen($publicKey) < 16 || !preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $publicKey)) {
                        throw new \InvalidArgumentException('Ungültiger Public Key (Base64 erwartet).');
                    }
                    $api->call('POST', 'domain/dnssec', [
                        'domain' => $domain,
                        'dnssec' => [
                            'flags' => (string) $flags,
                            'algorithm' => (string) $algorithm,
                            'publicKey' => $publicKey,
                        ],
                    ]);
                    $vars['successMessage'] = 'DNSSEC wurde gespeichert.';
                }
            } catch (\Throwable $e) {
                $vars['errorMessage'] = $e->getMessage();
            }
        }

        try {
            $data = $api->call('GET', 'domain/dnssec', ['domain' => $domain]);
            if (isset($data['dnssec']) && is_array($data['dnssec']) && !empty($data['dnssec']['publicKey'])) {
                $vars['dnssec'] = [
                    'flags' => isset($data['dnssec']['flags']) ? (string) $data['dnssec']['flags'] : '',
                    'algorithm' => isset($data['dnssec']['algorithm']) ? (string) $data['dnssec']['algorithm'] : '',
                    'publicKey' => (string) $data['dnssec']['publicKey'],
                ];
            }
        } catch (\Throwable $e) {
            $notFound = $e instanceof ApiException && $e->isNotFound();
            if (!$notFound && $vars['errorMessage'] === '') {
                $vars['errorMessage'] = $e->getMessage();
            }
        }
    } else {
        $vars['errorMessage'] = 'Die DNSSEC-Verwaltung ist deaktiviert.';
    }

    return [
        'templatefile' => 'dnssec',
        'breadcrumb' => [
            'clientarea.php?action=domaindetails&id=' . $domainId . '&modop=custom&a=dnssec' => 'DNSSEC',
        ],
        'vars' => $vars,
    ];
}
