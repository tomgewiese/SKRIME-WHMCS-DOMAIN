<?php

namespace WHMCS\Module\Registrar\Skrime;

use WHMCS\Database\Capsule;

class ClientDns
{
    const SUPPORTED = ['A', 'AAAA', 'CNAME', 'ALIAS', 'MX', 'SRV', 'TXT', 'CAA', 'PTR', 'TLSA', 'DS', 'DNSKEY'];

    public static function domainId(array $vars)
    {
        foreach (['domainid', 'id'] as $key) {
            if (isset($vars[$key]) && (int) $vars[$key] > 0) {
                return (int) $vars[$key];
            }
        }
        foreach (['domainid', 'id'] as $key) {
            if (isset($_REQUEST[$key]) && (int) $_REQUEST[$key] > 0) {
                return (int) $_REQUEST[$key];
            }
        }

        return 0;
    }

    public static function isSkrimeDomain($domainId)
    {
        if ($domainId <= 0) {
            return false;
        }
        $registrar = Capsule::table('tbldomains')->where('id', (int) $domainId)->value('registrar');

        return strtolower((string) $registrar) === Settings::MODULE;
    }

    public static function editorUrl($domainId)
    {
        return 'clientarea.php?action=domaindetails&id=' . (int) $domainId . '&modop=custom&a=dnszone';
    }

    public static function redirectTarget(array $vars, ?Settings $settings = null)
    {
        $settings = $settings ?: Settings::fromRegistrar();
        if (!$settings || $settings->choice('dnsEditor') !== 'skrime') {
            return null;
        }
        $domainId = self::domainId($vars);

        return self::isSkrimeDomain($domainId) ? self::editorUrl($domainId) : null;
    }

    public static function filterScript(array $vars, ?Settings $settings = null)
    {
        $template = isset($vars['templatefile']) ? (string) $vars['templatefile'] : '';
        if ($template !== 'clientareadomaindns') {
            return '';
        }
        $settings = $settings ?: Settings::fromRegistrar();
        if (!$settings || $settings->choice('dnsEditor') !== 'whmcs') {
            return '';
        }
        if (!self::isSkrimeDomain(self::domainId($vars))) {
            return '';
        }

        return '<script>(function(){var allowed=' . json_encode(self::SUPPORTED) . ';'
            . 'var selects=document.querySelectorAll(\'select[name^="dnsrecordtype"]\');'
            . 'for(var i=0;i<selects.length;i++){var options=selects[i].querySelectorAll("option");'
            . 'for(var j=0;j<options.length;j++){if(allowed.indexOf(options[j].value.toUpperCase())===-1&&!options[j].selected){options[j].parentNode.removeChild(options[j]);}}}})();</script>';
    }
}
