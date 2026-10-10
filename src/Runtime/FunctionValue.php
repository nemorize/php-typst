<?php

declare(strict_types=1);

namespace Nemorize\Typst\Runtime;

use Nemorize\Typst\Syntax\Node;

final readonly class FunctionValue
{
    public function __construct(public array $parameters, public Node $body, public Environment $environment) {}
}
