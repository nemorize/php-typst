<?php

declare(strict_types=1);

namespace Nemorize\Typst\Runtime;

final readonly class Module
{
    public function __construct(public array $exports, public Content $content) {}
}
