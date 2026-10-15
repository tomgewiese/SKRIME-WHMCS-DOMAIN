<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

if (defined('SKRIME_HOOKS_LOADED')) {
    return;
}
define('SKRIME_HOOKS_LOADED', true);

require_once __DIR__ . '/lib/autoload.php';

use WHMCS\Module\Registrar\Skrime\Cron;
use WHMCS\Module\Registrar\Skrime\Settings;
use WHMCS\Module\Registrar\Skrime\Storage;

add_hook('AfterCronJob', 1, function ($vars) {
    try {
        Cron::run();
    } catch (\Throwable $e) {
        if (function_exists('logActivity')) {
            logActivity('SKRIME Cron Fehler: ' . $e->getMessage());
        }
    }
});

if (defined('ADMINAREA') && class_exists('\WHMCS\Module\AbstractWidget')) {
    class SkrimeRegistrarWidget extends \WHMCS\Module\AbstractWidget
    {
        protected $title = 'SKRIME';
        protected $description = 'SKRIME Preis-Synchronisation';
        protected $weight = 150;
        protected $columns = 1;
        protected $cache = false;
        protected $cacheExpiry = 120;
        protected $requiredPermission = '';

        public function getData()
        {
            $settings = Settings::fromRegistrar();
            if (!$settings) {
                return ['active' => false];
            }
            Storage::ensureSchema();

            return [
                'active' => true,
                'tldEnabled' => $settings->bool('tldSyncEnabled'),
                'domainEnabled' => $settings->bool('domainSyncEnabled'),
                'tldLastRun' => (int) Storage::get('tld_last_run', 0),
                'tldLastResult' => (string) Storage::get('tld_last_result', ''),
                'domainLastRun' => (int) Storage::get('domain_last_run', 0),
                'domainLastResult' => (string) Storage::get('domain_last_result', ''),
                'changes24h' => Storage::countLogSince(date('Y-m-d H:i:s', time() - 86400)),
                'recent' => Storage::recentLog(8),
            ];
        }

        public function generateOutput($data)
        {
            $e = function ($value) {
                return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
            };
            if (empty($data['active'])) {
                return '<div class="widget-content-padded">Der SKRIME-Registrar ist nicht aktiviert.</div>';
            }
            $time = function ($timestamp) {
                return $timestamp > 0 ? date('d.m.Y H:i', $timestamp) : 'noch nie';
            };
            $state = function ($enabled) {
                return $enabled
                    ? '<span class="label label-success">aktiv</span>'
                    : '<span class="label label-default">inaktiv</span>';
            };

            $rows = '';
            foreach ($data['recent'] as $entry) {
                $rows .= '<tr>'
                    . '<td>' . $e($entry->reference) . '<br><small class="text-muted">' . $e($entry->detail) . '</small></td>'
                    . '<td class="text-right">' . ($entry->old_price === null ? '-' : $e(number_format((float) $entry->old_price, 2, ',', '.'))) . ' &rarr; '
                    . $e(number_format((float) $entry->new_price, 2, ',', '.')) . ' ' . $e($entry->currency) . '</td>'
                    . '</tr>';
            }
            if ($rows === '') {
                $rows = '<tr><td colspan="2" class="text-muted">Noch keine Preisänderungen.</td></tr>';
            }

            return '<div class="widget-content-padded">'
                . '<p><strong>TLD-Sync</strong> ' . $state($data['tldEnabled']) . '<br><small>Letzter Lauf: ' . $e($time($data['tldLastRun'])) . '</small>'
                . ($data['tldLastResult'] !== '' ? '<br><small class="text-muted">' . $e($data['tldLastResult']) . '</small>' : '') . '</p>'
                . '<p><strong>Domain-Sync</strong> ' . $state($data['domainEnabled']) . '<br><small>Letzter Lauf: ' . $e($time($data['domainLastRun'])) . '</small>'
                . ($data['domainLastResult'] !== '' ? '<br><small class="text-muted">' . $e($data['domainLastResult']) . '</small>' : '') . '</p>'
                . '<p>Preisänderungen (24h): <strong>' . (int) $data['changes24h'] . '</strong></p>'
                . '<table class="table table-condensed" style="margin-bottom:0">' . $rows . '</table>'
                . '</div>';
        }
    }

    try {
        $skrimeWidgetSettings = Settings::fromRegistrar();
        if ($skrimeWidgetSettings && $skrimeWidgetSettings->bool('widgetEnabled')) {
            add_hook('AdminHomeWidgets', 1, function () {
                return new SkrimeRegistrarWidget();
            });
        }
        unset($skrimeWidgetSettings);
    } catch (\Throwable $skrimeWidgetException) {
        unset($skrimeWidgetSettings, $skrimeWidgetException);
    }
}
