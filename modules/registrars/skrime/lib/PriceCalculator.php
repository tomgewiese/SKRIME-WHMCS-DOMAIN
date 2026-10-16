<?php

namespace WHMCS\Module\Registrar\Skrime;

class PriceCalculator
{
    private $settings;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    public function marginPercent($type)
    {
        $map = [
            'register' => 'marginRegister',
            'transfer' => 'marginTransfer',
            'renew' => 'marginRenew',
            'restore' => 'marginRenew',
        ];
        if ($type === 'domain') {
            $domain = $this->settings->floatOrNull('domainMargin');
            if ($domain !== null) {
                return $domain;
            }
            $type = 'renew';
        }
        if (isset($map[$type])) {
            $specific = $this->settings->floatOrNull($map[$type]);
            if ($specific !== null) {
                return $specific;
            }
        }

        return $this->settings->float('marginPercent');
    }

    public function markup($cost, $type)
    {
        $cost = (float) $cost;
        $price = $cost * (1 + $this->marginPercent($type) / 100) + $this->settings->float('marginFixed');
        $price = max($price, $cost + max(0.0, $this->settings->float('minProfit')));
        $price = max($price, max(0.0, $this->settings->float('minPrice')));

        return $price;
    }

    public function round($amount)
    {
        $cents = (int) round(((float) $amount) * 100);
        if ($cents <= 0) {
            return 0.0;
        }

        switch ($this->settings->choice('rounding')) {
            case 'up_99':
                $value = intdiv($cents, 100) * 100 + 99;
                if ($value < $cents) {
                    $value += 100;
                }
                break;
            case 'up_49_99':
                $base = intdiv($cents, 100) * 100;
                $value = $base + 149;
                foreach ([$base + 49, $base + 99] as $candidate) {
                    if ($candidate >= $cents) {
                        $value = $candidate;
                        break;
                    }
                }
                break;
            case 'up_x9':
                $value = intdiv($cents, 10) * 10 + 9;
                break;
            case 'up_50':
                $value = (int) ceil($cents / 50) * 50;
                break;
            case 'up_whole':
                $value = (int) ceil($cents / 100) * 100;
                break;
            case 'nearest_whole':
                $value = (int) round($cents / 100) * 100;
                if ($value <= 0) {
                    $value = 100;
                }
                break;
            default:
                $value = $cents;
        }

        return $value / 100;
    }

    public function yearlyPrice($cost, $type, array $currency)
    {
        $eur = $this->markup($cost, $type);
        $converted = $this->convert($eur, $currency);
        if ($converted === null) {
            return null;
        }

        return $this->round($converted);
    }

    public function convert($amountEur, array $currency)
    {
        if (!isset($currency['factor']) || $currency['factor'] === null) {
            return null;
        }

        return (float) $amountEur * (float) $currency['factor'];
    }

    public function shouldApply($current, $new)
    {
        if ($current === null) {
            return true;
        }
        $current = (float) $current;
        $new = (float) $new;
        if (abs($new - $current) < 0.005) {
            return false;
        }
        if ($new > $current && !$this->settings->bool('priceIncrease')) {
            return false;
        }
        if ($new < $current && !$this->settings->bool('priceDecrease')) {
            return false;
        }
        $threshold = max(0.0, $this->settings->float('minChangePercent'));
        if ($threshold > 0 && $current > 0 && abs($new - $current) / $current * 100 < $threshold) {
            return false;
        }

        return true;
    }
}
