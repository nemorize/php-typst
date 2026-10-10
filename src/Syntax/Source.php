<?php

declare(strict_types=1);

namespace Nemorize\Typst\Syntax;

final readonly class Source
{
    public string $text;

    public function __construct(string $text, public string $name = '<string>')
    {
        if (preg_match('//u', $text) !== 1) {
            throw new \InvalidArgumentException('Typst source must be valid UTF-8.');
        }
        $this->text = str_replace(["\r\n", "\r"], "\n", $text);
    }

    public function span(int $start, int $end): Span
    {
        return new Span($this, $start, $end);
    }
}
