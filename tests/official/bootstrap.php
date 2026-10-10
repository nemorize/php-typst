<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/autoload.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Nemorize\\Typst\\Tests\\Official\\';
    if (str_starts_with($class, $prefix)) {
        $path = __DIR__ . '/' . substr($class, strlen($prefix)) . '.php';
        if (is_file($path)) { require $path; }
    }
});
