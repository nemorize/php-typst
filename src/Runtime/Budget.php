<?php

declare(strict_types=1);

namespace Nemorize\Typst\Runtime;

use Nemorize\Typst\CompileException;
use Nemorize\Typst\Syntax\Span;

final class Budget
{
    private int $steps = 0;

    public function __construct(private readonly int $maximum = 200000) {}

    public function tick(Span $span): void
    {
        if (++$this->steps > $this->maximum) {
            throw new CompileException('Evaluation step limit exceeded.', $span);
        }
    }
}
