<?php

declare(strict_types=1);

namespace Nemorize\Typst\Layout;

use Nemorize\Typst\CompileException;
use Nemorize\Typst\Font\{FontBook, Unicode};
use Nemorize\Typst\Runtime\{Content, Dimension};
use Nemorize\Typst\Syntax\MathParser;

/** Initial greedy paragraph layout, with a display list shared by all exporters. */
final class FlowLayout
{
    private array $pages = [];
    private float $width = 595.276;
    private float $height = 841.89;
    private array $margins = [56.693, 56.693, 56.693, 56.693]; // top, right, bottom, left
    private float $y = 0;
    private array $line = [];
    private float $lineWidth = 0;
    private bool $space = false;
    private int $enumeration = 0;
    private array $lineStyle = [];
    private bool $cellMode = false;

    public function __construct(private readonly FontBook $fonts) {}

    public static function defaultStyle(): array
    {
        return ['size' => 11.0, 'bold' => false, 'italic' => false, 'color' => [0.0, 0.0, 0.0], 'alignment' => 'left', 'leading' => new Dimension(0.3, 'em'), 'spacing' => new Dimension(0.65, 'em')];
    }

    /** @return list<Page> */
    public function layout(Content $content): array
    {
        $this->newPage();
        $style = self::defaultStyle();
        $this->walk($content, $style);
        $this->flush($style);
        return $this->pages;
    }

    private function walk(Content $node, array &$style): void
    {
        switch ($node->kind) {
            case 'sequence':
                $local = $style;
                foreach ($node->children as $child) {
                    $this->walk($child, $local);
                }
                break;
            case 'text':
                foreach (preg_split('/(\s+)/u', $node->attributes['text'], -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $part) {
                    if (preg_match('/^\s+$/u', $part)) {
                        $this->space = true;
                    } else {
                        $this->word($part, $style, $node);
                    }
                }
                break;
            case 'strong': case 'emph': case 'styled':
                $local = $style;
                if ($node->kind === 'strong') {
                    $local['bold'] = !$local['bold'];
                } elseif ($node->kind === 'emph') {
                    $local['italic'] = !$local['italic'];
                } else {
                    $this->textStyle($local, $node->attributes);
                }
                foreach ($node->children as $child) {
                    $this->walk($child, $local);
                }
                break;
            case 'heading':
                $this->flush($style);
                $this->gap($style['size'] * 0.6);
                $local = $style;
                $local['bold'] = true;
                $local['size'] *= match ($node->attributes['level']) {
                    1 => 1.85, 2 => 1.5, 3 => 1.25, default => 1.1,
                };
                foreach ($node->children as $child) {
                    $this->walk($child, $local);
                }
                $this->flush($local);
                $this->gap($style['size'] * 0.4);
                $this->enumeration = 0;
                break;
            case 'item':
                $this->flush($style);
                $label = $node->attributes['ordered'] ? (++$this->enumeration) . '.' : '•';
                if (!$node->attributes['ordered']) {
                    $this->enumeration = 0;
                }
                $this->add($this->fonts->text($label, $style, $node->span), $style, $node);
                $this->space = true;
                foreach ($node->children as $child) {
                    $this->walk($child, $style);
                }
                $this->flush($style);
                $this->gap($style['size'] * 0.15);
                break;
            case 'parbreak':
                $hadLine = $this->line !== [];
                $this->flush($style);
                if ($hadLine) {
                    $this->gap($style['spacing']->points($style['size']));
                }
                $this->enumeration = 0;
                break;
            case 'linebreak':
                if ($this->line === []) {
                    $this->gap($style['size'] * 1.3);
                } else {
                    $this->flush($style);
                }
                break;
            case 'pagebreak':
                if ($this->cellMode) {
                    throw new CompileException('Page breaks in table cells are not implemented.', $node->span);
                }
                $this->flush($style);
                $this->newPage();
                break;
            case 'math':
                $math = (new MathParser($node->attributes['source'], $node->span))->parse();
                $box = (new MathLayout($this->fonts))->layout($math, $style);
                if ($node->attributes['block']) {
                    $this->flush($style);
                    $this->gap($style['size'] * 0.4);
                    $local = $style;
                    $local['alignment'] = 'center';
                    $this->add($box, $local, $node);
                    $this->flush($local);
                    $this->gap($style['size'] * 0.4);
                } else {
                    $this->add($box, $style, $node);
                }
                break;
            case 'raw':
                if ($node->attributes['block']) {
                    $this->flush($style);
                }
                foreach (explode("\n", $node->attributes['text']) as $i => $line) {
                    if ($i > 0) {
                        $this->flush($style);
                    }
                    $this->word(str_replace("\t", '    ', $line), $style, $node);
                }
                if ($node->attributes['block']) {
                    $this->flush($style);
                }
                break;
            case 'align':
                $this->flush($style);
                $local = $style;
                $local['alignment'] = $node->attributes['alignment'];
                foreach ($node->children as $child) {
                    $this->walk($child, $local);
                }
                $this->flush($local);
                break;
            case 'v':
                $this->flush($style);
                $this->gap($node->attributes['amount']->points($style['size'], $this->height));
                break;
            case 'h':
                $amount = $node->attributes['amount']->points($style['size'], $this->availableWidth());
                if ($amount < 0) {
                    throw new CompileException('Negative horizontal spacing is not implemented.', $node->span);
                }
                $this->add(new Box($amount, 0, 0), $style, $node);
                break;
            case 'set':
                $target = $node->attributes['target'];
                $options = $node->attributes['options'];
                if ($target === 'text') {
                    $this->textStyle($style, $options);
                } elseif ($target === 'par') {
                    $this->flush($style);
                    $style = array_replace($style, $options);
                } elseif ($target === 'page') {
                    $this->pageStyle($options, $style, $node);
                }
                break;
            case 'table': $this->table($node, $style);
                break;
            default: throw new CompileException("Layout for '{$node->kind}' is not implemented.", $node->span);
        }
    }

    private function word(string $text, array $style, Content $node): void
    {
        if ($text === '') {
            return;
        }
        $box = $this->fonts->text($text, $style, $node->span);
        if ($box->width <= $this->availableWidth()) {
            $this->add($box, $style, $node);
            return;
        }
        // Emergency breaking for long words and CJK; full Unicode line breaking is pending.
        foreach (Unicode::characters($text) as $character) {
            $this->add($this->fonts->text($character, $style, $node->span), $style, $node);
        }
    }

    private function add(Box $box, array $style, Content $node): void
    {
        $limit = $this->availableWidth();
        if ($box->width > $limit + 0.001) {
            throw new CompileException('Content is wider than the available line width.', $node->span);
        }
        $space = $this->space && $this->line !== [] ? $this->fonts->text(' ', $style, $node->span) : null;
        if ($this->line !== [] && $this->lineWidth + ($space?->width ?? 0) + $box->width > $limit) {
            $this->flush($style);
            $space = null;
        }
        if ($space !== null) {
            $this->line[] = $space;
            $this->lineWidth += $space->width;
        }
        if ($this->line === []) {
            $this->lineStyle = $style;
        }
        $this->line[] = $box;
        $this->lineWidth += $box->width;
        $this->space = false;
    }

    private function flush(array $style): void
    {
        $this->space = false;
        if ($this->line === []) {
            return;
        }
        $style = $this->lineStyle;
        $ascent = $descent = 0;
        foreach ($this->line as $box) {
            $ascent = max($ascent, $box->ascent);
            $descent = max($descent, $box->descent);
        }
        $leading = max(0, $style['leading']->points($style['size']));
        $height = max($ascent + $descent + $leading, $style['size'] * 1.25);
        $this->room($height);
        $x = $this->margins[3] + match ($style['alignment']) {
            'center' => ($this->availableWidth() - $this->lineWidth) / 2, 'right' => $this->availableWidth() - $this->lineWidth, default => 0,
        };
        $page = $this->pages[array_key_last($this->pages)];
        foreach ($this->line as $box) {
            array_push($page->items, ...$box->shifted($x, $this->y + $ascent));
            $x += $box->width;
        }
        $this->y += $height;
        $this->line = [];
        $this->lineWidth = 0;
    }

    private function room(float $height): void
    {
        if (!is_finite($height) || $height < 0 || $height > $this->height - $this->margins[0] - $this->margins[2]) {
            throw new CompileException('Content cannot fit vertically on a page.');
        }
        if ($this->y + $height > $this->height - $this->margins[2] + 0.001) {
            $this->newPage();
        }
    }

    private function gap(float $amount): void
    {
        if (!is_finite($amount) || $amount < 0) {
            throw new CompileException('Negative or non-finite vertical spacing is not implemented.');
        }
        $this->y += $amount;
    }

    private function newPage(): void
    {
        if (count($this->pages) >= 1000) {
            throw new CompileException('Page limit exceeded.');
        }
        $this->pages[] = new Page($this->width, $this->height);
        $this->y = $this->margins[0];
    }

    private function availableWidth(): float
    {
        return $this->width - $this->margins[1] - $this->margins[3];
    }

    private function textStyle(array &$style, array $options): void
    {
        if (isset($options['size'])) {
            $style['size'] = $options['size']->points($style['size']);
        }
        if (isset($options['weight'])) {
            $style['bold'] = $options['weight'] === 'bold' || (is_int($options['weight']) && $options['weight'] >= 600);
        }
        if (isset($options['style'])) {
            $style['italic'] = $options['style'] !== 'normal';
        }
        if (isset($options['fill'])) {
            $style['color'] = $options['fill']['color'];
        }
    }

    private function pageStyle(array $options, array $style, Content $node): void
    {
        if ($this->cellMode) {
            throw new CompileException('Page settings inside table cells are not implemented.', $node->span);
        }
        $this->flush($style);
        if (isset($options['paper'])) {
            [$this->width, $this->height] = match ($options['paper']) {
                'a5' => [419.528, 595.276], 'us-letter' => [612.0, 792.0], default => [595.276, 841.89],
            };
        }
        if (isset($options['width'])) {
            $this->width = $options['width']->points($style['size']);
        }
        if (isset($options['height'])) {
            $this->height = $options['height']->points($style['size']);
        }
        if ($options['flipped'] ?? false) {
            [$this->width, $this->height] = [$this->height, $this->width];
        }
        if (isset($options['margin'])) {
            $m = $options['margin'];
            if ($m instanceof Dimension) {
                $this->margins = array_fill(0, 4, $m->points($style['size'], min($this->width, $this->height)));
            } else {
                foreach (['top', 'right', 'bottom', 'left'] as $i => $side) {
                    $value = $m[$side] ?? $m[in_array($i, [0, 2]) ? 'y' : 'x'] ?? $m['rest'] ?? null;
                    if ($value !== null) {
                        $this->margins[$i] = $value->points($style['size'], in_array($i, [0, 2]) ? $this->height : $this->width);
                    }
                }
            }
        }
        if (!is_finite($this->width) || !is_finite($this->height) || min($this->width, $this->height) <= 0 || max($this->width, $this->height) > 14400 || min($this->margins) < 0 || $this->availableWidth() <= 0 || $this->height - $this->margins[0] - $this->margins[2] <= 0) {
            throw new CompileException('Invalid page geometry or margins.', $node->span);
        }
        $last = array_key_last($this->pages);
        if ($this->pages[$last]->items === []) {
            $this->pages[$last] = new Page($this->width, $this->height);
            $this->y = $this->margins[0];
        } else {
            $this->newPage();
        }
    }

    private function table(Content $node, array $style): void
    {
        $this->flush($style);
        $columns = $node->attributes['columns'];
        $cellWidth = $this->availableWidth() / $columns;
        $padding = ($node->attributes['inset'] ?? new Dimension(5, 'pt'))->points($style['size'], $cellWidth);
        $stroke = array_key_exists('stroke', $node->attributes) && $node->attributes['stroke'] === null ? 0 : ($node->attributes['stroke'] ?? new Dimension(0.5, 'pt'))->points($style['size']);
        if ($padding < 0 || $stroke < 0 || $padding * 2 >= $cellWidth) {
            throw new CompileException('Invalid table inset or stroke.', $node->span);
        }
        foreach (array_chunk($node->children, $columns) as $row) {
            $cells = [];
            $rowHeight = $style['size'] * 1.5 + 2 * $padding;
            foreach ($row as $content) {
                $cell = new self($this->fonts);
                $cell->width = $cellWidth;
                $cell->height = 14400;
                $cell->margins = array_fill(0, 4, $padding);
                $cell->cellMode = true;
                $cell->newPage();
                $local = $style;
                $cell->walk($content, $local);
                $cell->flush($local);
                if (count($cell->pages) > 1) {
                    throw new CompileException('Table cell is too tall.', $node->span);
                }
                $cells[] = $cell->pages[0]->items;
                $rowHeight = max($rowHeight, $cell->y + $padding);
            }
            $this->room($rowHeight);
            $page = $this->pages[array_key_last($this->pages)];
            for ($col = 0; $col < $columns; $col++) {
                $x = $this->margins[3] + $col * $cellWidth;
                $box = new Box(0, 0, 0, $cells[$col] ?? []);
                array_push($page->items, ...$box->shifted($x, $this->y));
                if ($stroke > 0) {
                    foreach ([[0, 0, $cellWidth, 0], [0, $rowHeight, $cellWidth, $rowHeight], [0, 0, 0, $rowHeight], [$cellWidth, 0, $cellWidth, $rowHeight]] as [$x1, $y1, $x2, $y2]) {
                        $page->items[] = ['kind' => 'line', 'x' => $x + $x1, 'y' => $this->y + $y1, 'x2' => $x + $x2, 'y2' => $this->y + $y2, 'stroke' => $stroke, 'color' => [0, 0, 0]];
                    }
                }
            }
            $this->y += $rowHeight;
        }
        $this->gap($style['size'] * 0.5);
    }
}
