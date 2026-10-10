<?php

declare(strict_types=1);

namespace Nemorize\Typst\Runtime;

use Nemorize\Typst\Syntax\Span;

final readonly class Content
{
    /** @param array<string, mixed> $attributes @param list<Content> $children */
    public function __construct(public string $kind, public array $attributes = [], public array $children = [], public ?Span $span = null) {}

    public static function text(string $text, ?Span $span = null): self { return new self('text', ['text' => $text], span: $span); }
    public static function sequence(array $children, ?Span $span = null): self { return new self('sequence', children: $children, span: $span); }
}
