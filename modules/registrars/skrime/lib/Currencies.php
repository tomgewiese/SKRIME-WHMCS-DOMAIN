<?php

namespace WHMCS\Module\Registrar\Skrime;

use WHMCS\Database\Capsule;

class Currencies
{
    private $all = [];
    private $targets = [];
    private $default;

    public function __construct(Settings $settings)
    {
        $rows = Capsule::table('tblcurrencies')->orderBy('id')->get();
        $eurRate = null;
        $defaultRate = null;
        foreach ($rows as $row) {
            $currency = [
                'id' => (int) $row->id,
                'code' => strtoupper((string) $row->code),
                'rate' => (float) $row->rate,
                'default' => (bool) $row->default,
                'prefix' => isset($row->prefix) ? (string) $row->prefix : '',
                'suffix' => isset($row->suffix) ? (string) $row->suffix : '',
                'factor' => null,
            ];
            if ($currency['code'] === 'EUR' && $currency['rate'] > 0) {
                $eurRate = $currency['rate'];
            }
            if ($currency['default']) {
                $defaultRate = $currency['rate'] > 0 ? $currency['rate'] : 1.0;
            }
            $this->all[$currency['id']] = $currency;
        }

        if ($defaultRate === null && !empty($this->all)) {
            $first = reset($this->all);
            $this->all[$first['id']]['default'] = true;
            $defaultRate = $first['rate'] > 0 ? $first['rate'] : 1.0;
        }

        $baseRate = $eurRate;
        $hasEur = $eurRate !== null;
        if ($baseRate === null && $settings->bool('currencyFallback')) {
            $baseRate = $defaultRate;
        }

        foreach ($this->all as $id => $currency) {
            if ($baseRate !== null && $baseRate > 0 && $currency['rate'] > 0) {
                $this->all[$id]['factor'] = $currency['rate'] / $baseRate;
            } elseif ($baseRate !== null && $currency['default']) {
                $this->all[$id]['factor'] = 1.0;
            }
            if ($this->all[$id]['default']) {
                $this->default = $this->all[$id];
            }
        }

        foreach ($this->all as $id => $currency) {
            if ($currency['factor'] === null) {
                continue;
            }
            if ($settings->choice('currencyMode') === 'eur') {
                $isBase = $hasEur ? $currency['code'] === 'EUR' : $currency['default'];
                if (!$isBase) {
                    continue;
                }
            }
            $this->targets[$id] = $currency;
        }
    }

    public function targets()
    {
        return $this->targets;
    }

    public function get($id)
    {
        $id = (int) $id;
        if (isset($this->all[$id])) {
            return $this->all[$id];
        }

        return $this->default;
    }

    public function isTarget($id)
    {
        return isset($this->targets[(int) $id]);
    }

    public function defaultCurrency()
    {
        return $this->default;
    }
}
