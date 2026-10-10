<?php

declare(strict_types=1);

namespace Nemorize\Typst\Syntax;

/** Code-mode token lookahead never advances the shared markup cursor. */
final class Lexer
{
    public function __construct(public readonly Cursor $cursor) {}

    public function peek(): Token
    {
        $saved = $this->cursor->offset;
        try { return $this->scan(); }
        finally { $this->cursor->offset = $saved; }
    }

    public function take(?string $expected = null): Token
    {
        $token = $this->scan();
        if ($expected !== null && $token->text !== $expected) {
            $this->cursor->error("Expected '{$expected}', found '{$token->text}'.", $token->start);
        }
        return $token;
    }

    public function accept(string $text): bool
    {
        if ($this->peek()->text !== $text) { return false; }
        $this->take();
        return true;
    }

    private function scan(): Token
    {
        $c = $this->cursor;
        do {
            $c->offset += strlen($c->match('/\G\s+/u') ?? '');
        } while ($c->comment());
        $start = $c->offset;
        if ($c->atEnd()) { return new Token('eof', '', $start, $start); }
        if ($c->char() === '"') {
            $c->offset++;
            while (!$c->atEnd()) {
                if ($c->char() === '\\') { $c->offset += 2; continue; }
                if ($c->char() === '"') {
                    $c->offset++;
                    return new Token('string', substr($c->source->text, $start, $c->offset - $start), $start, $c->offset);
                }
                $c->offset++;
            }
            $c->error('Unclosed string.', $start);
        }
        if (($text = $c->match('/\G(?:\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)(?:pt|mm|cm|in|em|fr|%)?/u')) !== null) {
            $kind = 'number';
        } elseif (($text = $c->match('/\G[\p{L}_][\p{L}\p{N}_-]*/u')) !== null) {
            $kind = 'identifier';
        } else {
            $text = $c->match('/\G(?:=>|==|!=|<=|>=|\.\.|[()\[\]{},:;.+*\/%=<>!\-$])/u') ?? $c->char();
            $kind = 'symbol';
        }
        $c->offset += strlen($text);
        return new Token($kind, $text, $start, $c->offset);
    }
}
