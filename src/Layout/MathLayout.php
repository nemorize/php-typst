<?php

declare(strict_types=1);

namespace Nemorize\Typst\Layout;

use Nemorize\Typst\Font\FontBook;
use Nemorize\Typst\Syntax\Node;

final class MathLayout
{
    public function __construct(private readonly FontBook $fonts) {}

    public function layout(Node $node, array $style): Box
    {
        $size = $style['size'];
        if ($node->kind === 'math-text') {
            return $this->fonts->text($node->data['text'], $style, $node->span);
        }
        if ($node->kind === 'math-sequence') {
            $x = $ascent = $descent = 0;
            $items = [];
            foreach ($node->children as $child) {
                $box = $this->layout($child, $style);
                if ($child->kind === 'math-text' && in_array($child->data['text'], ['+', '-', '=', '≤', '≥', '≠', '→'], true)) {
                    $x += $size * 0.2;
                }
                $items = [...$items, ...$box->shifted($x, 0)];
                $x += $box->width;
                $ascent = max($ascent, $box->ascent);
                $descent = max($descent, $box->descent);
                if ($child->kind === 'math-text' && in_array($child->data['text'], ['+', '-', '=', '≤', '≥', '≠', '→'], true)) {
                    $x += $size * 0.2;
                }
            }
            return new Box($x, $ascent, $descent, $items);
        }
        if ($node->kind === 'math-group') {
            $left = $this->fonts->text('(', $style, $node->span);
            $body = $this->layout($node->children[0], $style);
            $right = $this->fonts->text(')', $style, $node->span);
            return new Box($left->width + $body->width + $right->width, max($left->ascent, $body->ascent), max($left->descent, $body->descent), [...$left->items, ...$body->shifted($left->width, 0), ...$right->shifted($left->width + $body->width, 0)]);
        }
        if ($node->kind === 'fraction') {
            $small = $style;
            $small['size'] *= 0.8;
            $a = $this->layout($this->ungroup($node->children[0]), $small);
            $b = $this->layout($this->ungroup($node->children[1]), $small);
            $width = max($a->width, $b->width) + $size * 0.35;
            $axis = -$size * 0.25;
            $gap = $size * 0.14;
            $ay = $axis - $gap - $a->descent;
            $by = $axis + $gap + $b->ascent;
            $rule = ['kind' => 'line', 'x' => 0.0, 'y' => $axis, 'x2' => $width, 'y2' => $axis, 'stroke' => max(0.4, $size * 0.045), 'color' => $style['color']];
            return new Box($width, -$ay + $a->ascent, $by + $b->descent, [...$a->shifted(($width - $a->width) / 2, $ay), ...$b->shifted(($width - $b->width) / 2, $by), $rule]);
        }
        if ($node->kind === 'attachment') {
            $base = $this->layout($node->children[0], $style);
            $items = $base->items;
            $width = $base->width;
            $ascent = $base->ascent;
            $descent = $base->descent;
            $small = $style;
            $small['size'] *= 0.65;
            foreach ($node->data['attachments'] as $kind => $child) {
                $box = $this->layout($this->ungroup($child), $small);
                $y = $kind === '^' ? -$size * 0.55 : $size * 0.3;
                $items = [...$items, ...$box->shifted($base->width + $size * 0.05, $y)];
                $width = max($width, $base->width + $size * 0.05 + $box->width);
                $ascent = max($ascent, $box->ascent - $y);
                $descent = max($descent, $box->descent + $y);
            }
            return new Box($width, $ascent, $descent, $items);
        }
        if ($node->kind === 'sqrt') {
            $body = $this->layout($node->children[0], $style);
            $root = $this->fonts->text('√', $style, $node->span);
            $x = $root->width;
            $y = -$body->ascent - $size * 0.08;
            return new Box($x + $body->width + $size * 0.1, max($root->ascent, -$y), max($root->descent, $body->descent), [...$root->items, ...$body->shifted($x, 0), ['kind' => 'line', 'x' => $x, 'y' => $y, 'x2' => $x + $body->width, 'y2' => $y, 'stroke' => 0.5, 'color' => $style['color']]]);
        }
        throw new \Nemorize\Typst\CompileException('Unimplemented math layout.', $node->span);
    }

    private function ungroup(Node $node): Node
    {
        return $node->kind === 'math-group' ? $node->children[0] : $node;
    }
}
