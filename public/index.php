<?php

declare(strict_types=1);

// PHP's built-in dev server: serve real files (CSS, JS, images) directly.
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    return false;
}

require dirname(__DIR__) . '/src/bootstrap.php';

try {
    $app = Vigil\App::boot(VIGIL_ROOT);
    $request = Vigil\Http\Request::fromGlobals($app->config->baseUrl(), (bool) $app->config->get('trust_proxy'));
    $app->handle($request)->send();
} catch (Throwable $e) {
    error_log('[vigil] ' . $e);
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Vigil hit an error and couldn't finish this request.\n\n";
    // The two errors people hit on install are worth showing in full.
    if ($e instanceof RuntimeException && str_contains($e->getMessage(), 'storage')) {
        echo $e->getMessage(), "\n";
    } else {
        echo "Details were written to the PHP error log.\n";
    }
}
