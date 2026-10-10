<?php

declare(strict_types=1);

namespace Nemorize\Typst\Tests\Official;

use Nemorize\Typst\CompileException;
use Nemorize\Typst\Runtime\{Builtin, Content, Dimension, Environment, FunctionValue, Module, NativeFunction, ValueEquality};
use Nemorize\Typst\Syntax\Span;

/** Host equivalents of the test-only functions in upstream tests/src/world.rs. */
final class Helpers
{
    public int $assertions = 0;
    public array $printed = [];

    public function install(Environment $environment): void
    {
        $environment->values['test'] = new NativeFunction('test', function (array $p, array $n, Span $span): null {
            $this->arity($p, $n, $span);
            $this->assertions++;
            if (!ValueEquality::equals($p[0], $p[1])) {
                throw new CompileException('Assertion failed: ' . self::describe($p[0]) . ' != ' . self::describe($p[1]), $span);
            }
            return null;
        });
        $environment->values['test-repr'] = new NativeFunction('test-repr', function (array $p, array $n, Span $span): null {
            $this->arity($p, $n, $span);
            $this->assertions++;
            $a = self::repr($p[0], $span);
            $b = self::repr($p[1], $span);
            if ($a !== $b) {
                throw new CompileException("Assertion failed: {$a} != {$b}", $span);
            }
            return null;
        });
        $environment->values['print'] = new NativeFunction('print', function (array $p, array $n, Span $span): null {
            if ($n !== []) {
                throw new CompileException('print() does not accept named arguments.', $span);
            }
            $this->printed[] = implode(', ', array_map(self::describe(...), $p));
            return null;
        });
        foreach (['lines', 'bounds'] as $name) {
            $environment->values[$name] = new NativeFunction($name, static fn(array $p, array $n, Span $span) => throw new CompileException("Test helper '{$name}' is not implemented yet.", $span));
        }
        $environment->values['conifer'] = ['color' => [0x9f / 255, 0xeb / 255, 0x52 / 255]];
        $environment->values['forest'] = ['color' => [0x43 / 255, 0xa1 / 255, 0x27 / 255]];
    }

    private function arity(array $p, array $n, Span $span): void
    {
        if (count($p) !== 2 || $n !== []) {
            throw new CompileException('Test helper expects two positional arguments.', $span);
        }
    }

    public static function repr(mixed $value, Span $span): string
    {
        if ($value === null) {
            return 'none';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value) && is_finite($value)) {
            $text = (string) $value;
            return strpbrk($text, '.eE') === false ? $text . '.0' : $text;
        }
        if (is_string($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $key => $item) {
                $parts[] = (array_is_list($value) ? '' : $key . ': ') . self::repr($item, $span);
            }
            return '(' . implode(', ', $parts) . (count($parts) === 1 && array_is_list($value) ? ',' : '') . ')';
        }
        throw new CompileException('Test representation for ' . get_debug_type($value) . ' is not implemented yet.', $span);
    }

    public static function describe(mixed $value, int $depth = 0): string
    {
        if ($depth > 128) {
            return '<depth limit>';
        }
        if (is_array($value)) {
            $parts = [];
            foreach (array_slice($value, 0, 20, true) as $key => $item) {
                $parts[] = $key . ': ' . self::describe($item, $depth + 1);
            }
            return '(' . implode(', ', $parts) . (count($value) > 20 ? ', …' : '') . ')';
        }
        if ($value instanceof Dimension) {
            return $value->value . $value->unit;
        }
        if ($value instanceof Builtin || $value instanceof NativeFunction) {
            return $value->name;
        }
        if ($value instanceof FunctionValue) {
            return '<function>';
        }
        if ($value instanceof Module) {
            return '<module>';
        }
        if ($value instanceof Content) {
            return '<content:' . $value->kind . '>';
        }
        return is_scalar($value) || $value === null ? substr(var_export($value, true), 0, 1000) : '<' . get_debug_type($value) . '>';
    }
}
