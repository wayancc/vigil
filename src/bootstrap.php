<?php

declare(strict_types=1);

/*
 * Vigil has no Composer dependencies. This file is the whole autoloader.
 */

if (PHP_VERSION_ID < 80200) {
    http_response_code(500);
    exit('Vigil needs PHP 8.2 or newer. This server runs PHP ' . PHP_VERSION . '.');
}

foreach (['pdo_sqlite', 'curl', 'openssl'] as $ext) {
    if (!extension_loaded($ext)) {
        http_response_code(500);
        exit("Vigil needs the PHP extension \"{$ext}\". Enable it in your host's PHP settings.");
    }
}

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Vigil\\')) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 6)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

define('VIGIL_ROOT', dirname(__DIR__));
define('VIGIL_VERSION', '0.1.0');
