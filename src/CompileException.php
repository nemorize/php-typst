<?php

declare(strict_types=1);

namespace Nemorize\Typst;

use Nemorize\Typst\Syntax\Span;

final class CompileException extends \RuntimeException
{
    public function __construct(public readonly string $diagnosticMessage, public readonly ?Span $span = null)
    {
        parent::__construct(($span === null ? '' : $span->location() . ': ') . $diagnosticMessage);
    }
}
