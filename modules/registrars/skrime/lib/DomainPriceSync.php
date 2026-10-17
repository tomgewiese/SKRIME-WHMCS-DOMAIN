<?php

namespace WHMCS\Module\Registrar\Skrime;

use WHMCS\Database\Capsule;

class DomainPriceSync
{
    const MAX_TRANSPORT_FAILURES = 3;
    const BATCH_SECONDS = 900;

    private static $sharedPricelist;

    private $settings;
    private $api;
    private $calculator;
    private $currencies;
    private $pricelist;
    private $products;
    private $knownTlds;
    private $addonPricing = [];
    private $renewPricing = [];
    private $groupPricing = [];
    private $hasUpdatedAt;
    private $transportFailure = false;

    public function __construct(Settings $settings, ?Api $api = null)
    {
        $this->settings = $settings;
        $this->api = $api ?: Api::fromSettings($settings);
        $this->calculator = new PriceCalculator($settings);
    }

    public function setPricelist(array $pricelist)
    {
        $this->pricelist = $pricelist;
    }

    public function runBatch($force = false)
    {
        Storage::ensureSchema();
        $report = new Report('Produkt-Sync');
        $cursor = (int) Storage::get('domain_cursor', 0);

        if ((int) Storage::get('domain_force_pending', 0) === 1) {
            $force = true;
        }

        $cycleStart = (int) Storage::get('domain_cycle_start', 0);
        if ($cursor === 0) {
            if (!$force && !Storage::isDue('domain_cycle_start', $this->settings->int('domainSyncInterval', 1, 8760))) {
                return null;
            }
            Storage::set('domain_cycle_start', time());
        }

        $batch = $this->settings->int('domainSyncBatch', 1, 5000);
        $domains = $this->baseQuery()
            ->where('id', '>', $cursor)
            ->orderBy('id')
            ->limit($batch)
            ->get();

        $started = time();
        $failures = 0;
        $streakStart = null;
        $stopped = false;
        $processed = 0;

        if (count($domains) > 0) {
            if ($this->settings->bool('domainSyncBulk')) {
                $this->loadBulkProducts($report);
            }
            foreach ($domains as $domain) {
                $result = $this->processRow($domain, null, $force, $report);
                $processed++;
                if ($this->transportFailure) {
                    if ($failures === 0) {
                        $streakStart = $cursor;
                    }
                    $failures++;
                } elseif (in_array($result, ['changed', 'unchanged'], true)) {
                    $failures = 0;
                    $streakStart = null;
                }
                $cursor = (int) $domain->id;
                if ($failures >= self::MAX_TRANSPORT_FAILURES) {
                    $report->error('SKRIME API nicht erreichbar, Abgleich wird beim nächsten Cron-Lauf fortgesetzt');
                    $cursor = (int) $streakStart;
                    if ($cursor === 0) {
                        Storage::set('domain_cycle_start', $cycleStart);
                    }
                    $stopped = true;
                    break;
                }
                if (time() - $started >= self::BATCH_SECONDS) {
                    $stopped = true;
                    break;
                }
                if ($processed % 10 === 0) {
                    Storage::refreshLock(Cron::LOCK);
                }
            }
        }

        if (!$stopped && count($domains) < $batch) {
            Storage::set('domain_cursor', 0);
            Storage::set('domain_force_pending', 0);
            Storage::set('domain_last_complete', time());
        } else {
            Storage::set('domain_cursor', $cursor);
        }

        $report->finish();
        Storage::set('domain_last_run', time());
        Storage::set('domain_last_result', $report->summary());

        return $report;
    }

    public function runForDomain($domainId, $product = null, $force = false)
    {
        Storage::ensureSchema();
        $report = new Report('Produkt-Sync');
        $row = Capsule::table('tbldomains')->where('id', (int) $domainId)->first();
        if (!$row) {
            $report->error('Domain #' . (int) $domainId . ' nicht gefunden');

            return $report;
        }
        if (strtolower((string) $row->registrar) !== Settings::MODULE) {
            $report->error($row->domain . ': Registrar ist nicht SKRIME');

            return $report;
        }
        $result = $this->processRow($row, $product, $force, $report);
        if ($force && !in_array($result, ['changed', 'unchanged'], true) && strpos($result, 'Fehler: ') !== 0) {
            $report->error($row->domain . ': ' . $result);
        }
        $report->finish();

        return $report;
    }

    public function runAll($maxSeconds = 120)
    {
        Storage::ensureSchema();
        Storage::set('domain_cursor', 0);
        Storage::set('domain_force_pending', 1);
        $started = time();
        $changes = 0;
        $errors = [];
        do {
            $report = $this->runBatch(true);
            if ($report === null) {
                break;
            }
            $changes += count($report->changes());
            $errors = array_merge($errors, $report->errors());
            $cursor = (int) Storage::get('domain_cursor', 0);
        } while ($cursor > 0 && time() - $started < $maxSeconds && empty($report->errors()));

        $complete = (int) Storage::get('domain_force_pending', 0) === 0;

        return [
            'changes' => $changes,
            'errors' => $errors,
            'complete' => $complete,
        ];
    }

    private function baseQuery()
    {
        return Capsule::table('tbldomains')
            ->where('registrar', Settings::MODULE)
            ->whereIn('status', $this->statuses());
    }

    private function statuses()
    {
        $statuses = $this->settings->csv('domainStatuses', false);

        return empty($statuses) ? ['Active'] : $statuses;
    }

    private function processRow($row, $product, $force, Report $report)
    {
        $this->transportFailure = false;
        try {
            $result = $this->processDomain($row, $product, $force, $report);
        } catch (\Throwable $e) {
            $this->transportFailure = $e instanceof ApiException && $e->isTransportError();
            $result = 'Fehler: ' . $e->getMessage();
            $report->error($row->domain . ': ' . $e->getMessage());
        }
        $report->count('geprüfte Domains');

        return $result;
    }

    private function processDomain($row, $product, $force, Report $report)
    {
        $statuses = array_map('strtolower', $this->statuses());
        if (!$force && !in_array(strtolower((string) $row->status), $statuses, true)) {
            return 'Status ' . $row->status . ' ausgeschlossen';
        }

        $domain = strtolower((string) $row->domain);
        $state = Storage::domainState((int) $row->id) ?: [];

        if ($product === null || (is_array($product) && empty($product))) {
            $product = $this->product($domain);
        }
        if (!is_array($product) || empty($product)) {
            if ((isset($state['product_state']) ? $state['product_state'] : '') !== 'missing') {
                Storage::addEvent((int) $row->id, $domain, 'missing', 'Domain bei SKRIME nicht gefunden', '', '', true);
                $report->count('Hinweise');
            }
            Storage::saveDomainState((int) $row->id, [
                'domain' => substr($domain, 0, 191),
                'product_state' => 'missing',
                'checked_at' => Storage::now(),
            ]);

            return 'Domain bei SKRIME nicht gefunden';
        }

        $this->trackProduct($row, $domain, $product, $state, $report);
        $state = Storage::domainState((int) $row->id) ?: [];

        return $this->updatePrice($row, $domain, $product, $state, $force, $report);
    }

    private function trackProduct($row, $domain, array $product, array $state, Report $report)
    {
        $field = $this->settings->choice('domainSyncPriceField');
        $known = isset($state['product_state']) && $state['product_state'] !== '' && $state['product_state'] !== 'missing';
        $events = [];

        $newExpire = isset($product['expireAt']) ? Helper::toDate($product['expireAt']) : null;
        $oldExpire = !empty($state['expire_at']) ? substr((string) $state['expire_at'], 0, 10) : null;
        $newState = isset($product['state']) && is_scalar($product['state']) ? strtolower(trim((string) $product['state'])) : '';
        $oldState = isset($state['product_state']) ? (string) $state['product_state'] : '';
        $newAuto = isset($product['autoRenew']) ? (bool) $product['autoRenew'] : null;
        $oldAuto = isset($state['auto_renew']) && $state['auto_renew'] !== null ? (bool) $state['auto_renew'] : null;
        $newCost = Helper::productCost($product, $field);
        $oldCost = isset($state['product_price']) && $state['product_price'] !== null ? (float) $state['product_price'] : null;
        $mode = isset($state['price_mode']) && $state['price_mode'] !== '' ? $state['price_mode'] : 'auto';

        if ($oldState === 'missing') {
            $events[] = ['found', 'Domain bei SKRIME wieder gefunden', '', $newState, false];
        }
        if ($known && $oldExpire !== null && $newExpire !== null && $oldExpire !== $newExpire) {
            $events[] = ['expiry', 'Ablaufdatum bei SKRIME geändert', $oldExpire, $newExpire, false];
        }
        if ($known && $newState !== '' && $oldState !== $newState) {
            $events[] = ['state', 'Status bei SKRIME geändert', $oldState, $newState, $newState !== 'active'];
        }
        if ($newAuto === true && $oldAuto !== true) {
            $events[] = ['autorenew', 'Auto-Verlängerung bei SKRIME aktiv, WHMCS verlängert ebenfalls', $oldAuto === null ? '' : 'aus', 'an', true];
        }
        if ($known && $oldCost !== null && $newCost !== null && abs($oldCost - $newCost) >= 0.005) {
            $priceHandled = $this->settings->bool('domainPriceUpdate') && $mode !== 'fixed';
            $events[] = [
                'cost',
                $priceHandled ? 'Einkaufspreis geändert' : 'Einkaufspreis geändert, Verkaufspreis bitte prüfen',
                Helper::formatAmount($oldCost) . ' EUR',
                Helper::formatAmount($newCost) . ' EUR',
                !$priceHandled,
            ];
        }

        $extra = [];
        $whmcsExpiry = Helper::toDate(isset($row->expirydate) ? (string) $row->expirydate : '');
        $mismatch = isset($state['expiry_mismatch']) ? (string) $state['expiry_mismatch'] : '';
        if ($newExpire !== null && $whmcsExpiry !== null && $newExpire !== $whmcsExpiry) {
            if ($this->settings->bool('syncExpiryToWhmcs')) {
                Capsule::table('tbldomains')->where('id', (int) $row->id)->update(['expirydate' => $newExpire]);
                $events[] = ['expiry_sync', 'Ablaufdatum in WHMCS an SKRIME angepasst', $whmcsExpiry, $newExpire, false];
                $extra['expiry_mismatch'] = '';
            } elseif ($mismatch !== $newExpire) {
                $events[] = ['expiry_mismatch', 'Ablaufdatum in WHMCS weicht von SKRIME ab', 'WHMCS ' . $whmcsExpiry, 'SKRIME ' . $newExpire, true];
                $extra['expiry_mismatch'] = $newExpire;
            }
        } elseif ($mismatch !== '') {
            $extra['expiry_mismatch'] = '';
        }

        foreach ($events as $event) {
            Storage::addEvent((int) $row->id, $domain, $event[0], $event[1], $event[2], $event[3], $event[4]);
            $report->count('Hinweise');
        }
        Storage::rememberProduct((int) $row->id, $domain, $product, $field, $extra);
    }

    private function updatePrice($row, $domain, array $product, array $state, $force, Report $report)
    {
        $mode = isset($state['price_mode']) && in_array($state['price_mode'], Storage::PRICE_MODES, true) ? $state['price_mode'] : 'auto';
        $source = $this->settings->choice('domainSyncSource');
        $client = Capsule::table('tblclients')->where('id', (int) $row->userid)->first();
        $currency = $this->currencies()->get($client ? (int) $client->currency : 0);
        $period = max(1, (int) $row->registrationperiod);
        $current = round((float) $row->recurringamount, 2);
        $addons = $this->settings->bool('domainKeepAddons') && $currency ? $this->addonPrice($row, $currency) * $period : 0.0;
        $productCost = Helper::productCost($product, $this->settings->choice('domainSyncPriceField'));

        $basis = null;
        $basisSource = $source;
        if (in_array($source, ['product', 'product_pricelist'], true)) {
            $basis = $productCost;
            $basisSource = 'product';
        }
        if ($basis === null && in_array($source, ['product_pricelist', 'pricelist'], true)) {
            $basis = $this->pricelistRenewCost($domain);
            $basisSource = 'pricelist';
        }
        $costForMargin = $productCost !== null ? $productCost : ($basisSource === 'pricelist' ? $basis : null);

        $saveState = function ($price) use ($row, $domain, &$basis, &$basisSource, $currency, $period, $addons, $costForMargin) {
            $data = [
                'domain' => substr($domain, 0, 191),
                'last_price' => round((float) $price, 2),
                'last_currency' => $currency ? $currency['code'] : '',
            ];
            if ($basis !== null) {
                $data['basis'] = $basis;
                $data['basis_source'] = $basisSource;
            }
            if ($currency && $currency['factor'] !== null && $costForMargin !== null) {
                $data['last_margin'] = round((float) $price - $addons - $costForMargin * $currency['factor'] * $period, 2);
            }
            Storage::saveDomainState((int) $row->id, $data);
        };

        if ($mode === 'fixed') {
            $saveState($current);

            return 'unchanged';
        }
        if (!$this->settings->bool('domainPriceUpdate') && !$force) {
            $saveState($current);

            return 'unchanged';
        }
        $skip = null;
        if ($mode === 'auto') {
            if ($this->settings->bool('domainSkipPremium') && !empty($row->is_premium)) {
                $skip = 'Premium-Domain übersprungen';
            } elseif ($this->settings->bool('domainSkipPromo') && (int) $row->promoid > 0) {
                $skip = 'Domain mit Promo-Code übersprungen';
            } elseif ($this->settings->bool('domainSkipFree') && $current <= 0) {
                $skip = 'Kostenlose Domain übersprungen';
            } elseif ($this->settings->bool('domainSkipDoNotRenew') && !empty($row->donotrenew)) {
                $skip = 'Domain "Nicht verlängern" übersprungen';
            }
        }
        if ($skip !== null) {
            $saveState($current);

            return $skip;
        }
        if (!$currency || $currency['factor'] === null) {
            return 'Keine Umrechnung für die Kundenwährung möglich';
        }
        if ($source !== 'whmcs' && !$this->currencies()->isTarget($currency['id'])) {
            $saveState($current);

            return 'Währung ' . $currency['code'] . ' ist vom Preisabgleich ausgeschlossen';
        }
        $groupId = $client && isset($client->groupid) ? (int) $client->groupid : 0;
        if ($mode === 'auto' && $source !== 'whmcs' && $groupId > 0 && $this->settings->bool('domainSkipClientGroups')
            && $this->hasGroupPricing($domain, $currency, $groupId)) {
            $saveState($current);

            return 'Kundengruppe mit eigenen TLD-Preisen übersprungen';
        }

        $hasBaseline = isset($state['basis']) && $state['basis'] !== null && isset($state['basis_source']) && $state['basis_source'] === $basisSource;
        $oldBasis = $hasBaseline ? (float) $state['basis'] : null;
        $changed = $hasBaseline && $basis !== null ? abs($oldBasis - (float) $basis) >= 0.00005 : null;
        $marginMode = $this->settings->choice('domainMarginMode');
        $trigger = $this->settings->choice('domainSyncTrigger');
        $factor = (float) $currency['factor'];
        $target = null;
        $detail = 'Verlängerung ' . $period . ' Jahr(e)';

        if ($mode === 'margin') {
            if ($basis === null) {
                return 'Kein Einkaufspreis für ' . $domain . ' ermittelbar';
            }
            $percent = isset($state['margin_percent']) && $state['margin_percent'] !== null ? (float) $state['margin_percent'] : 0.0;
            $fixed = isset($state['margin_fixed']) && $state['margin_fixed'] !== null ? (float) $state['margin_fixed'] : 0.0;
            $yearly = $this->calculator->round(($basis * (1 + $percent / 100) + $fixed) * $factor);
            $target = round($yearly * $period + $addons, 2);
            $detail .= ', eigene Marge';
        } elseif ($source === 'whmcs') {
            $whmcs = $this->whmcsRenewPrice($domain, $currency, $period, $groupId);
            if ($whmcs === null) {
                return 'Kein WHMCS-Preis für ' . $domain . ' ermittelbar';
            }
            $basis = $whmcs;
            $basisSource = 'whmcs';
            $hasBaseline = isset($state['basis']) && $state['basis'] !== null && isset($state['basis_source']) && $state['basis_source'] === 'whmcs';
            $changed = $hasBaseline ? abs((float) $state['basis'] - $whmcs) >= 0.00005 : null;
            if (!($force || $trigger === 'always' || ($changed === null ? $this->settings->bool('domainSyncInitial') : $changed))) {
                $saveState($current);

                return 'unchanged';
            }
            $target = round($whmcs + $addons, 2);
        } elseif ($basis === null) {
            return 'Kein Einkaufspreis für ' . $domain . ' ermittelbar';
        } elseif ($marginMode === 'calculate') {
            if (!($force || $trigger === 'always' || ($changed === null ? $this->settings->bool('domainSyncInitial') : $changed))) {
                $saveState($current);

                return 'unchanged';
            }
            $yearly = $this->calculator->yearlyPrice($basis, 'domain', $currency);
            if ($yearly === null) {
                return 'Kein Preis für ' . $domain . ' ermittelbar';
            }
            $target = round($yearly * $period + $addons, 2);
        } else {
            if (!$hasBaseline || !$changed) {
                $saveState($current);

                return 'unchanged';
            }
            $oldCostTotal = $oldBasis * $factor * $period;
            $newCostTotal = (float) $basis * $factor * $period;
            if ($marginMode === 'keep_percent' && $oldCostTotal > 0) {
                $target = round(($current - $addons) * $newCostTotal / $oldCostTotal + $addons, 2);
                $detail .= ', Marge in % beibehalten';
            } else {
                $target = round($current + $newCostTotal - $oldCostTotal, 2);
                $detail .= ', Marge beibehalten';
            }
        }

        if ($target === null || $target <= 0) {
            return 'Kein Preis für ' . $domain . ' ermittelbar';
        }
        if (abs($target - $current) < 0.005) {
            $saveState($current);

            return 'unchanged';
        }
        if (!$this->calculator->shouldApply($current, $target)) {
            $saveState($current);
            $report->count('gefiltert');

            return $force
                ? 'Änderung von ' . Helper::formatAmount($current) . ' auf ' . Helper::formatAmount($target) . ' ' . $currency['code'] . ' durch die Preisregeln (Erhöhung/Senkung/Mindeständerung) blockiert'
                : 'unchanged';
        }

        $update = ['recurringamount' => $target];
        if ($this->hasUpdatedAt === null) {
            $this->hasUpdatedAt = Capsule::schema()->hasColumn('tbldomains', 'updated_at');
        }
        if ($this->hasUpdatedAt) {
            $update['updated_at'] = Storage::now();
        }
        Capsule::table('tbldomains')->where('id', (int) $row->id)->update($update);
        $saveState($target);

        $report->change($row->domain, $detail, $currency['code'], $current, $target);
        Storage::log('domain', $row->domain, $detail, $currency['code'], $current, $target, $oldBasis, $basis);
        Storage::addEvent(
            (int) $row->id,
            $domain,
            'price',
            'Verlängerungspreis angepasst',
            Helper::formatAmount($current) . ' ' . $currency['code'],
            Helper::formatAmount($target) . ' ' . $currency['code'],
            false
        );
        $this->notifyClient($row, $current, $target, $currency);

        return 'changed';
    }

    private function currencies()
    {
        if ($this->currencies === null) {
            $this->currencies = new Currencies($this->settings);
        }

        return $this->currencies;
    }

    private function loadBulkProducts(Report $report)
    {
        if ($this->products !== null) {
            return;
        }
        $this->products = [];
        try {
            foreach (Helper::productList($this->api->call('GET', 'product/all', ['type' => 'domain'])) as $product) {
                if (isset($product['type']) && strtolower((string) $product['type']) !== 'domain') {
                    continue;
                }
                $this->products[Helper::productDomain($product)] = $product;
            }
        } catch (\Throwable $e) {
            $report->error('product/all: ' . $e->getMessage());
        }
    }

    private function product($domain)
    {
        if (is_array($this->products) && isset($this->products[$domain])) {
            return $this->products[$domain];
        }
        try {
            $data = $this->api->call('GET', 'product/single', ['domain' => $domain]);
        } catch (ApiException $e) {
            if ($e->isNotFound()) {
                return null;
            }
            throw $e;
        }
        $list = Helper::productList($data);
        if (!empty($list)) {
            return $list[0];
        }

        return is_array($data) && (isset($data['price']) || isset($data['state'])) ? $data : null;
    }

    private function pricelistRenewCost($domain)
    {
        if ($this->pricelist === null) {
            if (self::$sharedPricelist === null) {
                try {
                    self::$sharedPricelist = Pricelist::fetch($this->api);
                } catch (\Throwable $e) {
                    if (!($e instanceof ApiException && $e->isTransportError())) {
                        self::$sharedPricelist = [];
                    }
                    throw $e;
                }
            }
            $this->pricelist = self::$sharedPricelist;
        }
        if (empty($this->pricelist)) {
            return null;
        }
        list(, $tld) = Helper::splitDomain($domain, array_keys($this->pricelist));
        if (!isset($this->pricelist[$tld])) {
            return null;
        }
        $costs = Pricelist::effectiveCosts($this->pricelist[$tld], $this->settings);

        return $costs['renew'];
    }

    private function knownTlds()
    {
        if ($this->knownTlds === null) {
            $this->knownTlds = [];
            foreach (Capsule::table('tbldomainpricing')->pluck('extension') as $extension) {
                $this->knownTlds[] = strtolower(ltrim(trim((string) $extension), '.'));
            }
        }

        return $this->knownTlds;
    }

    private function tldPricingId($domain)
    {
        list(, $tld) = Helper::splitDomain($domain, $this->knownTlds());
        if ($tld === '') {
            return [null, $tld];
        }

        return [
            Capsule::table('tbldomainpricing')->whereIn('extension', ['.' . $tld, $tld])->value('id'),
            $tld,
        ];
    }

    private function hasGroupPricing($domain, array $currency, $groupId)
    {
        list($relid, $tld) = $this->tldPricingId($domain);
        $cacheKey = $tld . '|' . $currency['id'] . '|' . $groupId;
        if (!array_key_exists($cacheKey, $this->groupPricing)) {
            $this->groupPricing[$cacheKey] = $relid && Capsule::table('tblpricing')
                ->where('type', 'domainrenew')
                ->where('relid', $relid)
                ->where('currency', $currency['id'])
                ->where('tsetupfee', (int) $groupId)
                ->exists();
        }

        return $this->groupPricing[$cacheKey];
    }

    private function whmcsRenewPrice($domain, array $currency, $period, $groupId = 0)
    {
        list($relid, $tld) = $this->tldPricingId($domain);
        $groupId = max(0, (int) $groupId);
        $cacheKey = $tld . '|' . $currency['id'] . '|' . $groupId;
        if (!array_key_exists($cacheKey, $this->renewPricing)) {
            $this->renewPricing[$cacheKey] = null;
            if ($relid) {
                $this->renewPricing[$cacheKey] = Capsule::table('tblpricing')
                    ->where('type', 'domainrenew')
                    ->where('relid', $relid)
                    ->where('currency', $currency['id'])
                    ->whereIn('tsetupfee', array_values(array_unique([0, $groupId])))
                    ->orderBy('tsetupfee', 'desc')
                    ->first();
            }
        }
        $row = $this->renewPricing[$cacheKey];
        if (!$row) {
            return null;
        }
        $columns = TldPriceSync::YEAR_COLUMNS;
        if (isset($columns[$period]) && (float) $row->{$columns[$period]} > 0) {
            return round((float) $row->{$columns[$period]}, 2);
        }
        if ((float) $row->msetupfee > 0) {
            return round((float) $row->msetupfee * $period, 2);
        }

        return null;
    }

    private function addonPrice($row, array $currency)
    {
        if (!array_key_exists($currency['id'], $this->addonPricing)) {
            $this->addonPricing[$currency['id']] = Capsule::table('tblpricing')
                ->where('type', 'domainaddons')
                ->where('currency', $currency['id'])
                ->where('relid', 0)
                ->first();
        }
        $pricing = $this->addonPricing[$currency['id']];
        if (!$pricing) {
            return 0.0;
        }
        $total = 0.0;
        if (!empty($row->dnsmanagement) && (float) $pricing->msetupfee > 0) {
            $total += (float) $pricing->msetupfee;
        }
        if (!empty($row->emailforwarding) && (float) $pricing->qsetupfee > 0) {
            $total += (float) $pricing->qsetupfee;
        }
        if (!empty($row->idprotection) && (float) $pricing->ssetupfee > 0) {
            $total += (float) $pricing->ssetupfee;
        }

        return $total;
    }

    private function notifyClient($row, $old, $new, array $currency)
    {
        $template = $this->settings->string('notifyClientTemplate');
        if ($template === '' || !function_exists('localAPI')) {
            return;
        }
        if ($this->settings->bool('notifyClientIncreaseOnly') && $new <= $old) {
            return;
        }
        try {
            $result = localAPI('SendEmail', [
                'messagename' => $template,
                'id' => (int) $row->id,
                'customvars' => base64_encode(serialize([
                    'skrime_old_price' => Helper::formatAmount($old),
                    'skrime_new_price' => Helper::formatAmount($new),
                    'skrime_currency' => $currency['code'],
                ])),
            ]);
            if (is_array($result) && isset($result['result']) && $result['result'] !== 'success' && function_exists('logActivity')) {
                logActivity('SKRIME Kundenbenachrichtigung für ' . $row->domain . ' fehlgeschlagen: ' . (isset($result['message']) ? $result['message'] : ''));
            }
        } catch (\Throwable $e) {
            if (function_exists('logActivity')) {
                logActivity('SKRIME Kundenbenachrichtigung für ' . $row->domain . ' fehlgeschlagen: ' . $e->getMessage());
            }
        }
    }
}
