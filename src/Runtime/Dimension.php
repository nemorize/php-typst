<?php

declare(strict_types=1);

namespace Nemorize\Typst\Runtime;

final readonly class Dimension
{
    public function __construct(public float $value, public string $unit) {}

    public function points(float $em = 11, float $relative = 0): float
    {
        return $this->value * match ($this->unit) {
            'pt' => 1, 'mm' => 72 / 25.4, 'cm' => 72 / 2.54, 'in' => 72,
            'em' => $em, '%' => $relative / 100,
            default => throw new \InvalidArgumentException("'{$this->unit}' cannot be converted to a length."),
        };
    }
}
