<?php

declare(strict_types=1);

namespace Nemorize\Typst\Syntax;

use Nemorize\Typst\Font\Unicode;

final class CodeParser
{
    private int $depth = 0;
    private const PRECEDENCE = ['or' => 1, 'and' => 2, '==' => 3, '!=' => 3, '<' => 4, '>' => 4, '<=' => 4, '>=' => 4, '+' => 5, '-' => 5, '*' => 6, '/' => 6];

    public function __construct(private readonly Lexer $lexer, private readonly Parser $markup) {}

    private function node(string $kind, int $start, array $data = [], array $children = []): Node
    {
        return new Node($kind, $this->lexer->cursor->source->span($start, $this->lexer->cursor->offset), $data, $children);
    }

    public function statement(bool $embedded = true): Node
    {
        $start = $this->lexer->peek()->start;
        $word = $this->lexer->peek()->text;
        if ($word === 'let') {
            $this->lexer->take();
            $name = $this->identifier();
            $params = $this->lexer->peek()->text === '(' ? $this->parameters() : null;
            $this->lexer->take('=');
            $value = $this->expression();
            if ($params !== null) { $value = $this->node('function', $start, ['parameters' => $params], [$value]); }
            $result = $this->node('let', $start, ['name' => $name], [$value]);
        } elseif ($word === 'set') {
            $this->lexer->take();
            $name = $this->identifier();
            $result = $this->node('set', $start, ['name' => $name], $this->arguments());
        } elseif ($word === 'import' || $word === 'include') {
            $this->lexer->take();
            $path = $this->expression(0, false);
            $names = null;
            $alias = null;
            if ($word === 'import' && $this->lexer->accept(':')) {
                $names = [];
                if ($this->lexer->accept('*')) { $names = ['*']; }
                else { do { $names[] = $this->identifier(); } while ($this->lexer->accept(',')); }
            } elseif ($word === 'import' && $this->lexer->accept('as')) { $alias = $this->identifier(); }
            $result = $this->node($word, $start, ['names' => $names, 'alias' => $alias], [$path]);
        } elseif (in_array($word, ['show', 'context', 'while', 'return', 'break', 'continue'], true)) {
            $this->lexer->cursor->error("'{$word}' is not implemented yet.", $start);
        } else {
            $result = $this->expression($embedded ? 8 : 0);
        }
        $this->lexer->accept(';');
        return $result;
    }

    public function expression(int $minimum = 0, bool $contentCalls = true): Node
    {
        if (++$this->depth > 128) { $this->lexer->cursor->error('Expression nesting limit exceeded.'); }
        $t = $this->lexer->take();
        $start = $t->start;
        if ($t->kind === 'number') {
            preg_match('/^(\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)(.*)$/', $t->text, $m);
            $left = $this->node('literal', $start, ['value' => str_contains($m[1], '.') || stripos($m[1], 'e') !== false ? (float) $m[1] : (int) $m[1], 'unit' => $m[2]]);
        } elseif ($t->kind === 'string') {
            try { $value = Unicode::unescape(substr($t->text, 1, -1)); }
            catch (\InvalidArgumentException $e) { $this->lexer->cursor->error($e->getMessage(), $start); }
            $left = $this->node('literal', $start, ['value' => $value]);
        } elseif (in_array($t->text, ['-', '+', 'not'], true)) {
            $left = $this->node('unary', $start, ['operator' => $t->text], [$this->expression(7, $contentCalls)]);
        } elseif ($t->text === '[') {
            $left = $this->markup->markup(']');
            $this->lexer->take(']');
        } elseif ($t->text === '{') {
            $children = [];
            while ($this->lexer->peek()->text !== '}') {
                if ($this->lexer->peek()->kind === 'eof') { $this->lexer->cursor->error('Unclosed code block.', $start); }
                $children[] = $this->statement(false);
            }
            $this->lexer->take('}');
            $left = $this->node('block', $start, [], $children);
        } elseif ($t->text === '(') {
            $items = [];
            $dict = false;
            $comma = false;
            if ($this->lexer->accept(':')) { $dict = true; }
            elseif ($this->lexer->peek()->text !== ')') {
                do {
                    $item = $this->expression();
                    if ($this->lexer->accept(':')) {
                        $dict = true;
                        if ($item->kind !== 'identifier') { $this->lexer->cursor->error('Dictionary keys must be identifiers.', $item->span->start); }
                        $item = $this->node('entry', $item->span->start, ['name' => $item->data['name']], [$this->expression()]);
                    }
                    $items[] = $item;
                    if (!$this->lexer->accept(',')) { break; }
                    $comma = true;
                } while ($this->lexer->peek()->text !== ')');
            }
            $this->lexer->take(')');
            if ($this->lexer->accept('=>')) {
                $params = [];
                foreach ($items as $item) {
                    if ($item->kind !== 'identifier') { $this->lexer->cursor->error('Expected function parameter.', $item->span->start); }
                    $params[$item->data['name']] = null;
                }
                $left = $this->node('function', $start, ['parameters' => $params], [$this->expression()]);
            } else {
                $left = !$dict && !$comma && count($items) === 1 ? $items[0] : $this->node($dict ? 'dictionary' : 'array', $start, [], $items);
            }
        } elseif ($t->text === 'if') {
            $condition = $this->expression(0, false);
            $yes = $this->expression(0, false);
            $children = [$condition, $yes];
            if ($this->lexer->accept('else')) { $children[] = $this->expression(0, false); }
            $left = $this->node('if', $start, [], $children);
        } elseif ($t->text === 'for') {
            $name = $this->identifier();
            $this->lexer->take('in');
            $items = $this->expression(0, false);
            $body = $this->expression(0, false);
            $left = $this->node('for', $start, ['name' => $name], [$items, $body]);
        } elseif ($t->kind === 'identifier') {
            $left = match ($t->text) {
                'true', 'false', 'none' => $this->node('literal', $start, ['value' => match ($t->text) { 'true' => true, 'false' => false, default => null }]),
                default => $this->node('identifier', $start, ['name' => $t->text]),
            };
            if ($this->lexer->accept('=>')) {
                $left = $this->node('function', $start, ['parameters' => [$t->text => null]], [$this->expression()]);
            }
        } else {
            $this->lexer->cursor->error("Unexpected token '{$t->text}' in expression.", $start);
        }

        while (true) {
            $next = $this->lexer->peek();
            if ($next->text === '.') {
                if (preg_match('/\G\.[\p{L}_]/u', $this->lexer->cursor->source->text, $m, 0, $next->start) !== 1) { break; }
                if ($minimum === 8 && $next->start !== $this->lexer->cursor->offset) { break; }
                $this->lexer->take();
                $left = $this->node('member', $start, ['name' => $this->identifier()], [$left]);
            } elseif ($next->text === '(') {
                if ($minimum === 8 && $next->start !== $this->lexer->cursor->offset) { break; }
                $left = $this->node('call', $start, [], [$left, ...$this->arguments()]);
            } elseif ($next->text === '[' && $contentCalls && in_array($left->kind, ['identifier', 'member', 'call'], true)) {
                // A trailing content argument must be adjacent to the callee.
                if ($next->start !== $this->lexer->cursor->offset) { break; }
                $this->lexer->take();
                $body = $this->markup->markup(']');
                $this->lexer->take(']');
                $arg = $this->node('argument', $next->start, ['name' => null], [$body]);
                $left = $left->kind === 'call'
                    ? $this->node('call', $start, [], [...$left->children, $arg])
                    : $this->node('call', $start, [], [$left, $arg]);
            } elseif (isset(self::PRECEDENCE[$next->text]) && self::PRECEDENCE[$next->text] >= $minimum) {
                $this->lexer->take();
                $right = $this->expression(self::PRECEDENCE[$next->text] + 1, $contentCalls);
                $left = $this->node('binary', $start, ['operator' => $next->text], [$left, $right]);
            } else { break; }
        }
        $this->depth--;
        return $left;
    }

    /** @return list<Node> */
    private function arguments(): array
    {
        $this->lexer->take('(');
        $args = [];
        while ($this->lexer->peek()->text !== ')') {
            $value = $this->expression();
            $name = null;
            if ($this->lexer->accept(':')) {
                if ($value->kind !== 'identifier') { $this->lexer->cursor->error('Expected named argument.', $value->span->start); }
                $name = $value->data['name'];
                $value = $this->expression();
            }
            $args[] = $this->node('argument', $value->span->start, ['name' => $name], [$value]);
            if (!$this->lexer->accept(',')) { break; }
        }
        $this->lexer->take(')');
        return $args;
    }

    /** @return array<string, ?Node> */
    private function parameters(): array
    {
        $this->lexer->take('(');
        $params = [];
        while ($this->lexer->peek()->text !== ')') {
            $name = $this->identifier();
            if (array_key_exists($name, $params)) { $this->lexer->cursor->error("Duplicate parameter '{$name}'."); }
            $params[$name] = $this->lexer->accept(':') ? $this->expression() : null;
            if (!$this->lexer->accept(',')) { break; }
        }
        $this->lexer->take(')');
        return $params;
    }

    private function identifier(): string
    {
        $token = $this->lexer->take();
        if ($token->kind !== 'identifier') { $this->lexer->cursor->error('Expected identifier.', $token->start); }
        return $token->text;
    }
}
