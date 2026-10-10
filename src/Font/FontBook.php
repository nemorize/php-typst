<?php

declare(strict_types=1);

namespace Nemorize\Typst\Font;

use Nemorize\Typst\CompileException;
use Nemorize\Typst\Layout\Box;
use Nemorize\Typst\Syntax\Span;

final class FontBook
{
    /** @var array<int, TrueTypeFont> */
    public readonly array $fonts;

    public function __construct()
    {
        $root = dirname(__DIR__, 2) . '/resources/fonts/';
        $this->fonts = [new TrueTypeFont($root . 'NanumGothic-Regular.ttf'), new TrueTypeFont($root . 'NanumGothic-Bold.ttf')];
    }

    public function text(string $text, array $style, ?Span $span = null): Box
    {
        $fontId = $style['bold'] ? 1 : 0;
        $font = $this->fonts[$fontId];
        $width = 0;
        foreach (Unicode::characters($text) as $char) {
            $cp = Unicode::codepoint($char);
            if ($font->glyph($cp) === 0) {
                throw new CompileException(sprintf('The bundled font has no glyph for U+%04X.', $cp), $span);
            }
            $width += $font->width($cp) * $style['size'];
        }
        return new Box($width, $font->ascent / $font->unitsPerEm * $style['size'], -$font->descent / $font->unitsPerEm * $style['size'], [[
            'kind' => 'text', 'text' => $text, 'font' => $fontId, 'size' => $style['size'], 'italic' => $style['italic'], 'color' => $style['color'], 'x' => 0.0, 'y' => 0.0,
        ]]);
    }
}
