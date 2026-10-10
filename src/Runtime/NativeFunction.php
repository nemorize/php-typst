<?php

declare(strict_types=1);

namespace Nemorize\Typst\Runtime;

/** A trusted host callback; Typst source cannot create or resolve PHP callbacks. */
final readonly class NativeFunction
{
    public function __construct(public string $name, public \Closure $callback) {}
}
