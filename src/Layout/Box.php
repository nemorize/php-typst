<?php

declare(strict_types=1);

namespace Nemorize\Typst\Layout;

/** Drawing items use coordinates relative to a baseline, with y increasing downwards. */
final readonly class Box
{
    public function __construct(public float $width, public float $ascent, public float $descent, public array $items = []) {}

    public function shifted(float $x, float $y): array
    {
        return array_map(static function (array $item) use ($x, $y): array {
            $item['x'] += $x;
            $item['y'] += $y;
            if ($item['kind'] === 'line') {
                $item['x2'] += $x;
                $item['y2'] += $y;
            }
            return $item;
        }, $this->items);
    }
}
