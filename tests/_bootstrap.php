<?php

if (!defined('WHMCS')) {
    define('WHMCS', true);
}

require_once __DIR__ . '/../modules/registrars/skrime/skrime.php';

if (!function_exists('logModuleCall')) {
    function logModuleCall($module, $action, $request, $response, $data = '', $variablesToMask = array())
    {
    }
}
