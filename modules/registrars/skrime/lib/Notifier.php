<?php

namespace WHMCS\Module\Registrar\Skrime;

use WHMCS\Database\Capsule;

class Notifier
{
    const LIMIT = 500;

    public static function sendDigest(Settings $settings)
    {
        Storage::ensureSchema();
        $lastId = (int) Storage::get('notify_last_id', 0);
        $maxId = (int) Capsule::table(Storage::TABLE_LOG)->max('id');

        if (!$settings->bool('notifyAdmin') || !function_exists('sendAdminNotification')) {
            if ($maxId > $lastId) {
                Storage::set('notify_last_id', $maxId);
            }

            return false;
        }
        if ($maxId <= $lastId) {
            return false;
        }

        $total = (int) Capsule::table(Storage::TABLE_LOG)->where('id', '>', $lastId)->count();
        $entries = Capsule::table(Storage::TABLE_LOG)
            ->where('id', '>', $lastId)
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get();

        $rows = '';
        foreach ($entries as $entry) {
            $rows .= '<tr>'
                . '<td>' . self::escape($entry->created_at) . '</td>'
                . '<td>' . ($entry->type === 'tld' ? 'TLD' : 'Domain') . '</td>'
                . '<td>' . self::escape($entry->reference) . '</td>'
                . '<td>' . self::escape($entry->detail) . '</td>'
                . '<td style="text-align:right">' . ($entry->old_price === null ? '-' : Helper::formatAmount($entry->old_price)) . '</td>'
                . '<td style="text-align:right">' . ($entry->new_price === null ? '-' : Helper::formatAmount($entry->new_price)) . '</td>'
                . '<td>' . self::escape($entry->currency) . '</td>'
                . '<td style="text-align:right">' . ($entry->new_cost === null ? '-' : Helper::formatAmount($entry->new_cost)) . '</td>'
                . '</tr>';
        }

        $body = '<p>Das SKRIME-Modul hat ' . $total . ' Preisänderung(en) vorgenommen.</p>'
            . '<table border="1" cellpadding="4" cellspacing="0" style="border-collapse:collapse">'
            . '<tr><th>Zeit</th><th>Typ</th><th>Objekt</th><th>Details</th><th>Alt</th><th>Neu</th><th>Währung</th><th>EK (EUR)</th></tr>'
            . $rows
            . '</table>'
            . ($total > self::LIMIT ? '<p>... und ' . ($total - self::LIMIT) . ' weitere Einträge im Preisprotokoll.</p>' : '');

        try {
            sendAdminNotification('system', 'SKRIME: ' . $total . ' Preisänderung(en)', $body);
        } catch (\Throwable $e) {
            if (function_exists('logActivity')) {
                logActivity('SKRIME Admin-Benachrichtigung fehlgeschlagen: ' . $e->getMessage());
            }

            return false;
        }
        Storage::set('notify_last_id', $maxId);

        return true;
    }

    private static function escape($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
