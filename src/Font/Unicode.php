<?php

declare(strict_types=1);

namespace Nemorize\Typst\Font;

/** UTF-8 utilities without mbstring or iconv. */
final class Unicode
{
    /** @return list<string> */
    public static function characters(string $text): array
    {
        return preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    public static function codepoint(string $character): int
    {
        $bytes = array_values(unpack('C*', $character));
        $value = $bytes[0] & match (count($bytes)) { 1 => 0x7f, 2 => 0x1f, 3 => 0x0f, 4 => 0x07 };
        for ($i = 1; $i < count($bytes); $i++) { $value = ($value << 6) | ($bytes[$i] & 0x3f); }
        return $value;
    }

    public static function character(int $value): string
    {
        if ($value < 0 || $value > 0x10ffff || ($value >= 0xd800 && $value <= 0xdfff)) {
            throw new \InvalidArgumentException('Invalid Unicode scalar value.');
        }
        return match (true) {
            $value < 0x80 => chr($value),
            $value < 0x800 => chr(0xc0 | ($value >> 6)) . chr(0x80 | ($value & 0x3f)),
            $value < 0x10000 => chr(0xe0 | ($value >> 12)) . chr(0x80 | (($value >> 6) & 0x3f)) . chr(0x80 | ($value & 0x3f)),
            default => chr(0xf0 | ($value >> 18)) . chr(0x80 | (($value >> 12) & 0x3f)) . chr(0x80 | (($value >> 6) & 0x3f)) . chr(0x80 | ($value & 0x3f)),
        };
    }

    public static function utf16(int $value): string
    {
        if ($value <= 0xffff) { return pack('n', $value); }
        $value -= 0x10000;
        return pack('nn', 0xd800 + ($value >> 10), 0xdc00 + ($value & 0x3ff));
    }

    public static function unescape(string $text): string
    {
        return preg_replace_callback('/\\\\(?:u\{([0-9a-fA-F]+)\}|(.))/us', static function (array $m): string {
            if ($m[1] !== '') { return self::character((int) hexdec($m[1])); }
            return match ($m[2]) { 'n' => "\n", 'r' => "\r", 't' => "\t", default => $m[2] };
        }, $text);
    }
}
