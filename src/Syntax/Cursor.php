<?php

declare(strict_types=1);

namespace Nemorize\Typst\Syntax;

use Nemorize\Typst\CompileException;

final class Cursor
{
    public int $offset = 0;

    public function __construct(public readonly Source $source) {}

    public function atEnd(): bool { return $this->offset >= strlen($this->source->text); }
    public function starts(string $text): bool { return substr_compare($this->source->text, $text, $this->offset, strlen($text)) === 0; }
    public function char(): string { return $this->source->text[$this->offset] ?? ''; }

    public function match(string $pattern): ?string
    {
        if (preg_match($pattern, $this->source->text, $matches, 0, $this->offset) === 1) {
            return $matches[0];
        }
        return null;
    }

    public function error(string $message, ?int $start = null): never
    {
        throw new CompileException($message, $this->source->span($start ?? $this->offset, $this->offset));
    }

    public function comment(): bool
    {
        if ($this->starts('//')) {
            $end = strpos($this->source->text, "\n", $this->offset);
            $this->offset = $end === false ? strlen($this->source->text) : $end;
            return true;
        }
        if (!$this->starts('/*')) {
            return false;
        }
        $start = $this->offset;
        $this->offset += 2;
        $depth = 1;
        while (!$this->atEnd()) {
            if ($this->starts('/*')) { $depth++; $this->offset += 2; }
            elseif ($this->starts('*/')) { $depth--; $this->offset += 2; if ($depth === 0) { return true; } }
            else { $this->offset++; }
        }
        $this->error('Unclosed block comment.', $start);
    }
}
