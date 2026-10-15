<?php

namespace WHMCS\Module\Registrar\Skrime;

use WHMCS\Database\Capsule;

class TldPriceSync
{
    const YEAR_COLUMNS = [
        1 => 'msetupfee',
        2 => 'qsetupfee',
        3 => 'ssetupfee',
        4 => 'asetupfee',
        5 => 'bsetupfee',
        6 => 'monthly',
        7 => 'quarterly',
        8 => 'semiannually',
        9 => 'annually',
        10 => 'biennially',
    ];

    const PRICE_TYPES = [
        'register' => 'domainregister',
        'transfer' => 'domaintransfer',
        'renew' => 'domainrenew',
    ];

    const TYPE_LABELS = [
        'register' => 'Registrierung',
        'transfer' => 'Transfer',
        'renew' => 'Verlängerung',
        'restore' => 'Redemption',
    ];

    private $settings;
    private $api;
    private $calculator;
    private $pricelist;

    public function __construct(Settings $settings, ?Api $api = null)
    {
        $this->settings = $settings;
        $this->api = $api ?: Api::fromSettings($settings);
        $this->calculator = new PriceCalculator($settings);
    }

    public function run($force = false, ?array $pricelist = null)
    {
        Storage::ensureSchema();
        $report = new Report('TLD-Preisabgleich');

        try {
            if ($pricelist === null) {
                $pricelist = Pricelist::fetch($this->api);
            }
        } catch (\Throwable $e) {
            $report->error('Preisliste konnte nicht geladen werden: ' . $e->getMessage());
            $this->finish($report);

            return $report;
        }

        $this->pricelist = $pricelist;

        if (empty($pricelist)) {
            $report->error('Die SKRIME-Preisliste ist leer');
            $this->finish($report);

            return $report;
        }

        $currencies = new Currencies($this->settings);
        $targets = $currencies->targets();
        if (empty($targets)) {
            $report->error('Keine passende Währung gefunden (EUR anlegen oder "Standardwährung als EUR" aktivieren)');
            $this->finish($report);

            return $report;
        }

        $pricingRows = [];
        foreach (Capsule::table('tbldomainpricing')->get() as $row) {
            $extension = strtolower(ltrim(trim((string) $row->extension), '.'));
            if ($extension !== '' && !isset($pricingRows[$extension])) {
                $pricingRows[$extension] = $row;
            }
        }

        $scope = $this->settings->choice('tldSyncScope');
        $include = $this->settings->tldList('tldInclude');
        $exclude = $this->settings->tldList('tldExclude');
        $trigger = $this->settings->choice('tldSyncTrigger');
        $initial = $this->settings->bool('tldSyncInitial');
        $types = [];
        foreach (['register' => 'tldUpdateRegister', 'transfer' => 'tldUpdateTransfer', 'renew' => 'tldUpdateRenew'] as $type => $key) {
            if ($this->settings->bool($key)) {
                $types[] = $type;
            }
        }
        $restore = $this->settings->bool('tldUpdateRedemption')
            && Capsule::schema()->hasColumn('tbldomainpricing', 'redemption_grace_period_fee');

        foreach ($pricelist as $tld => $entry) {
            if (!empty($include) && !in_array($tld, $include, true)) {
                continue;
            }
            if (in_array($tld, $exclude, true)) {
                continue;
            }

            try {
                $isNew = false;
                $pricing = isset($pricingRows[$tld]) ? $pricingRows[$tld] : null;
                if ($pricing === null) {
                    if ($scope !== 'create') {
                        continue;
                    }
                    $pricing = $this->createTld($tld);
                    $pricingRows[$tld] = $pricing;
                    $isNew = true;
                    $report->count('neue TLDs');
                } elseif ($scope === 'assigned' && strtolower(trim((string) $pricing->autoreg)) !== Settings::MODULE) {
                    continue;
                }

                $report->count('geprüfte TLDs');
                $costs = Pricelist::effectiveCosts($entry, $this->settings);
                $stored = Storage::tldPrice($tld);
                $storedCosts = $stored === null ? null : Pricelist::effectiveCosts([
                    'register' => $stored['register'] === null ? null : (float) $stored['register'],
                    'transfer' => $stored['transfer'] === null ? null : (float) $stored['transfer'],
                    'renew' => $stored['renew'] === null ? null : (float) $stored['renew'],
                    'restore' => $stored['restore'] === null ? null : (float) $stored['restore'],
                    'offer' => !empty($stored['offer']),
                    'offerTypes' => array_values(array_filter(explode(',', (string) $stored['offer_types']))),
                ], $this->settings);

                foreach ($types as $type) {
                    if ($costs[$type] === null) {
                        continue;
                    }
                    $old = $storedCosts === null ? null : $storedCosts[$type];
                    if (!$this->shouldRun($force, $trigger, $isNew, $stored === null, $initial, $old, $costs[$type])) {
                        continue;
                    }
                    $this->applyType((int) $pricing->id, $tld, $type, $costs[$type], $old, $targets, $report);
                }

                if ($restore && $costs['restore'] !== null) {
                    $old = $storedCosts === null ? null : $storedCosts['restore'];
                    if ($this->shouldRun($force, $trigger, $isNew, $stored === null, $initial, $old, $costs['restore'])) {
                        $this->applyRestore($pricing, $tld, $costs['restore'], $old, $currencies->defaultCurrency(), $report);
                    }
                }

                Storage::saveTldPrice($tld, [
                    'register' => $entry['register'],
                    'transfer' => $entry['transfer'],
                    'renew' => $entry['renew'],
                    'restore' => $entry['restore'],
                ], $entry['offer'], $entry['offerTypes']);
            } catch (\Throwable $e) {
                $report->error('.' . $tld . ': ' . $e->getMessage());
            }
        }

        $this->finish($report);

        return $report;
    }

    public function pricelist()
    {
        return $this->pricelist;
    }

    private function shouldRun($force, $trigger, $isNew, $firstRun, $initial, $old, $new)
    {
        if ($force || $trigger === 'always' || $isNew) {
            return true;
        }
        if ($firstRun) {
            return $initial;
        }
        if ($old === null) {
            return true;
        }

        return abs((float) $old - (float) $new) >= 0.00005;
    }

    private function applyType($relid, $tld, $type, $cost, $oldCost, array $targets, Report $report)
    {
        $priceType = self::PRICE_TYPES[$type];
        $maxYears = $this->settings->int('tldMaxYears', 1, 10);
        $enableYears = $this->settings->bool('tldEnableYears');

        foreach ($targets as $currency) {
            $yearly = $this->calculator->yearlyPrice($cost, $type, $currency);
            if ($yearly === null || $yearly <= 0) {
                continue;
            }

            $query = Capsule::table('tblpricing')
                ->where('type', $priceType)
                ->where('relid', $relid)
                ->where('currency', $currency['id']);
            if (!$this->settings->bool('tldClientGroups')) {
                $query->where('tsetupfee', 0);
            }
            $rows = $query->get();

            if (count($rows) === 0) {
                $insert = [
                    'type' => $priceType,
                    'currency' => $currency['id'],
                    'relid' => $relid,
                    'tsetupfee' => 0,
                    'triennially' => 0,
                ];
                foreach (self::YEAR_COLUMNS as $years => $column) {
                    $enabled = $type === 'transfer' ? $years === 1 : $years <= $maxYears;
                    $insert[$column] = $enabled ? round($yearly * $years, 2) : -1;
                }
                Capsule::table('tblpricing')->insert($insert);
                $this->record($tld, $type, '1 Jahr (neu)', $currency, null, $yearly, $oldCost, $cost, $report);
                continue;
            }

            foreach ($rows as $row) {
                $update = [];
                $oldFirst = null;
                $newFirst = null;
                foreach (self::YEAR_COLUMNS as $years => $column) {
                    if ($years > $maxYears) {
                        break;
                    }
                    $current = (float) $row->$column;
                    if ($current < 0 && !$enableYears) {
                        continue;
                    }
                    $new = round($yearly * $years, 2);
                    if (!$this->calculator->shouldApply($current < 0 ? 0 : $current, $new)) {
                        continue;
                    }
                    $update[$column] = $new;
                    if ($oldFirst === null && $newFirst === null) {
                        $oldFirst = $current < 0 ? null : $current / $years;
                        $newFirst = $new / $years;
                    }
                }
                if (empty($update)) {
                    continue;
                }
                Capsule::table('tblpricing')->where('id', $row->id)->update($update);
                $detail = count($update) . ' Laufzeit(en)';
                if ((int) $row->tsetupfee > 0) {
                    $detail .= ', Kundengruppe ' . (int) $row->tsetupfee;
                }
                $this->record($tld, $type, $detail, $currency, $oldFirst, $newFirst, $oldCost, $cost, $report);
            }
        }
    }

    private function applyRestore($pricing, $tld, $cost, $oldCost, $currency, Report $report)
    {
        if (!$currency || $currency['factor'] === null) {
            return;
        }
        $price = $this->calculator->yearlyPrice($cost, 'restore', $currency);
        if ($price === null) {
            return;
        }
        $current = (float) $pricing->redemption_grace_period_fee;
        if (!$this->calculator->shouldApply($current < 0 ? 0 : $current, $price)) {
            return;
        }
        Capsule::table('tbldomainpricing')->where('id', $pricing->id)->update(['redemption_grace_period_fee' => $price]);
        $this->record($tld, 'restore', 'Gebühr', $currency, $current < 0 ? null : $current, $price, $oldCost, $cost, $report);
    }

    private function record($tld, $type, $detail, array $currency, $old, $new, $oldCost, $newCost, Report $report)
    {
        $label = self::TYPE_LABELS[$type] . ' (' . $detail . ')';
        $report->change('.' . $tld, $label, $currency['code'], $old, $new);
        Storage::log('tld', '.' . $tld, $label, $currency['code'], $old, $new, $oldCost, $newCost);
    }

    private function createTld($tld)
    {
        $schema = Capsule::schema();
        $data = [
            'extension' => '.' . $tld,
            'autoreg' => Settings::MODULE,
        ];
        $optional = [
            'dnsmanagement' => '',
            'emailforwarding' => '',
            'idprotection' => '',
            'eppcode' => 'on',
            'group' => 'none',
        ];
        foreach ($optional as $column => $value) {
            if ($schema->hasColumn('tbldomainpricing', $column)) {
                $data[$column] = $value;
            }
        }
        if ($schema->hasColumn('tbldomainpricing', 'order')) {
            $data['order'] = (int) Capsule::table('tbldomainpricing')->max('order') + 1;
        }
        foreach (['created_at', 'updated_at'] as $column) {
            if ($schema->hasColumn('tbldomainpricing', $column)) {
                $data[$column] = Storage::now();
            }
        }
        $id = Capsule::table('tbldomainpricing')->insertGetId($data);

        return Capsule::table('tbldomainpricing')->where('id', $id)->first();
    }

    private function finish(Report $report)
    {
        $report->finish();
        Storage::set('tld_last_run', time());
        Storage::set('tld_last_result', $report->summary());
    }
}
