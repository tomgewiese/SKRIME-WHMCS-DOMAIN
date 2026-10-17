<?php

namespace WHMCS\Module\Registrar\Skrime;

use WHMCS\Database\Capsule;

class Notifier
{
    const LIMIT = 500;

    public static function sendDigest(Settings $settings)
    {
        Storage::ensureSchema();
        $lastLogId = (int) Storage::get('notify_last_id', 0);
        $lastEventId = (int) Storage::get('notify_last_event_id', 0);
        $maxLogId = (int) Capsule::table(Storage::TABLE_LOG)->max('id');
        $maxEventId = (int) Capsule::table(Storage::TABLE_EVENTS)->max('id');

        $markSent = function () use ($maxLogId, $maxEventId, $lastLogId, $lastEventId) {
            if ($maxLogId > $lastLogId) {
                Storage::set('notify_last_id', $maxLogId);
            }
            if ($maxEventId > $lastEventId) {
                Storage::set('notify_last_event_id', $maxEventId);
            }
        };

        if (!$settings->bool('notifyAdmin') || !function_exists('sendAdminNotification')) {
            $markSent();

            return false;
        }
        if ($maxLogId <= $lastLogId && $maxEventId <= $lastEventId) {
            return false;
        }

        $priceTotal = (int) Capsule::table(Storage::TABLE_LOG)->where('id', '>', $lastLogId)->count();
        $prices = Capsule::table(Storage::TABLE_LOG)->where('id', '>', $lastLogId)->orderBy('id')->limit(self::LIMIT)->get();
        $eventTotal = (int) Capsule::table(Storage::TABLE_EVENTS)->where('id', '>', $lastEventId)->count();
        $attentionTotal = (int) Capsule::table(Storage::TABLE_EVENTS)->where('id', '>', $lastEventId)->where('attention', 1)->count();
        $events = Capsule::table(Storage::TABLE_EVENTS)
            ->where('id', '>', $lastEventId)
            ->orderBy('attention', 'desc')
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get();

        $body = '';
        if ($eventTotal > 0) {
            $rows = '';
            foreach ($events as $event) {
                $label = isset(DomainTab::EVENT_LABELS[$event->type]) ? DomainTab::EVENT_LABELS[$event->type] : $event->type;
                $rows .= '<tr' . ($event->attention ? ' style="background:#fdecea"' : '') . '>'
                    . '<td>' . self::escape($event->created_at) . '</td>'
                    . '<td>' . self::escape($event->domain) . '</td>'
                    . '<td>' . self::escape($label) . '</td>'
                    . '<td>' . self::escape($event->message) . '</td>'
                    . '<td>' . self::escape($event->old_value) . '</td>'
                    . '<td>' . self::escape($event->new_value) . '</td>'
                    . '<td>' . ($event->attention ? '<strong>Bitte prüfen</strong>' : 'Info') . '</td>'
                    . '</tr>';
            }
            $body .= '<h3>Änderungen bei SKRIME (' . $eventTotal . ', davon ' . $attentionTotal . ' zu prüfen)</h3>'
                . '<table border="1" cellpadding="4" cellspacing="0" style="border-collapse:collapse">'
                . '<tr><th>Zeit</th><th>Domain</th><th>Art</th><th>Meldung</th><th>Alt</th><th>Neu</th><th>Aktion</th></tr>'
                . $rows
                . '</table>'
                . ($eventTotal > self::LIMIT ? '<p>... und ' . ($eventTotal - self::LIMIT) . ' weitere.</p>' : '');
        }

        if ($priceTotal > 0) {
            $rows = '';
            foreach ($prices as $entry) {
                $rows .= '<tr>'
                    . '<td>' . self::escape($entry->created_at) . '</td>'
                    . '<td>' . ($entry->type === 'tld' ? 'TLD' : 'Domain') . '</td>'
                    . '<td>' . self::escape($entry->reference) . '</td>'
                    . '<td>' . self::escape($entry->detail) . '</td>'
                    . '<td style="text-align:right">' . ($entry->old_price === null ? '-' : Helper::formatAmount($entry->old_price)) . '</td>'
                    . '<td style="text-align:right">' . ($entry->new_price === null ? '-' : Helper::formatAmount($entry->new_price)) . '</td>'
                    . '<td>' . self::escape($entry->currency) . '</td>'
                    . '<td style="text-align:right">' . ($entry->old_cost === null ? '-' : Helper::formatAmount($entry->old_cost)) . '</td>'
                    . '<td style="text-align:right">' . ($entry->new_cost === null ? '-' : Helper::formatAmount($entry->new_cost)) . '</td>'
                    . '</tr>';
            }
            $body .= '<h3>Preisänderungen (' . $priceTotal . ')</h3>'
                . '<table border="1" cellpadding="4" cellspacing="0" style="border-collapse:collapse">'
                . '<tr><th>Zeit</th><th>Typ</th><th>Objekt</th><th>Details</th><th>VK alt</th><th>VK neu</th><th>Währung</th><th>EK alt (EUR)</th><th>EK neu (EUR)</th></tr>'
                . $rows
                . '</table>'
                . ($priceTotal > self::LIMIT ? '<p>... und ' . ($priceTotal - self::LIMIT) . ' weitere Einträge im Preisprotokoll.</p>' : '');
        }

        $subject = 'SKRIME: ' . $priceTotal . ' Preisänderung(en), ' . $attentionTotal . ' Hinweis(e) zu prüfen';

        try {
            sendAdminNotification('system', $subject, $body);
        } catch (\Throwable $e) {
            if (function_exists('logActivity')) {
                logActivity('SKRIME Admin-Benachrichtigung fehlgeschlagen: ' . $e->getMessage());
            }

            return false;
        }
        $markSent();

        return true;
    }

    private static function escape($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
