<?php

declare(strict_types=1);

namespace Nemorize\Typst\Syntax;

final readonly class Token
{
    public function __construct(public string $kind, public string $text, public int $start, public int $end) {}
}
