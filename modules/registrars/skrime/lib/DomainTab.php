<?php

namespace WHMCS\Module\Registrar\Skrime;

use WHMCS\Database\Capsule;

class DomainTab
{
    const EVENT_LABELS = [
        'missing' => 'Nicht gefunden',
        'found' => 'Wieder gefunden',
        'expiry' => 'Ablaufdatum',
        'expiry_sync' => 'Ablaufdatum übernommen',
        'expiry_mismatch' => 'Ablaufdatum abweichend',
        'state' => 'Status',
        'autorenew' => 'Auto-Verlängerung',
        'cost' => 'Einkaufspreis',
        'price' => 'Verkaufspreis',
        'mode' => 'Preis-Modus',
        'renew_partial' => 'Verlängerung unvollständig',
        'register_partial' => 'Registrierung unvollständig',
    ];

    public static function domain($domainId)
    {
        $row = Capsule::table('tbldomains')->where('id', (int) $domainId)->first();
        if (!$row || strtolower((string) $row->registrar) !== Settings::MODULE) {
            return null;
        }

        return $row;
    }

    public static function fields($domainId)
    {
        $row = self::domain($domainId);
        if (!$row) {
            return [];
        }
        Storage::ensureSchema();
        $state = Storage::domainState((int) $row->id) ?: [];
        $e = function ($value) {
            return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        };
        $value = function ($key, $default = '') use ($state) {
            return isset($state[$key]) && $state[$key] !== null && $state[$key] !== '' ? $state[$key] : $default;
        };

        $mode = $value('price_mode', 'auto');
        $currency = $value('last_currency', '');
        $cost = $value('product_price', null);
        $margin = $value('last_margin', null);
        $price = (float) $row->recurringamount;
        $period = max(1, (int) $row->registrationperiod);
        $lastPrice = $value('last_price', null);
        $marginPercent = null;
        if ($cost !== null && $margin !== null && $lastPrice !== null) {
            $costTotal = (float) $lastPrice - (float) $margin;
            $margin = round($price - $costTotal, 2);
            $marginPercent = $costTotal > 0 ? $margin / $costTotal * 100 : null;
        }

        $productState = $value('product_state', '');
        $stateLabel = $productState === '' ? 'noch nicht abgeglichen' : ($productState === 'missing' ? 'bei SKRIME nicht gefunden' : $productState);
        $autoRenew = $value('auto_renew', null);
        $expire = $value('expire_at', '');

        $product = $e($stateLabel)
            . ($value('product_id') !== '' ? ' &middot; ID ' . $e($value('product_id')) : '')
            . ($expire !== '' ? ' &middot; Ablauf bei SKRIME: ' . $e(date('d.m.Y', strtotime($expire))) : '')
            . ($autoRenew !== null ? ' &middot; Auto-Verlängerung bei SKRIME: ' . ((int) $autoRenew === 1 ? '<strong style="color:#c00">an</strong>' : 'aus') : '')
            . ($value('checked_at') !== '' ? '<br><small class="text-muted">Zuletzt abgeglichen: ' . $e(date('d.m.Y H:i', strtotime($value('checked_at')))) . '</small>' : '');

        $prices = $cost === null
            ? '<span class="text-muted">Noch kein Einkaufspreis bekannt</span>'
            : 'Einkaufspreis: <strong>' . $e(Helper::formatAmount($cost)) . ' EUR</strong> je 12 Monate'
                . ' &middot; Verkaufspreis: <strong>' . $e(Helper::formatAmount($price)) . ' ' . $e($currency) . '</strong> für ' . $period . ' Jahr(e)'
                . ($margin !== null ? ' &middot; Marge: <strong>' . $e(Helper::formatAmount($margin)) . ' ' . $e($currency) . '</strong>' . ($marginPercent !== null ? ' (' . $e(number_format($marginPercent, 1, ',', '.')) . ' %)' : '') : '');

        $options = [
            'auto' => 'Automatisch (Einstellungen des Moduls)',
            'fixed' => 'Fester Preis (nie synchronisieren)',
            'margin' => 'Eigene Marge für diese Domain',
        ];
        $select = '<select name="skrime_price_mode" class="form-control select-inline" style="display:inline-block;width:auto">';
        foreach ($options as $key => $label) {
            $select .= '<option value="' . $e($key) . '"' . ($mode === $key ? ' selected' : '') . '>' . $e($label) . '</option>';
        }
        $select .= '</select>';
        $modeHtml = $select
            . ' Aufschlag <input type="text" name="skrime_margin_percent" value="' . $e($value('margin_percent', '')) . '" size="5" class="form-control input-inline" style="display:inline-block;width:80px"> %'
            . ' + <input type="text" name="skrime_margin_fixed" value="' . $e($value('margin_fixed', '')) . '" size="5" class="form-control input-inline" style="display:inline-block;width:80px"> EUR'
            . '<br><small class="text-muted">Fester Preis: Der wiederkehrende Betrag oben bleibt unverändert. Eigene Marge: Einkaufspreis + Aufschlag, wird bei jedem Abgleich angewendet.</small>';

        $openEvents = Storage::domainOpenEvents((int) $row->id);
        $byId = [];
        $maxOpen = 0;
        foreach ($openEvents as $event) {
            $byId[(int) $event->id] = $event;
            $maxOpen = max($maxOpen, (int) $event->id);
        }
        foreach (Storage::domainEvents((int) $row->id, 10) as $event) {
            $byId[(int) $event->id] = $event;
        }
        krsort($byId);
        $events = array_values($byId);
        $open = count($openEvents);
        $list = '';
        foreach ($events as $event) {
            $label = isset(self::EVENT_LABELS[$event->type]) ? self::EVENT_LABELS[$event->type] : $event->type;
            $badge = $event->attention && !$event->acknowledged ? '<span class="label label-danger badge badge-danger">offen</span> ' : '';
            $change = $event->old_value !== '' || $event->new_value !== ''
                ? ': ' . $e($event->old_value) . ' &rarr; ' . $e($event->new_value)
                : '';
            $list .= '<li>' . $badge . '<small class="text-muted">' . $e(date('d.m.Y H:i', strtotime($event->created_at))) . '</small> '
                . '<strong>' . $e($label) . '</strong> ' . $e($event->message) . $change . '</li>';
        }
        $eventsHtml = $list === ''
            ? '<span class="text-muted">Keine Änderungen protokolliert</span>'
            : '<ul style="margin:0;padding-left:18px">' . $list . '</ul>'
                . ($open > 0 ? '<input type="hidden" name="skrime_ack_max" value="' . $maxOpen . '"><label style="font-weight:normal;margin-top:5px"><input type="checkbox" name="skrime_ack" value="1"> ' . $open . ' offene(n) Hinweis(e) als erledigt markieren</label>' : '');

        return [
            'SKRIME Produkt' => $product,
            'SKRIME Preise' => $prices,
            'SKRIME Preis-Modus' => $modeHtml,
            'SKRIME Änderungen' => $eventsHtml,
        ];
    }

    public static function save($domainId, array $input, ?Settings $settings = null)
    {
        $row = self::domain($domainId);
        if (!$row || !array_key_exists('skrime_price_mode', $input)) {
            return false;
        }
        Storage::ensureSchema();
        $domain = strtolower((string) $row->domain);
        $state = Storage::domainState((int) $row->id) ?: [];
        $oldMode = isset($state['price_mode']) && $state['price_mode'] !== '' ? $state['price_mode'] : 'auto';
        $mode = in_array($input['skrime_price_mode'], Storage::PRICE_MODES, true) ? $input['skrime_price_mode'] : 'auto';
        $rawPercent = isset($input['skrime_margin_percent']) ? trim((string) $input['skrime_margin_percent']) : '';
        $rawFixed = isset($input['skrime_margin_fixed']) ? trim((string) $input['skrime_margin_fixed']) : '';
        $percent = self::number($rawPercent);
        $fixed = self::number($rawFixed);

        if (!empty($input['skrime_ack'])) {
            Storage::acknowledgeEvents((int) $row->id, isset($input['skrime_ack_max']) ? (int) $input['skrime_ack_max'] : 0);
        }

        if ($mode === 'margin') {
            $invalid = ($rawPercent !== '' && ($percent === null || abs($percent) > 999999))
                || ($rawFixed !== '' && ($fixed === null || abs($fixed) > 999999))
                || ($percent === null && $fixed === null);
            if ($invalid) {
                Storage::addEvent((int) $row->id, $domain, 'mode', 'Eigene Marge ungültig, nicht übernommen', $rawPercent === '' ? '-' : $rawPercent . ' %', $rawFixed === '' ? '-' : $rawFixed . ' EUR', true);

                return false;
            }
        }

        $oldPercent = isset($state['margin_percent']) ? $state['margin_percent'] : null;
        $oldFixed = isset($state['margin_fixed']) ? $state['margin_fixed'] : null;
        Storage::setPriceMode((int) $row->id, $mode, $percent, $fixed);

        $describe = function ($mode, $percent, $fixed) {
            if ($mode === 'fixed') {
                return 'Fester Preis';
            }
            if ($mode === 'margin') {
                return 'Eigene Marge ' . Helper::formatAmount((float) $percent) . ' % + ' . Helper::formatAmount((float) $fixed) . ' EUR';
            }

            return 'Automatisch';
        };
        $before = $describe($oldMode, $oldPercent, $oldFixed);
        $after = $describe($mode, $percent, $fixed);
        if ($before !== $after) {
            Storage::addEvent((int) $row->id, $domain, 'mode', 'Preis-Modus geändert', $before, $after, false);
        }

        if ($mode === 'margin') {
            $settings = $settings ?: Settings::fromRegistrar();
            $problem = null;
            if (!$settings || $settings->string('apiKey') === '') {
                $problem = 'Kein API Key hinterlegt';
            } else {
                try {
                    $report = (new DomainPriceSync($settings))->runForDomain((int) $row->id, null, true);
                    if (!empty($report->errors())) {
                        $problem = implode(' ', $report->errors());
                    }
                } catch (\Throwable $e) {
                    $problem = $e->getMessage();
                }
            }
            if ($problem !== null) {
                Storage::addEvent((int) $row->id, $domain, 'mode', 'Eigene Marge konnte nicht angewendet werden', '', $problem, true);
            }
        }

        return true;
    }

    private static function number($value)
    {
        $value = preg_replace('/(%|€|eur)$/iu', '', str_replace(' ', '', trim((string) $value)));
        if ($value === '') {
            return null;
        }
        if (strpos($value, ',') !== false && strpos($value, '.') !== false) {
            $value = strrpos($value, ',') > strrpos($value, '.')
                ? str_replace(['.', ','], ['', '.'], $value)
                : str_replace(',', '', $value);
        } elseif (strpos($value, ',') !== false) {
            $value = str_replace(',', '.', $value);
        }

        return is_numeric($value) ? (float) $value : null;
    }
}
