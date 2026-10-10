<?php

declare(strict_types=1);

namespace Nemorize\Typst\Runtime;

use Nemorize\Typst\CompileException;
use Nemorize\Typst\Syntax\Node;

final class Evaluator
{
    private int $depth = 0;

    public function __construct(private readonly ModuleResolver $modules) {}

    public function document(Node $node, Environment $environment): Content
    {
        return Content::sequence(array_map(fn(Node $child) => $this->content($this->evaluate($child, $environment), $child), $node->children), $node->span);
    }

    public function evaluate(Node $node, Environment $env): mixed
    {
        $this->modules->budget->tick($node->span);
        if (++$this->depth > 128) {
            throw new CompileException('Evaluation nesting limit exceeded.', $node->span);
        }
        try {
            return $this->value($node, $env);
        } catch (\InvalidArgumentException|\DivisionByZeroError|\TypeError $e) {
            throw new CompileException($e->getMessage(), $node->span);
        } finally {
            $this->depth--;
        }
    }

    private function value(Node $n, Environment $env): mixed
    {
        $d = $n->data;
        switch ($n->kind) {
            case 'literal': return ($d['unit'] ?? '') === '' ? $d['value'] : new Dimension((float) $d['value'], $d['unit']);
            case 'identifier': return $env->get($d['name'], $n->span);
            case 'text': return Content::text($d['text'], $n->span);
            case 'content': return $this->document($n, new Environment($env));
            case 'heading': case 'strong': case 'emph': case 'item':
                return new Content($n->kind, $d, $this->document($n, new Environment($env))->children, $n->span);
            case 'math': case 'raw': case 'linebreak': case 'parbreak': return new Content($n->kind, $d, span: $n->span);
            case 'array': return array_map(fn($v) => $this->evaluate($v, $env), $n->children);
            case 'dictionary':
                $result = [];
                foreach ($n->children as $entry) {
                    if ($entry->kind !== 'entry') {
                        throw new CompileException('Cannot mix dictionary entries and array values.', $entry->span);
                    }
                    if (array_key_exists($entry->data['name'], $result)) {
                        throw new CompileException('Duplicate dictionary key.', $entry->span);
                    }
                    $result[$entry->data['name']] = $this->evaluate($entry->children[0], $env);
                }
                return $result;
            case 'let':
                $env->values[$d['name']] = $this->evaluate($n->children[0], $env);
                return null;
            case 'function': return new FunctionValue($d['parameters'], $n->children[0], $env);
            case 'block':
                $local = new Environment($env);
                $result = null;
                foreach ($n->children as $child) {
                    $value = $this->evaluate($child, $local);
                    if ($value === null) {
                        continue;
                    }
                    if ($result === null) {
                        $result = $value;
                    } elseif ($result instanceof Content || $value instanceof Content) {
                        $result = Content::sequence([$this->content($result, $n), $this->content($value, $n)], $n->span);
                    } else {
                        throw new CompileException('Only content values can be joined in this code block.', $n->span);
                    }
                }
                return $result;
            case 'member':
                $object = $this->evaluate($n->children[0], $env);
                if ($object instanceof Module) {
                    $object = $object->exports;
                }
                if ($object instanceof Builtin) {
                    return StandardLibrary::member($object, $d['name'], $n->span);
                }
                if (is_array($object) && array_key_exists($d['name'], $object)) {
                    return $object[$d['name']];
                }
                throw new CompileException("Unknown field '{$d['name']}'.", $n->span);
            case 'call':
                $callee = $this->evaluate($n->children[0], $env);
                [$positional, $named] = $this->arguments(array_slice($n->children, 1), $env);
                if ($callee instanceof NativeFunction) {
                    return ($callee->callback)($positional, $named, $n->span);
                }
                if ($callee instanceof Builtin) {
                    $spans = [];
                    foreach (array_slice($n->children, 1) as $arg) {
                        $key = $arg->data['name'];
                        if ($key === null) {
                            $spans[] = $arg->children[0]->span;
                        } else {
                            $spans[$key] = $arg->children[0]->span;
                        }
                    }
                    return StandardLibrary::call($callee->name, $positional, $named, $n->span, $spans);
                }
                if (!$callee instanceof FunctionValue) {
                    throw new CompileException('Value is not callable.', $n->span);
                }
                $local = new Environment($callee->environment);
                foreach ($callee->parameters as $name => $default) {
                    if ($default === null && $positional !== []) {
                        $value = array_shift($positional);
                    } elseif (array_key_exists($name, $named)) {
                        $value = $named[$name];
                        unset($named[$name]);
                    } elseif ($default !== null) {
                        $value = $this->evaluate($default, $callee->environment);
                    } else {
                        throw new CompileException("Missing function argument '{$name}'.", $n->span);
                    }
                    $local->values[$name] = $value;
                }
                if ($positional !== [] || $named !== []) {
                    throw new CompileException('Unexpected function arguments.', $n->span);
                }
                return $this->evaluate($callee->body, $local);
            case 'unary':
                $value = $this->evaluate($n->children[0], $env);
                if ($d['operator'] === 'not') {
                    return !$this->boolean($value, $n);
                }
                if ($value instanceof Dimension) {
                    return new Dimension($d['operator'] === '-' ? -$value->value : $value->value, $value->unit);
                }
                $this->numeric($value, $n);
                return $d['operator'] === '-' ? -$value : $value;
            case 'binary': return $this->binary($n, $env);
            case 'if':
                $condition = $this->boolean($this->evaluate($n->children[0], $env), $n);
                return $condition ? $this->evaluate($n->children[1], $env) : (isset($n->children[2]) ? $this->evaluate($n->children[2], $env) : null);
            case 'for':
                $items = $this->evaluate($n->children[0], $env);
                if (!is_array($items) || !array_is_list($items)) {
                    throw new CompileException('For loop expects an array.', $n->span);
                }
                $parts = [];
                foreach ($items as $item) {
                    $local = new Environment($env);
                    $local->values[$d['name']] = $item;
                    $parts[] = $this->content($this->evaluate($n->children[1], $local), $n);
                }
                return Content::sequence($parts, $n->span);
            case 'set':
                [$positional, $named] = $this->arguments($n->children, $env);
                return StandardLibrary::set($d['name'], $positional, $named, $n->span);
            case 'import': case 'include':
                $path = $this->evaluate($n->children[0], $env);
                if (!is_string($path)) {
                    throw new CompileException('Module path must be a string.', $n->span);
                }
                $module = $this->modules->load($path, $n->span);
                if ($n->kind === 'include') {
                    return $module->content;
                }
                if ($d['names'] === null) {
                    $env->values[$d['alias'] ?? pathinfo($path, PATHINFO_FILENAME)] = $module;
                } else {
                    foreach ($d['names'] === ['*'] ? array_keys($module->exports) : $d['names'] as $name) {
                        if (!array_key_exists($name, $module->exports)) {
                            throw new CompileException("Module does not export '{$name}'.", $n->span);
                        }
                        $env->values[$name] = $module->exports[$name];
                    }
                }
                return null;
        }
        throw new CompileException("Unimplemented syntax node '{$n->kind}'.", $n->span);
    }

    private function arguments(array $args, Environment $env): array
    {
        $positional = $named = [];
        foreach ($args as $arg) {
            $value = $this->evaluate($arg->children[0], $env);
            $name = $arg->data['name'];
            if ($name === null) {
                $positional[] = $value;
            } else {
                if (array_key_exists($name, $named)) {
                    throw new CompileException("Duplicate argument '{$name}'.", $arg->span);
                }
                $named[$name] = $value;
            }
        }
        return [$positional, $named];
    }

    public function content(mixed $value, Node $node): Content
    {
        if ($value instanceof Content) {
            return $value;
        }
        if ($value === null) {
            return Content::sequence([], $node->span);
        }
        if (is_bool($value)) {
            return Content::text($value ? 'true' : 'false', $node->span);
        }
        if (is_string($value) || is_int($value) || is_float($value)) {
            return Content::text((string) $value, $node->span);
        }
        throw new CompileException('Value cannot be displayed as content.', $node->span);
    }

    private function binary(Node $node, Environment $env): mixed
    {
        $op = $node->data['operator'];
        $a = $this->evaluate($node->children[0], $env);
        if ($op === 'and' && !$this->boolean($a, $node)) {
            return false;
        }
        if ($op === 'or' && $this->boolean($a, $node)) {
            return true;
        }
        $b = $this->evaluate($node->children[1], $env);
        if ($op === 'and' || $op === 'or') {
            return $this->boolean($b, $node);
        }
        if ($op === '==' || $op === '!=') {
            $same = ValueEquality::equals($a, $b);
            return $op === '==' ? $same : !$same;
        }
        if ($op === '+' && is_string($a) && is_string($b)) {
            return $a . $b;
        }
        if ($op === '+' && $a instanceof Content && $b instanceof Content) {
            return Content::sequence([$a, $b], $node->span);
        }
        if ($a instanceof Dimension && $b instanceof Dimension && in_array($op, ['+', '-'], true)) {
            if ($a->unit === $b->unit) {
                return new Dimension($op === '+' ? $a->value + $b->value : $a->value - $b->value, $a->unit);
            }
            return new Dimension($op === '+' ? $a->points() + $b->points() : $a->points() - $b->points(), 'pt');
        }
        if ($a instanceof Dimension && (is_int($b) || is_float($b)) && in_array($op, ['*', '/'], true)) {
            return new Dimension($op === '*' ? $a->value * $b : $a->value / $b, $a->unit);
        }
        $this->numeric($a, $node);
        $this->numeric($b, $node);
        return match ($op) {
            '+' => $a + $b, '-' => $a - $b, '*' => $a * $b, '/' => $a / $b,
            '<' => $a < $b, '>' => $a > $b, '<=' => $a <= $b, '>=' => $a >= $b,
            default => throw new CompileException("Unsupported operator '{$op}'.", $node->span),
        };
    }

    private function numeric(mixed $value, Node $n): void
    {
        if (!is_int($value) && !is_float($value)) {
            throw new CompileException('Expected a number.', $n->span);
        }
    }

    private function boolean(mixed $value, Node $n): bool
    {
        if (!is_bool($value)) {
            throw new CompileException('Expected a boolean.', $n->span);
        }
        return $value;
    }
}
