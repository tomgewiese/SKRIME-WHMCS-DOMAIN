<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

if (defined('ROOTDIR') && is_file(ROOTDIR . '/modules/registrars/skrime/hooks.php')) {
    require_once ROOTDIR . '/modules/registrars/skrime/hooks.php';
}
