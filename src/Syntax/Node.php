<?php

declare(strict_types=1);

namespace Nemorize\Typst\Syntax;

/** Immutable syntax tree. Internal node attributes depend on kind. */
final readonly class Node
{
    /** @param array<string, mixed> $data @param list<Node> $children */
    public function __construct(public string $kind, public Span $span, public array $data = [], public array $children = []) {}
}
