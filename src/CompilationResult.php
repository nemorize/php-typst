<?php

declare(strict_types=1);

namespace Nemorize\Typst;

final readonly class CompilationResult
{
    public function __construct(public string $pdf, public int $pageCount) {}

    /** Saves atomically; a failed compilation never truncates an existing output. */
    public function save(string $path): void
    {
        $directory = realpath(dirname($path));
        if ($directory === false || !is_writable($directory) || is_dir($path) || basename($path) === '') {
            throw new \RuntimeException('Output parent directory must exist and be writable.');
        }
        $temporary = tempnam($directory, '.php-typst-');
        if ($temporary === false) {
            throw new \RuntimeException('Cannot create an output file.');
        }
        try {
            if (file_put_contents($temporary, $this->pdf) !== strlen($this->pdf) || !rename($temporary, $path)) {
                throw new \RuntimeException("Cannot write output '{$path}'.");
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
