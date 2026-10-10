<?php

declare(strict_types=1);

namespace Nemorize\Typst\Layout;

final class Page
{
    public array $items = [];
    public function __construct(public readonly float $width, public readonly float $height) {}
}
