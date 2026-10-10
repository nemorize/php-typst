<?php

declare(strict_types=1);

namespace Nemorize\Typst\Tests\Official;

final readonly class TestCase
{
    /** @param list<string> $attributes @param list<Diagnostic> $diagnostics */
    public function __construct(
        public string $name,
        public string $file,
        public int $headerLine,
        public array $attributes,
        public string $source,
        public array $diagnostics,
        public array $sourceLines,
    ) {}

    public function isEval(): bool
    {
        return in_array('eval', $this->attributes, true);
    }
}
