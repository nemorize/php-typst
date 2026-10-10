<?php

declare(strict_types=1);

if (PHP_VERSION_ID < 80500) {
    throw new RuntimeException('php-typst requires PHP 8.5 or later.');
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'Nemorize\\Typst\\';
    if (str_starts_with($class, $prefix)) {
        $path = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
});
