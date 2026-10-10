<?php

declare(strict_types=1);

namespace Nemorize\Typst\Runtime;

use Nemorize\Typst\CompileException;
use Nemorize\Typst\Syntax\Span;

final class Environment
{
    /** @var array<string, mixed> */
    public array $values = [];

    public function __construct(private readonly ?self $parent = null) {}

    public function get(string $name, Span $span): mixed
    {
        if (array_key_exists($name, $this->values)) {
            return $this->values[$name];
        }
        if ($this->parent !== null) {
            return $this->parent->get($name, $span);
        }
        throw new CompileException("Unknown identifier '{$name}' (possibly unimplemented).", $span);
    }
}
