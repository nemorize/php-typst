<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = (new Finder())
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/tests/official',
        __DIR__ . '/examples',
    ])
    ->append([
        __FILE__,
        __DIR__ . '/autoload.php',
        __DIR__ . '/tests/run.php',
        __DIR__ . '/bin/php-typst',
    ]);

return (new Config())
    ->setRules([
        '@PER-CS3x0' => true,
    ])
    ->setRiskyAllowed(false)
    ->setIndent('    ')
    ->setLineEnding("\n")
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache')
    ->setFinder($finder);
