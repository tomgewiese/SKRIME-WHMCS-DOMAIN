<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

require_once __DIR__ . '/lib/autoload.php';

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

function skrime_orderDomain(array $params, $authCode)
{
    $settings = Settings::fromParams($params);
    $domain = Helper::domainName($params);
    $nameservers = Helper::orderNameservers($params, $settings);
    if (count($nameservers) < 2) {
        return ['error' => 'Es werden mindestens 2 Nameserver benötigt. Bitte Standard-Nameserver in den Registrar-Einstellungen hinterlegen.'];
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
        $api = Api::fromSettings($settings);
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

    return ['success' => true];
}

function skrime_rememberOrder(array $params, Settings $settings, $domain, $product)
{
    if (!$settings->bool('domainSyncEnabled') || !is_array($product) || empty($params['domainid'])) {
        return;
    }
    try {
        Storage::rememberProduct((int) $params['domainid'], $domain, $product);
        if (in_array($settings->choice('domainSyncSource'), ['product', 'product_pricelist'], true)) {
            $basis = Helper::productYearlyCost($product, $settings->choice('domainSyncPriceField'));
            if ($basis !== null) {
                Storage::saveDomainState((int) $params['domainid'], [
                    'basis' => $basis,
                    'basis_source' => 'product',
                ]);
            }
        }
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
    try {
        skrime_api($params)->call('POST', 'domain/renew', [
            'domain' => Helper::domainName($params),
        ]);

        return ['success' => true];
    } catch (\Throwable $e) {
        return skrime_error($e);
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
        $contact = Helper::contactFromParams($params, $settings);
        if ($contact['company'] === '') {
            unset($contact['company']);
        }
    } else {
        $contact = Helper::contactFromDetails($details, $params, $settings);
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

    try {
        $api = Api::fromSettings($settings);
        $results = new ResultsList();
        $lastError = null;
        $checked = 0;

        foreach ($tlds as $tld) {
            $searchResult = new SearchResult($searchTerm, $tld);
            try {
                $data = $api->call('POST', 'domain/check', [
                    'domain' => strtolower($label . '.' . ltrim((string) $tld, '.')),
                ]);
                $checked++;
                if (!empty($data['available'])) {
                    $status = !empty($data['premium']) && $settings->choice('premiumHandling') === 'unavailable'
                        ? SearchResult::STATUS_RESERVED
                        : SearchResult::STATUS_NOT_REGISTERED;
                } else {
                    $status = SearchResult::STATUS_REGISTERED;
                }
            } catch (ApiException $e) {
                $lastError = $e;
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
    $records = [];

    foreach (isset($params['dnsrecords']) && is_array($params['dnsrecords']) ? $params['dnsrecords'] : [] as $record) {
        $address = isset($record['address']) ? trim((string) $record['address']) : '';
        if ($address === '') {
            continue;
        }
        $type = strtoupper(isset($record['type']) ? trim((string) $record['type']) : '');
        if (!in_array($type, SKRIME_API_DNS_TYPES, true)) {
            return ['error' => 'Der Record-Typ ' . $type . ' wird von SKRIME nicht unterstützt.'];
        }
        $name = isset($record['hostname']) ? trim((string) $record['hostname']) : '';
        $entry = [
            'name' => $name === '' ? '@' : $name,
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

    try {
        $api = Api::fromSettings($settings);
        if ($settings->bool('dnsKeepUnsupported')) {
            $current = $api->call('GET', 'domain/dns', ['domain' => $domain]);
            foreach (isset($current['records']) && is_array($current['records']) ? $current['records'] : [] as $record) {
                $type = strtoupper(isset($record['type']) ? (string) $record['type'] : '');
                if (!is_array($record) || in_array($type, SKRIME_WHMCS_DNS_TYPES, true)) {
                    continue;
                }
                $records[] = $record;
            }
        }

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
        return skrime_api($params)->call('GET', 'domain/all');
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
    $data = $api->call('GET', 'domain/single', ['domain' => $domain]);
    $list = Helper::productList($data);

    return !empty($list) ? $list[0] : (is_array($data) ? $data : []);
}

function skrime_Sync($params)
{
    $settings = Settings::fromParams($params);
    $domain = Helper::domainName($params);

    try {
        $product = skrime_fetchProduct(Api::fromSettings($settings), $domain);
    } catch (ApiException $e) {
        if ($e->isNotFound() && $settings->bool('syncMissingAsCancelled')) {
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

    if ($settings->bool('domainSyncEnabled') && $settings->bool('domainSyncOnSync') && !empty($params['domainid'])) {
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

    $status = Helper::productStatus($product);
    if ($status === 'active') {
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

    $maxYears = $settings->int('tldMaxYears', 1, 10);
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
    if (!$settings || !$settings->bool('dnssecClientArea')) {
        return [];
    }

    return ['DNSSEC' => 'dnssec'];
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
