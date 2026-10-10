<?php

declare(strict_types=1);

namespace Nemorize\Typst\Syntax;

/** Math-specific tree, separate from code evaluation. */
final class MathParser
{
    private int $index = 0;
    private int $depth = 0;
    private array $tokens;
    private const SYMBOLS = ['alpha' => 'α', 'beta' => 'β', 'gamma' => 'γ', 'delta' => 'δ', 'theta' => 'θ', 'lambda' => 'λ', 'mu' => 'μ', 'pi' => 'π', 'sigma' => 'σ', 'phi' => 'φ', 'omega' => 'ω', 'infinity' => '∞', 'sum' => '∑', 'product' => '∏', 'integral' => '∫', 'times' => '×', 'dot' => '⋅', 'plus.minus' => '±', 'lt.eq' => '≤', 'gt.eq' => '≥', 'eq.not' => '≠', 'arrow.r' => '→', 'sin' => 'sin', 'cos' => 'cos', 'tan' => 'tan', 'log' => 'log', 'ln' => 'ln'];

    public function __construct(string $text, private readonly Span $span)
    {
        preg_match_all('/"(?:\\\\.|[^"\\\\])*"|[\p{L}]+(?:\.[\p{L}]+)*|\d+(?:\.\d+)?|!=|<=|>=|->|[^\s]/u', $text, $m);
        $this->tokens = $m[0];
    }

    public function parse(): Node
    {
        $result = $this->sequence();
        if ($this->index !== count($this->tokens)) {
            $this->error('Unexpected math delimiter.');
        }
        return $result;
    }

    private function sequence(): Node
    {
        if (++$this->depth > 128) {
            $this->error('Math nesting limit exceeded.');
        }
        $items = [];
        while ($this->peek() !== '' && !in_array($this->peek(), [')', ','], true)) {
            $item = $this->attached();
            while ($this->peek() === '/') {
                $this->index++;
                $item = new Node('fraction', $this->span, children: [$item, $this->attached()]);
            }
            $items[] = $item;
        }
        $this->depth--;
        return new Node('math-sequence', $this->span, children: $items);
    }

    private function attached(): Node
    {
        $base = $this->atom();
        $attachments = [];
        while ($this->peek() === '^' || $this->peek() === '_') {
            $kind = $this->tokens[$this->index++];
            if (isset($attachments[$kind])) {
                $this->error('Duplicate math attachment.');
            }
            $attachments[$kind] = $this->atom();
        }
        return $attachments === [] ? $base : new Node('attachment', $this->span, ['attachments' => $attachments], [$base]);
    }

    private function atom(): Node
    {
        $token = $this->peek();
        if ($token === '' || in_array($token, [')', ',', '^', '_', '/'], true)) {
            $this->error('Expected math expression.');
        }
        $this->index++;
        if ($token === '(') {
            $body = $this->sequence();
            if ($this->peek() !== ')') {
                $this->error('Unclosed math group.');
            }
            $this->index++;
            return new Node('math-group', $this->span, children: [$body]);
        }
        if ($token === 'frac' || $token === 'sqrt') {
            if ($this->peek() !== '(') {
                $this->error("Expected '(' after {$token}.");
            }
            $this->index++;
            $first = $this->sequence();
            $children = [$first];
            if ($token === 'frac') {
                if ($this->peek() !== ',') {
                    $this->error('frac() expects two math arguments.');
                }
                $this->index++;
                $children[] = $this->sequence();
            }
            if ($this->peek() !== ')') {
                $this->error('Unclosed math function.');
            }
            $this->index++;
            return new Node($token === 'frac' ? 'fraction' : 'sqrt', $this->span, children: $children);
        }
        if ($token[0] === '"') {
            $text = \Nemorize\Typst\Font\Unicode::unescape(substr($token, 1, -1));
        } elseif (isset(self::SYMBOLS[$token])) {
            $text = self::SYMBOLS[$token];
        } elseif (preg_match('/^[\p{L}]{2,}/u', $token)) {
            $this->error("Unknown or unimplemented math symbol '{$token}'.");
        } elseif (in_array($token, ['#', '&', '\\', '{', '}'], true)) {
            $this->error("Math syntax '{$token}' is not implemented yet.");
        } else {
            $text = match ($token) {
                '!=' => '≠', '<=' => '≤', '>=' => '≥', '->' => '→', default => $token,
            };
        }
        return new Node('math-text', $this->span, ['text' => $text]);
    }

    private function peek(): string
    {
        return $this->tokens[$this->index] ?? '';
    }
    private function error(string $message): never
    {
        throw new \Nemorize\Typst\CompileException($message, $this->span);
    }
}
