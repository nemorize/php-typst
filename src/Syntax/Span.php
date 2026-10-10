<?php

declare(strict_types=1);

namespace Nemorize\Typst\Syntax;

final readonly class Span
{
    public function __construct(public Source $source, public int $start, public int $end) {}

    public function location(): string
    {
        $prefix = substr($this->source->text, 0, $this->start);
        $line = substr_count($prefix, "\n") + 1;
        $last = strrpos($prefix, "\n");
        $columnText = substr($prefix, $last === false ? 0 : $last + 1);
        $column = preg_match_all('/./us', $columnText) + 1;
        return "{$this->source->name}:{$line}:{$column}";
    }
}
