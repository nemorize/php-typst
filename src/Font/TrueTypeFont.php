<?php

declare(strict_types=1);

namespace Nemorize\Typst\Font;

/** Reads TrueType cmap/hmtx metrics; does not use a native font engine. */
final class TrueTypeFont
{
    public readonly string $data;
    public readonly int $unitsPerEm;
    public readonly int $ascent;
    public readonly int $descent;
    public readonly array $boundingBox;
    private array $tables = [];
    private array $widths = [];
    private array $glyphCache = [];
    private int $cmap;
    private int $format;

    public function __construct(string $path)
    {
        $bytes = file_get_contents($path);
        if ($bytes === false || strlen($bytes) < 12 || strlen($bytes) > 16 * 1024 * 1024 || substr($bytes, 0, 4) !== "\x00\x01\x00\x00") {
            throw new \InvalidArgumentException('Expected a TrueType font with glyf outlines.');
        }
        $this->data = $bytes;
        $count = $this->u16(4);
        for ($i = 0; $i < $count; $i++) {
            $at = 12 + $i * 16;
            $name = substr($bytes, $at, 4);
            $offset = $this->u32($at + 8);
            $length = $this->u32($at + 12);
            if ($offset + $length > strlen($bytes)) {
                throw new \InvalidArgumentException('Truncated TrueType table.');
            }
            $this->tables[$name] = $offset;
        }
        foreach (['head', 'hhea', 'hmtx', 'cmap', 'maxp', 'glyf', 'loca'] as $table) {
            if (!isset($this->tables[$table])) {
                throw new \InvalidArgumentException("Missing font table '{$table}'.");
            }
        }
        $head = $this->tables['head'];
        $hhea = $this->tables['hhea'];
        $this->unitsPerEm = $this->u16($head + 18);
        if ($this->unitsPerEm === 0) {
            throw new \InvalidArgumentException('Invalid font unitsPerEm.');
        }
        $this->ascent = $this->s16($hhea + 4);
        $this->descent = $this->s16($hhea + 6);
        $this->boundingBox = array_map(fn($at) => $this->s16($head + $at), [36, 38, 40, 42]);
        $metrics = $this->u16($hhea + 34);
        $glyphs = $this->u16($this->tables['maxp'] + 4);
        if ($metrics < 1 || $metrics > $glyphs) {
            throw new \InvalidArgumentException('Invalid horizontal font metrics.');
        }
        for ($i = 0; $i < $glyphs; $i++) {
            $this->widths[$i] = $this->u16($this->tables['hmtx'] + min($i, $metrics - 1) * 4);
        }
        $cmap = $this->tables['cmap'];
        $best = -1;
        for ($i = 0; $i < $this->u16($cmap + 2); $i++) {
            $at = $cmap + 4 + 8 * $i;
            $platform = $this->u16($at);
            $encoding = $this->u16($at + 2);
            if ($platform !== 0 && !($platform === 3 && in_array($encoding, [1, 10], true))) {
                continue;
            }
            $offset = $cmap + $this->u32($at + 4);
            $format = $this->u16($offset);
            $score = $format === 12 ? 2 : ($format === 4 ? 1 : -1);
            if ($score > $best) {
                $best = $score;
                $this->cmap = $offset;
                $this->format = $format;
            }
        }
        if ($best < 0) {
            throw new \InvalidArgumentException('No supported Unicode cmap in font.');
        }
    }

    public function glyph(int $codepoint): int
    {
        if (isset($this->glyphCache[$codepoint])) {
            return $this->glyphCache[$codepoint];
        }
        $glyph = 0;
        $at = $this->cmap;
        if ($this->format === 12) {
            $low = 0;
            $high = $this->u32($at + 12) - 1;
            while ($low <= $high) {
                $mid = intdiv($low + $high, 2);
                $group = $at + 16 + $mid * 12;
                $start = $this->u32($group);
                $end = $this->u32($group + 4);
                if ($codepoint < $start) {
                    $high = $mid - 1;
                } elseif ($codepoint > $end) {
                    $low = $mid + 1;
                } else {
                    $glyph = $this->u32($group + 8) + $codepoint - $start;
                    break;
                }
            }
        } elseif ($codepoint <= 0xffff) {
            $segments = intdiv($this->u16($at + 6), 2);
            for ($i = 0; $i < $segments; $i++) {
                $end = $this->u16($at + 14 + $i * 2);
                if ($codepoint > $end) {
                    continue;
                }
                $start = $this->u16($at + 16 + $segments * 2 + $i * 2);
                if ($codepoint < $start) {
                    break;
                }
                $delta = $this->s16($at + 16 + $segments * 4 + $i * 2);
                $rangeAt = $at + 16 + $segments * 6 + $i * 2;
                $range = $this->u16($rangeAt);
                $glyph = $range === 0 ? ($codepoint + $delta) & 0xffff : $this->u16($rangeAt + $range + 2 * ($codepoint - $start));
                if ($range !== 0 && $glyph !== 0) {
                    $glyph = ($glyph + $delta) & 0xffff;
                }
                break;
            }
        }
        return $this->glyphCache[$codepoint] = $glyph;
    }

    public function width(int $codepoint): float
    {
        return $this->widths[$this->glyph($codepoint)] / $this->unitsPerEm;
    }
    public function pdfWidth(int $codepoint): int
    {
        return (int) round($this->width($codepoint) * 1000);
    }
    public function scale(int $metric): int
    {
        return (int) round($metric * 1000 / $this->unitsPerEm);
    }

    private function u16(int $at): int
    {
        $this->bounds($at, 2);
        return unpack('n', substr($this->data, $at, 2))[1];
    }
    private function s16(int $at): int
    {
        $v = $this->u16($at);
        return $v >= 32768 ? $v - 65536 : $v;
    }
    private function u32(int $at): int
    {
        $this->bounds($at, 4);
        return unpack('N', substr($this->data, $at, 4))[1];
    }
    private function bounds(int $at, int $length): void
    {
        if ($at < 0 || $at + $length > strlen($this->data)) {
            throw new \InvalidArgumentException('Truncated font data.');
        }
    }
}
