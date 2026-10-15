<?php

namespace WHMCS\Module\Registrar\Skrime;

class Pricelist
{
    const TYPES = ['register', 'transfer', 'renew', 'restore'];

    public static function fetch(Api $api)
    {
        $data = $api->call('GET', 'domain/pricelist');

        return self::normalize($data);
    }

    public static function normalize($data)
    {
        $list = [];
        if (!is_array($data)) {
            return $list;
        }
        foreach ($data as $entry) {
            if (!is_array($entry) || !isset($entry['tld'])) {
                continue;
            }
            $tld = strtolower(ltrim(trim((string) $entry['tld']), '.'));
            if ($tld === '') {
                continue;
            }
            $offerTypes = [];
            if (isset($entry['offerTypes']) && is_array($entry['offerTypes'])) {
                foreach ($entry['offerTypes'] as $type) {
                    $type = self::mapType($type);
                    if ($type !== null) {
                        $offerTypes[] = $type;
                    }
                }
            }
            $offer = !empty($entry['offer']);
            if ($offer && empty($offerTypes)) {
                $offerTypes = ['register'];
            }
            $list[$tld] = [
                'tld' => $tld,
                'register' => self::amount($entry, 'create'),
                'transfer' => self::amount($entry, 'transfer'),
                'renew' => self::amount($entry, 'renew'),
                'restore' => self::amount($entry, 'restore'),
                'offer' => $offer,
                'offerTypes' => array_values(array_unique($offerTypes)),
            ];
        }

        return $list;
    }

    public static function effectiveCosts(array $entry, Settings $settings)
    {
        $mode = $settings->choice('offerMode');
        $costs = [];
        foreach (self::TYPES as $type) {
            $cost = isset($entry[$type]) ? $entry[$type] : null;
            if ($cost !== null && $cost <= 0) {
                $cost = null;
            }
            $isOffer = !empty($entry['offer']) && in_array($type, $entry['offerTypes'], true);
            if ($cost !== null && $isOffer && $mode !== 'apply') {
                $cost = null;
                $renewOffer = in_array('renew', $entry['offerTypes'], true);
                if ($mode === 'regular' && in_array($type, ['register', 'transfer'], true) && !$renewOffer
                    && isset($entry['renew']) && $entry['renew'] > 0) {
                    $cost = $entry['renew'];
                }
            }
            $costs[$type] = $cost;
        }

        return $costs;
    }

    private static function amount(array $entry, $key)
    {
        if (!isset($entry[$key]) || !is_numeric($entry[$key])) {
            return null;
        }

        return round((float) $entry[$key], 4);
    }

    private static function mapType($type)
    {
        $type = strtolower(trim((string) $type));
        $map = [
            'create' => 'register',
            'register' => 'register',
            'registration' => 'register',
            'order' => 'register',
            'transfer' => 'transfer',
            'renew' => 'renew',
            'renewal' => 'renew',
            'restore' => 'restore',
        ];

        return isset($map[$type]) ? $map[$type] : null;
    }
}
