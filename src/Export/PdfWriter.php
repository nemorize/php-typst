<?php

declare(strict_types=1);

namespace Nemorize\Typst\Export;

use Nemorize\Typst\Font\{FontBook, TrueTypeFont, Unicode};
use Nemorize\Typst\Layout\Page;

/** Deterministic PDF 1.7 output, including embedded fonts and Unicode extraction maps. */
final class PdfWriter
{
    private array $objects = [null];

    public function __construct(private readonly FontBook $fonts) {}

    /** @param list<Page> $pages */
    public function write(array $pages): string
    {
        $catalog = $this->reserve(); $tree = $this->reserve(); $characters = [];
        foreach ($pages as $page) {
            foreach ($page->items as $item) {
                if ($item['kind'] !== 'text') { continue; }
                foreach (Unicode::characters($item['text']) as $char) { $characters[$item['font']][Unicode::codepoint($char)] = true; }
            }
        }
        $maps = $resources = [];
        foreach ($characters as $fontId => $set) {
            $cid = 1; $map = [];
            foreach (array_keys($set) as $cp) { $map[$cp] = $cid++; }
            if ($cid > 65536) { throw new \Nemorize\Typst\CompileException('Too many distinct glyphs in one font.'); }
            $maps[$fontId] = $map;
            $font = $this->embed($this->fonts->fonts[$fontId], $map, $fontId === 0 ? 'NanumGothicRegular' : 'NanumGothicBold');
            $resources[] = "/F{$fontId} {$font} 0 R";
        }
        $kids = [];
        foreach ($pages as $page) {
            $content = '';
            foreach ($page->items as $item) {
                $color = implode(' ', array_map(self::number(...), $item['color']));
                if ($item['kind'] === 'text') {
                    $hex = '';
                    foreach (Unicode::characters($item['text']) as $char) { $hex .= sprintf('%04X', $maps[$item['font']][Unicode::codepoint($char)]); }
                    $shear = $item['italic'] ? '0.21256' : '0';
                    $content .= 'q ' . $color . ' rg BT /F' . $item['font'] . ' ' . self::number($item['size']) . ' Tf 1 0 ' . $shear . ' 1 ' . self::number($item['x']) . ' ' . self::number($page->height - $item['y']) . " Tm <{$hex}> Tj ET Q\n";
                } elseif ($item['kind'] === 'line') {
                    $content .= 'q ' . $color . ' RG ' . self::number($item['stroke']) . ' w ' . self::number($item['x']) . ' ' . self::number($page->height - $item['y']) . ' m ' . self::number($item['x2']) . ' ' . self::number($page->height - $item['y2']) . " l S Q\n";
                }
            }
            $stream = $this->stream($content);
            $kids[] = $this->add('<< /Type /Page /Parent ' . $tree . ' 0 R /MediaBox [0 0 ' . self::number($page->width) . ' ' . self::number($page->height) . '] /Resources << /Font << ' . implode(' ', $resources) . " >> >> /Contents {$stream} 0 R >>");
        }
        $this->objects[$catalog] = "<< /Type /Catalog /Pages {$tree} 0 R >>";
        $this->objects[$tree] = '<< /Type /Pages /Count ' . count($kids) . ' /Kids [' . implode(' ', array_map(fn ($id) => "{$id} 0 R", $kids)) . '] >>';
        $info = $this->add('<< /Producer (php-typst experimental compiler) >>');
        $pdf = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n"; $offsets = [0];
        foreach ($this->objects as $id => $body) {
            if ($id === 0) { continue; }
            $offsets[$id] = strlen($pdf); $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($pdf); $count = count($this->objects);
        $pdf .= "xref\n0 {$count}\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) { $pdf .= sprintf("%010d 00000 n \n", $offset); }
        return $pdf . "trailer\n<< /Size {$count} /Root {$catalog} 0 R /Info {$info} 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    private function embed(TrueTypeFont $font, array $map, string $name): int
    {
        $file = $this->stream($font->data, '/Length1 ' . strlen($font->data));
        $bbox = implode(' ', array_map($font->scale(...), $font->boundingBox));
        $descriptor = $this->add("<< /Type /FontDescriptor /FontName /{$name} /Flags 32 /FontBBox [{$bbox}] /ItalicAngle 0 /Ascent " . $font->scale($font->ascent) . ' /Descent ' . $font->scale($font->descent) . ' /CapHeight ' . $font->scale($font->ascent) . " /StemV 80 /FontFile2 {$file} 0 R >>");
        $gidMap = "\x00\x00"; $widths = []; $unicode = [];
        foreach ($map as $cp => $cid) {
            $gidMap .= pack('n', $font->glyph($cp)); $widths[] = $font->pdfWidth($cp);
            $unicode[] = sprintf('<%04X> <%s>', $cid, strtoupper(bin2hex(Unicode::utf16($cp))));
        }
        $gid = $this->stream($gidMap);
        $cmap = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n";
        foreach (array_chunk($unicode, 100) as $chunk) { $cmap .= count($chunk) . " beginbfchar\n" . implode("\n", $chunk) . "\nendbfchar\n"; }
        $cmap .= "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend\n";
        $toUnicode = $this->stream($cmap);
        $cidFont = $this->add("<< /Type /Font /Subtype /CIDFontType2 /BaseFont /{$name} /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor {$descriptor} 0 R /CIDToGIDMap {$gid} 0 R /DW 1000 /W [1 [" . implode(' ', $widths) . ']] >>');
        return $this->add("<< /Type /Font /Subtype /Type0 /BaseFont /{$name} /Encoding /Identity-H /DescendantFonts [{$cidFont} 0 R] /ToUnicode {$toUnicode} 0 R >>");
    }

    private function reserve(): int { $this->objects[] = null; return array_key_last($this->objects); }
    private function add(string $body): int { $id = $this->reserve(); $this->objects[$id] = $body; return $id; }
    private function stream(string $data, string $extra = ''): int { return $this->add('<< /Length ' . strlen($data) . " {$extra} >>\nstream\n" . $data . "\nendstream"); }
    private static function number(float|int $value): string
    {
        if (!is_finite((float) $value)) { throw new \Nemorize\Typst\CompileException('Non-finite PDF coordinate.'); }
        return rtrim(rtrim(number_format($value, 5, '.', ''), '0'), '.') ?: '0';
    }
}
