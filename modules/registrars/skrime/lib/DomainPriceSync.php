<?php

namespace WHMCS\Module\Registrar\Skrime;

use WHMCS\Database\Capsule;

class DomainPriceSync
{
    private $settings;
    private $api;
    private $calculator;
    private $currencies;
    private $pricelist;
    private $products;
    private $knownTlds;
    private $addonPricing = [];
    private $renewPricing = [];
    private $hasUpdatedAt;

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
        $report = new Report('Domain-Preisabgleich');
        $cursor = (int) Storage::get('domain_cursor', 0);

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

        if (count($domains) > 0) {
            $source = $this->settings->choice('domainSyncSource');
            if (in_array($source, ['product', 'product_pricelist'], true) && $this->settings->bool('domainSyncBulk')) {
                $this->loadBulkProducts($report);
            }
            foreach ($domains as $domain) {
                $this->processRow($domain, null, $force, $report);
                $cursor = (int) $domain->id;
            }
        }

        if (count($domains) < $batch) {
            Storage::set('domain_cursor', 0);
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
        $report = new Report('Domain-Preisabgleich');
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
        } while ($cursor > 0 && time() - $started < $maxSeconds);

        return [
            'changes' => $changes,
            'errors' => $errors,
            'complete' => (int) Storage::get('domain_cursor', 0) === 0,
        ];
    }

    private function baseQuery()
    {
        $statuses = $this->settings->csv('domainStatuses', false);
        if (empty($statuses)) {
            $statuses = ['Active'];
        }

        return Capsule::table('tbldomains')
            ->where('registrar', Settings::MODULE)
            ->whereIn('status', $statuses);
    }

    private function processRow($row, $product, $force, Report $report)
    {
        try {
            $result = $this->processDomain($row, $product, $force, $report);
        } catch (\Throwable $e) {
            $result = 'Fehler: ' . $e->getMessage();
            $report->error($row->domain . ': ' . $e->getMessage());
        }
        $report->count('geprüfte Domains');

        return $result;
    }

    private function processDomain($row, $product, $force, Report $report)
    {
        $statuses = array_map('strtolower', $this->settings->csv('domainStatuses', false) ?: ['Active']);
        if (!$force && !in_array(strtolower((string) $row->status), $statuses, true)) {
            return 'Status ' . $row->status . ' ausgeschlossen';
        }
        if ($this->settings->bool('domainSkipPremium') && !empty($row->is_premium)) {
            return 'Premium-Domain übersprungen';
        }
        if ($this->settings->bool('domainSkipPromo') && (int) $row->promoid > 0) {
            return 'Domain mit Promo-Code übersprungen';
        }
        if ($this->settings->bool('domainSkipFree') && (float) $row->recurringamount <= 0) {
            return 'Kostenlose Domain übersprungen';
        }
        if ($this->settings->bool('domainSkipDoNotRenew') && !empty($row->donotrenew)) {
            return 'Domain "Nicht verlängern" übersprungen';
        }

        $source = $this->settings->choice('domainSyncSource');
        $client = Capsule::table('tblclients')->where('id', (int) $row->userid)->first();
        $currency = $this->currencies()->get($client ? (int) $client->currency : 0);
        if (!$currency || $currency['factor'] === null) {
            return 'Keine Umrechnung für die Kundenwährung möglich';
        }

        $domain = strtolower((string) $row->domain);
        if ($product === null && in_array($source, ['product', 'product_pricelist'], true)) {
            $product = $this->product($domain);
        }
        if (is_array($product)) {
            Storage::rememberProduct((int) $row->id, $domain, $product);
        }

        $basis = null;
        $basisSource = $source;
        if (in_array($source, ['product', 'product_pricelist'], true)) {
            $basis = Helper::productYearlyCost($product, $this->settings->choice('domainSyncPriceField'));
            $basisSource = 'product';
        }
        if ($basis === null && in_array($source, ['product_pricelist', 'pricelist'], true)) {
            $basis = $this->pricelistRenewCost($domain);
            $basisSource = 'pricelist';
        }

        $period = max(1, (int) $row->registrationperiod);
        $target = null;
        if ($source === 'whmcs') {
            $target = $this->whmcsRenewPrice($domain, $currency, $period);
            $basis = $target;
            $basisSource = 'whmcs';
        } elseif ($basis !== null) {
            $yearly = $this->calculator->yearlyPrice($basis, 'domain', $currency);
            $target = $yearly === null ? null : round($yearly * $period, 2);
        }

        if ($basis === null || $target === null || $target <= 0) {
            return 'Kein Preis für ' . $domain . ' ermittelbar';
        }

        $state = Storage::domainState((int) $row->id);
        $hasBaseline = $state !== null && $state['basis'] !== null && $state['basis_source'] === $basisSource;
        $changed = $hasBaseline ? abs((float) $state['basis'] - (float) $basis) >= 0.00005 : null;
        $oldBasis = $hasBaseline ? (float) $state['basis'] : null;

        Storage::saveDomainState((int) $row->id, [
            'domain' => substr($domain, 0, 191),
            'basis' => $basis,
            'basis_source' => $basisSource,
        ]);

        $trigger = $this->settings->choice('domainSyncTrigger');
        $run = $force || $trigger === 'always' || ($changed === null ? $this->settings->bool('domainSyncInitial') : $changed);
        if (!$run) {
            return 'unchanged';
        }

        if ($this->settings->bool('domainKeepAddons')) {
            $target = round($target + $this->addonPrice($row, $currency) * $period, 2);
        }

        $current = round((float) $row->recurringamount, 2);
        if (abs($target - $current) < 0.005) {
            return 'unchanged';
        }
        if (!$this->calculator->shouldApply($current, $target)) {
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

        $detail = 'Verlängerung ' . $period . ' Jahr(e)';
        $report->change($row->domain, $detail, $currency['code'], $current, $target);
        Storage::log('domain', $row->domain, $detail, $currency['code'], $current, $target, $oldBasis, $basis);
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
            foreach (Helper::productList($this->api->call('GET', 'domain/all')) as $product) {
                $this->products[Helper::productDomain($product)] = $product;
            }
        } catch (\Throwable $e) {
            $report->error('domain/all: ' . $e->getMessage());
        }
    }

    private function product($domain)
    {
        if (is_array($this->products) && isset($this->products[$domain])) {
            return $this->products[$domain];
        }
        try {
            $data = $this->api->call('GET', 'domain/single', ['domain' => $domain]);
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

        return is_array($data) ? $data : null;
    }

    private function pricelistRenewCost($domain)
    {
        if ($this->pricelist === null) {
            $this->pricelist = Pricelist::fetch($this->api);
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

    private function whmcsRenewPrice($domain, array $currency, $period)
    {
        list(, $tld) = Helper::splitDomain($domain, $this->knownTlds());
        $cacheKey = $tld . '|' . $currency['id'];
        if (!array_key_exists($cacheKey, $this->renewPricing)) {
            $this->renewPricing[$cacheKey] = null;
            $relid = Capsule::table('tbldomainpricing')
                ->whereIn('extension', ['.' . $tld, $tld])
                ->value('id');
            if ($relid) {
                $this->renewPricing[$cacheKey] = Capsule::table('tblpricing')
                    ->where('type', 'domainrenew')
                    ->where('relid', $relid)
                    ->where('currency', $currency['id'])
                    ->where('tsetupfee', 0)
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
