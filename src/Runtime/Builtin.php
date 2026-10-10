<?php

declare(strict_types=1);

namespace Nemorize\Typst\Runtime;

final readonly class Builtin
{
    public function __construct(public string $name) {}
}
