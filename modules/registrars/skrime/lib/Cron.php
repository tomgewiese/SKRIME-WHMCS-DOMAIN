<?php

namespace WHMCS\Module\Registrar\Skrime;

class Cron
{
    const LOCK = 'cron';
    const LOCK_TTL = 1800;
    const RETRY_SECONDS = 900;

    public static function run(?Settings $settings = null)
    {
        $settings = $settings ?: Settings::fromRegistrar();
        if (!$settings || $settings->string('apiKey') === '') {
            return;
        }

        $tldEnabled = $settings->bool('tldSyncEnabled');
        $domainEnabled = $settings->bool('domainSyncEnabled');

        try {
            Storage::ensureSchema();
            $forcePending = (int) Storage::get('domain_force_pending', 0) === 1;
            if (!Storage::acquireLock(self::LOCK, self::LOCK_TTL)) {
                return;
            }
        } catch (\Throwable $e) {
            self::log('Cron konnte nicht gestartet werden: ' . $e->getMessage());

            return;
        }

        try {
            if (function_exists('set_time_limit')) {
                @set_time_limit(0);
            }
            $api = Api::fromSettings($settings);
            $pricelist = null;

            $retryDue = time() - (int) Storage::get('tld_last_attempt', 0) >= self::RETRY_SECONDS;
            if ($tldEnabled && $retryDue && Storage::isDue('tld_last_run', $settings->int('tldSyncInterval', 1, 8760))) {
                $tldSync = new TldPriceSync($settings, $api);
                $tldSync->run(false);
                $pricelist = $tldSync->pricelist();
            }

            if ($domainEnabled || $forcePending) {
                $domainSync = new DomainPriceSync($settings, $api);
                if (is_array($pricelist) && !empty($pricelist)) {
                    $domainSync->setPricelist($pricelist);
                }
                $domainSync->runBatch(false);
            }

            Storage::acknowledgeOrphanEvents();
            Notifier::sendDigest($settings);

            if (Storage::isDue('log_pruned', 24)) {
                Storage::pruneLog($settings->int('logRetentionDays', 0, 36500));
                Storage::set('log_pruned', time());
            }
        } catch (\Throwable $e) {
            self::log('Cron Fehler: ' . $e->getMessage());
        } finally {
            try {
                Storage::releaseLock(self::LOCK);
            } catch (\Throwable $e) {
                self::log('Cron Lock konnte nicht freigegeben werden: ' . $e->getMessage());
            }
        }
    }

    private static function log($message)
    {
        if (function_exists('logActivity')) {
            logActivity('SKRIME ' . $message);
        }
    }
}
