<?php

declare(strict_types=1);

namespace Nemorize\Typst\Runtime;

/** Equality for currently supported values; never traverses closure environments. */
final class ValueEquality
{
    public static function equals(mixed $a, mixed $b, int $depth = 0): bool
    {
        if ($depth > 128) { throw new \InvalidArgumentException('Value comparison nesting limit exceeded.'); }
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) { return $a == $b; }
        if (get_debug_type($a) !== get_debug_type($b)) { return false; }
        if (is_array($a)) {
            if (count($a) !== count($b) || array_is_list($a) !== array_is_list($b)) { return false; }
            foreach ($a as $key => $value) { if (!array_key_exists($key, $b) || !self::equals($value, $b[$key], $depth + 1)) { return false; } }
            return true;
        }
        if ($a instanceof FunctionValue || $a instanceof NativeFunction || $a instanceof Module) { return $a === $b; }
        if ($a instanceof Builtin) { return $a->name === $b->name; }
        if ($a instanceof Dimension) { return $a->unit === $b->unit && $a->value === $b->value; }
        if ($a instanceof Content) {
            return $a->kind === $b->kind && self::equals($a->attributes, $b->attributes, $depth + 1) && self::equals($a->children, $b->children, $depth + 1);
        }
        return $a === $b;
    }
}
