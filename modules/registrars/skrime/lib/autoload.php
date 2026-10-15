<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

if (!defined('SKRIME_AUTOLOADER')) {
    define('SKRIME_AUTOLOADER', true);

    spl_autoload_register(function ($class) {
        $prefix = 'WHMCS\\Module\\Registrar\\Skrime\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    });
}
