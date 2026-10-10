<?php

declare(strict_types=1);

namespace Nemorize\Typst\Runtime;

use Nemorize\Typst\CompileException;
use Nemorize\Typst\Syntax\Span;

final class StandardLibrary
{
    public static function environment(array $inputs = []): Environment
    {
        $builtins = new Environment();
        foreach (['text', 'strong', 'emph', 'heading', 'table', 'align', 'pagebreak', 'parbreak', 'linebreak', 'v', 'h', 'str', 'int', 'float', 'range', 'rgb', 'assert'] as $name) {
            $builtins->values[$name] = new Builtin($name);
        }
        foreach (['left', 'right', 'center', 'auto'] as $name) {
            $builtins->values[$name] = $name;
        }
        $builtins->values['sys'] = ['inputs' => $inputs];
        return new Environment($builtins);
    }

    public static function member(Builtin $value, string $field, Span $span): Builtin
    {
        if ($value->name === 'assert' && in_array($field, ['eq', 'ne'], true)) {
            return new Builtin('assert.' . $field);
        }
        throw new CompileException("Associated field '{$value->name}.{$field}' is not implemented yet.", $span);
    }

    public static function call(string $name, array $p, array $n, Span $span, array $argumentSpans = []): mixed
    {
        switch ($name) {
            case 'text':
                $body = array_pop($p) ?? ($n['body'] ?? null);
                unset($n['body']);
                if (count($p) > 1) {
                    throw new CompileException('text() accepts content and at most one positional style option.', $span);
                }
                if ($p !== []) {
                    $key = $p[0] instanceof Dimension ? 'size' : 'font';
                    if (array_key_exists($key, $n)) {
                        throw new CompileException('Duplicate text option.', $span);
                    }
                    $n[$key] = $p[0];
                }
                self::keys($n, ['size', 'font', 'weight', 'style', 'fill'], $span);
                self::textOptions($n, $span);
                return new Content('styled', $n, [self::body($body, $span)], $span);
            case 'strong': case 'emph':
                self::keys($n, [], $span);
                self::arity($p, 1, $span);
                return new Content($name, children: [self::body($p[0], $span)], span: $span);
            case 'heading':
                self::keys($n, ['level'], $span);
                self::arity($p, 1, $span);
                $level = $n['level'] ?? 1;
                if (!is_int($level) || $level < 1 || $level > 6) {
                    throw new CompileException('Heading level must be between 1 and 6.', $span);
                }
                return new Content('heading', ['level' => $level], [self::body($p[0], $span)], $span);
            case 'table':
                self::keys($n, ['columns', 'inset', 'stroke'], $span);
                $columns = $n['columns'] ?? 1;
                if (!is_int($columns) || $columns < 1 || $columns > 64) {
                    throw new CompileException('Currently table columns must be an integer from 1 to 64.', $span);
                }
                if (isset($n['inset'])) {
                    self::length($n['inset'], $span);
                }
                if (array_key_exists('stroke', $n) && $n['stroke'] !== null) {
                    self::length($n['stroke'], $span);
                }
                return new Content('table', ['columns' => $columns] + $n, array_map(fn($v) => self::body($v, $span), $p), $span);
            case 'align':
                self::keys($n, [], $span);
                self::arity($p, 2, $span);
                if (!in_array($p[0], ['left', 'right', 'center'], true)) {
                    throw new CompileException('Only horizontal left/center/right alignment is implemented.', $span);
                }
                return new Content('align', ['alignment' => $p[0]], [self::body($p[1], $span)], $span);
            case 'pagebreak': case 'parbreak': case 'linebreak':
                self::keys($n, [], $span);
                self::arity($p, 0, $span);
                return new Content($name, span: $span);
            case 'v': case 'h':
                self::keys($n, [], $span);
                self::arity($p, 1, $span);
                self::length($p[0], $span);
                return new Content($name, ['amount' => $p[0]], span: $span);
            case 'str':
                self::keys($n, [], $span);
                self::arity($p, 1, $span);
                if (!is_scalar($p[0])) {
                    throw new CompileException('str() currently accepts scalar values only.', $span);
                }
                return is_bool($p[0]) ? ($p[0] ? 'true' : 'false') : (string) $p[0];
            case 'int': case 'float':
                self::keys($n, [], $span);
                self::arity($p, 1, $span);
                if (!is_numeric($p[0])) {
                    throw new CompileException('Expected numeric input.', $span);
                }
                if ($name === 'int' && is_float($p[0]) && (!is_finite($p[0]) || $p[0] >= (float) PHP_INT_MAX || $p[0] < (float) PHP_INT_MIN)) {
                    throw new CompileException('number too large', $argumentSpans[0] ?? $span);
                }
                return $name === 'int' ? (int) $p[0] : (float) $p[0];
            case 'range':
                self::keys($n, ['step'], $span);
                if (count($p) < 1 || count($p) > 2 || !is_int($p[0]) || (isset($p[1]) && !is_int($p[1]))) {
                    throw new CompileException('range() expects one or two integers.', $span);
                }
                $start = count($p) === 1 ? 0 : $p[0];
                $end = count($p) === 1 ? $p[0] : $p[1];
                $step = $n['step'] ?? 1;
                if (!is_int($step) || $step === 0) {
                    throw new CompileException('range step must be a nonzero integer.', $span);
                }
                $result = [];
                for ($i = $start; $step > 0 ? $i < $end : $i > $end; $i += $step) {
                    if (count($result) >= 10000) {
                        throw new CompileException('range() exceeds the 10000-item limit.', $span);
                    }
                    $result[] = $i;
                }
                return $result;
            case 'rgb':
                self::keys($n, [], $span);
                self::arity($p, 1, $span);
                if (!is_string($p[0]) || !preg_match('/^#?([0-9a-fA-F]{6})$/', $p[0], $m)) {
                    throw new CompileException('rgb() currently expects a six-digit hexadecimal string.', $span);
                }
                return ['color' => [hexdec(substr($m[1], 0, 2)) / 255, hexdec(substr($m[1], 2, 2)) / 255, hexdec(substr($m[1], 4, 2)) / 255]];
            case 'assert':
                self::keys($n, ['message'], $span);
                self::arity($p, 1, $span);
                if (!is_bool($p[0])) {
                    throw new CompileException('expected boolean, found ' . (is_string($p[0]) ? 'string' : get_debug_type($p[0])), $argumentSpans[0] ?? $span);
                }
                if (!$p[0]) {
                    throw new CompileException('assertion failed' . (isset($n['message']) ? ': ' . $n['message'] : ''), $span);
                }
                return null;
            case 'assert.eq': case 'assert.ne':
                self::keys($n, ['message'], $span);
                self::arity($p, 2, $span);
                $equal = ValueEquality::equals($p[0], $p[1]);
                $wantEqual = $name === 'assert.eq';
                if ($equal !== $wantEqual) {
                    $message = $n['message'] ?? ('value ' . self::describe($p[0]) . ($wantEqual ? ' was not equal to ' : ' was equal to ') . self::describe($p[1]));
                    throw new CompileException(($wantEqual ? 'equality' : 'inequality') . ' assertion failed: ' . $message, $span);
                }
                return null;
        }
        throw new CompileException("Unimplemented function '{$name}'.", $span);
    }

    public static function set(string $name, array $p, array $n, Span $span): Content
    {
        if ($name === 'text') {
            if (count($p) > 1) {
                throw new CompileException('set text() accepts at most one positional option.', $span);
            }
            if ($p !== []) {
                $key = $p[0] instanceof Dimension ? 'size' : 'font';
                if (isset($n[$key])) {
                    throw new CompileException('Duplicate text option.', $span);
                } $n[$key] = $p[0];
            }
            self::keys($n, ['font', 'size', 'weight', 'style', 'fill'], $span);
            self::textOptions($n, $span);
        } elseif ($name === 'page') {
            if (count($p) > 1) {
                throw new CompileException('set page() accepts at most one paper name.', $span);
            }
            if ($p !== []) {
                if (array_key_exists('paper', $n)) {
                    throw new CompileException('Duplicate paper option.', $span);
                } $n['paper'] = $p[0];
            }
            self::keys($n, ['paper', 'width', 'height', 'margin', 'flipped'], $span);
            if (isset($n['paper']) && !in_array($n['paper'], ['a4', 'a5', 'us-letter'], true)) {
                throw new CompileException('Unimplemented paper size.', $span);
            }
            foreach (['width', 'height'] as $key) {
                if (isset($n[$key])) {
                    self::length($n[$key], $span);
                }
            }
            if (isset($n['flipped']) && !is_bool($n['flipped'])) {
                throw new CompileException('flipped must be boolean.', $span);
            }
            if (isset($n['margin'])) {
                if (is_array($n['margin'])) {
                    self::keys($n['margin'], ['top', 'bottom', 'left', 'right', 'x', 'y', 'rest'], $span);
                    foreach ($n['margin'] as $v) {
                        self::length($v, $span);
                    }
                } else {
                    self::length($n['margin'], $span);
                }
            }
        } elseif ($name === 'par') {
            self::arity($p, 0, $span);
            self::keys($n, ['leading', 'spacing'], $span);
            foreach ($n as $v) {
                self::length($v, $span);
            }
        } else {
            throw new CompileException("Set rules for '{$name}' are not implemented yet.", $span);
        }
        return new Content('set', ['target' => $name, 'options' => $n], span: $span);
    }

    private static function textOptions(array $n, Span $span): void
    {
        if (isset($n['font']) && !in_array($n['font'], ['Nanum Gothic', 'NanumGothic'], true)) {
            throw new CompileException('Only the bundled Nanum Gothic font is currently supported.', $span);
        }
        if (isset($n['size'])) {
            self::length($n['size'], $span);
            if ($n['size']->points() <= 0) {
                throw new CompileException('Text size must be positive.', $span);
            }
        }
        if (isset($n['weight']) && !(is_int($n['weight']) && $n['weight'] >= 100 && $n['weight'] <= 900) && !in_array($n['weight'], ['regular', 'bold'], true)) {
            throw new CompileException('Invalid text weight.', $span);
        }
        if (isset($n['style']) && !in_array($n['style'], ['normal', 'italic', 'oblique'], true)) {
            throw new CompileException('Invalid text style.', $span);
        }
        if (isset($n['fill']) && (!is_array($n['fill']) || !isset($n['fill']['color']))) {
            throw new CompileException('Text fill currently requires rgb().', $span);
        }
    }

    private static function length(mixed $v, Span $span): void
    {
        if (!$v instanceof Dimension || !in_array($v->unit, ['pt', 'cm', 'mm', 'in', 'em', '%'], true)) {
            throw new CompileException('Expected a length.', $span);
        }
    }

    private static function describe(mixed $value): string
    {
        if ($value === null) {
            return 'none';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_string($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if ($value instanceof Dimension) {
            return $value->value . $value->unit;
        }
        return '<' . get_debug_type($value) . '>';
    }

    private static function body(mixed $v, Span $span): Content
    {
        if ($v instanceof Content) {
            return $v;
        }
        if (is_string($v) || is_int($v) || is_float($v)) {
            return Content::text((string) $v, $span);
        }
        throw new CompileException('Expected content.', $span);
    }

    private static function keys(array $n, array $allowed, Span $span): void
    {
        foreach ($n as $key => $_) {
            if (!in_array($key, $allowed, true)) {
                throw new CompileException("Argument '{$key}' is not implemented for this function.", $span);
            }
        }
    }

    private static function arity(array $p, int $count, Span $span): void
    {
        if (count($p) !== $count) {
            throw new CompileException("Expected {$count} positional arguments, received " . count($p) . '.', $span);
        }
    }
}
