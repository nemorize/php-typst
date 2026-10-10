<?php

declare(strict_types=1);

namespace Nemorize\Typst\Syntax;

use Nemorize\Typst\Font\Unicode;

final class Parser
{
    private int $depth = 0;

    public function __construct(private readonly Cursor $cursor) {}

    public static function parse(Source $source): Node
    {
        return (new self(new Cursor($source)))->markup();
    }

    public function markup(?string $stop = null): Node
    {
        $c = $this->cursor;
        $start = $c->offset;
        if (++$this->depth > 128) { $c->error('Markup nesting limit exceeded.'); }
        $nodes = [];
        while (!$c->atEnd() && ($stop === null || $c->char() !== $stop)) {
            $at = $c->offset;
            if ($c->comment()) { continue; }
            if ($c->starts('https://') || $c->starts('http://')) { $c->error('Hyperlinks are not implemented yet. Use raw text to display a URL.'); }
            $lineStart = $at === 0 || $c->source->text[$at - 1] === "\n";
            if ($lineStart && ($marker = $c->match('/\G(?:={1,6}|[-+])[ \t]+/u')) !== null) {
                $c->offset += strlen($marker);
                $body = $this->markup("\n");
                $kind = $marker[0] === '=' ? 'heading' : 'item';
                $data = $kind === 'heading' ? ['level' => strlen(trim($marker))] : ['ordered' => $marker[0] === '+'];
                $nodes[] = new Node($kind, $c->source->span($at, $c->offset), $data, $body->children);
                if ($c->char() === "\n") { $c->offset++; }
                continue;
            }
            $char = $c->char();
            if ($char === '#') {
                $c->offset++;
                $nodes[] = (new CodeParser(new Lexer($c), $this))->statement();
            } elseif ($char === '*' || $char === '_') {
                $c->offset++;
                $body = $this->markup($char);
                if ($c->char() !== $char) { $c->error('Unclosed emphasis.', $at); }
                $c->offset++;
                $nodes[] = new Node($char === '*' ? 'strong' : 'emph', $c->source->span($at, $c->offset), [], $body->children);
            } elseif ($char === '[') {
                $c->offset++;
                $body = $this->markup(']');
                if ($c->char() !== ']') { $c->error('Unclosed content block.', $at); }
                $c->offset++;
                $nodes[] = $body;
            } elseif ($char === ']') {
                $c->error('Unexpected closing content delimiter.');
            } elseif ($char === '$') {
                $c->offset++;
                $mathStart = $c->offset;
                while (!$c->atEnd() && $c->char() !== '$') {
                    if ($c->char() === '\\') { $c->offset++; }
                    $c->offset++;
                }
                if ($c->atEnd()) { $c->error('Unclosed equation.', $at); }
                $math = substr($c->source->text, $mathStart, $c->offset - $mathStart);
                $c->offset++;
                $nodes[] = new Node('math', $c->source->span($at, $c->offset), ['source' => $math, 'block' => preg_match('/^\s.*\s$/us', $math) === 1]);
            } elseif ($char === '`') {
                $fence = $c->starts('```') ? '```' : '`';
                $c->offset += strlen($fence);
                $end = strpos($c->source->text, $fence, $c->offset);
                if ($end === false) { $c->error('Unclosed raw text.', $at); }
                $raw = substr($c->source->text, $c->offset, $end - $c->offset);
                if ($fence === '```') { $raw = preg_replace('/^[a-zA-Z0-9_-]*\r?\n/', '', $raw); }
                $c->offset = $end + strlen($fence);
                $nodes[] = new Node('raw', $c->source->span($at, $c->offset), ['text' => $raw, 'block' => $fence === '```']);
            } elseif ($char === '\\') {
                $escape = $c->match('/\G\\\\u\{[0-9a-fA-F]+\}/u');
                if ($escape !== null) {
                    $c->offset += strlen($escape);
                    try { $value = Unicode::unescape($escape); }
                    catch (\InvalidArgumentException $e) { $c->error($e->getMessage(), $at); }
                    $nodes[] = new Node('text', $c->source->span($at, $c->offset), ['text' => $value]);
                } else {
                    $c->offset++;
                    if ($c->atEnd() || preg_match('/\s/u', $c->char())) {
                        $nodes[] = new Node('linebreak', $c->source->span($at, $c->offset));
                    } else {
                        $value = $c->match('/\G./us');
                        $c->offset += strlen($value);
                        $nodes[] = new Node('text', $c->source->span($at, $c->offset), ['text' => $value]);
                    }
                }
            } elseif ($char === "\r" || $char === "\n") {
                $space = $c->match('/\G(?:\r?\n)[ \t]*(?:\r?\n[ \t]*)*/u');
                $c->offset += strlen($space);
                $nodes[] = substr_count($space, "\n") > 1
                    ? new Node('parbreak', $c->source->span($at, $c->offset))
                    : new Node('text', $c->source->span($at, $c->offset), ['text' => ' ']);
            } else {
                if ($char === '@' || $char === '<') { $c->error('Labels and references are not implemented yet. Escape this character to print it.'); }
                $text = $c->match('/\G(?:(?!https?:\/\/)[^#\[\]$*_`\\\\\r\n\/@<])+/u') ?? $char;
                $c->offset += strlen($text);
                $nodes[] = new Node('text', $c->source->span($at, $c->offset), ['text' => $text]);
            }
        }
        $this->depth--;
        return new Node('content', $c->source->span($start, $c->offset), [], $nodes);
    }
}
