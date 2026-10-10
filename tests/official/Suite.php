<?php

declare(strict_types=1);

namespace Nemorize\Typst\Tests\Official;

/** Reads the upstream format without rewriting the vendored test source. */
final class Suite
{
    public const COMMIT = '9dfd3a08500b7896045f907433cf7b4b02434fad';
    public readonly string $root;
    public readonly array $manifest;

    public function __construct(string $root)
    {
        $this->root = realpath($root) ?: throw new \RuntimeException('Missing upstream fixture directory.');
        $this->manifest = json_decode(file_get_contents($this->root . '/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        if (($this->manifest['version'] ?? '') !== '0.15.1' || ($this->manifest['commit'] ?? '') !== self::COMMIT) { throw new \RuntimeException('Unexpected upstream fixture version or commit.'); }
    }

    public function verify(): void
    {
        foreach ($this->manifest['files'] as $path => $hash) {
            $file = realpath($this->root . '/' . $path);
            if ($file === false || !str_starts_with($file, $this->root . DIRECTORY_SEPARATOR) || hash_file('sha256', $file) !== $hash) {
                throw new \RuntimeException("Upstream fixture missing or modified: {$path}");
            }
        }
        $actual = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $path = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($this->root) + 1));
            // The upstream README is optional reference material, not test data.
            if (!in_array($path, ['manifest.json', 'tests/README.md'], true)) { $actual[] = $path; }
        }
        $expected = array_keys($this->manifest['files']); sort($actual); sort($expected);
        if ($actual !== $expected) { throw new \RuntimeException('Unexpected files in upstream fixture snapshot.'); }
    }

    /** @return list<TestCase> */
    public function cases(): array
    {
        $files = [];
        foreach ($this->manifest['files'] as $path => $_) { if (str_starts_with($path, 'tests/suite/') && str_ends_with($path, '.typ')) { $files[] = $path; } }
        sort($files); $cases = []; $names = [];
        foreach ($files as $path) {
            foreach (self::parseFile($path, file_get_contents($this->root . '/' . $path)) as $case) {
                if (isset($names[$case->name])) { throw new \RuntimeException("Duplicate upstream test name: {$case->name}"); }
                $names[$case->name] = true; $cases[] = $case;
            }
        }
        return $cases;
    }

    /** @return list<TestCase> */
    public static function parseFile(string $path, string $text): array
    {
        if (str_starts_with($text, '// SKIP')) { return []; }
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = explode("\n", $text); $headers = [];
        foreach ($lines as $i => $line) {
            if (preg_match('/^---[ \t]+([^\s]+)[ \t]+(.+?)[ \t]+---[ \t]*$/u', $line, $m)) {
                preg_match_all('/[a-z]+(?:\([^)]*\))?/', $m[2], $a);
                $attributes = $a[0];
                if (trim(preg_replace('/[a-z]+(?:\([^)]*\))?/', '', $m[2])) !== '') { throw new \RuntimeException("Invalid test attributes in {$path}:" . ($i + 1)); }
                foreach ($attributes as $attr) {
                    if (!preg_match('/^(?:eval|paged|render|pdf|pdftags|svg|html|bundle|empty|large|pdfstandard\([^)]*\)|tolerance\(\d+\))$/', $attr)) { throw new \RuntimeException("Unknown test attribute in {$path}:{$i}: {$attr}"); }
                }
                if (!array_intersect($attributes, ['eval', 'paged', 'render', 'pdf', 'pdftags', 'svg', 'html', 'bundle'])) { throw new \RuntimeException("Test has no stage: {$path}:{$i}"); }
                $headers[] = [$i, $m[1], $attributes];
            } elseif (str_starts_with($line, '---')) { throw new \RuntimeException("Invalid test header in {$path}:" . ($i + 1)); }
        }
        $preamble = array_slice($lines, 0, $headers[0][0] ?? count($lines));
        foreach ($preamble as $line) {
            if (trim($line) !== '' && !str_starts_with(trim($line), '//')) { throw new \RuntimeException("Upstream preamble must contain comments only: {$path}"); }
        }
        $cases = [];
        foreach ($headers as $i => [$start, $name, $attributes]) {
            $end = $headers[$i + 1][0] ?? count($lines);
            $body = array_slice($lines, $start + 1, $end - $start - 1);
            if ($i === count($headers) - 1 && end($body) === '') { array_pop($body); }
            $source = []; $mapping = []; $notes = [];
            foreach ($body as $j => $line) {
                if (preg_match('/^[ \t]*\/\/ (Error|Warning|Hint):[ \t]*(.*)$/u', $line, $m)) {
                    $notes[] = [$m[1], $m[2], count($source) + 1];
                } else { $source[] = $line; $mapping[count($source)] = $start + 2 + $j; }
            }
            $diagnostics = array_map(fn ($note) => self::annotation(...$note), $notes);
            $cases[] = new TestCase($name, $path, $start + 1, $attributes, $source === [] ? '' : implode("\n", $source) . "\n", $diagnostics, $mapping);
        }
        return $cases;
    }

    public static function annotation(string $kind, string $text, int $nextLine): Diagnostic
    {
        $external = null;
        if (preg_match('/^"([^"]+)"\s+(.*)$/u', $text, $m)) { $external = '/' . ltrim($m[1], '/'); $text = $m[2]; }
        $range = null;
        if (preg_match('/^((?:\d+:)?\d+(?:-(?:\d+:)?\d+)?)\s+(.*)$/u', $text, $m)) {
            $positions = explode('-', $m[1]);
            $convert = static function (string $position) use ($nextLine, $external): array {
                $parts = explode(':', $position);
                if (count($parts) === 2) { [$line, $column] = array_map(intval(...), $parts); $line += $external === null ? $nextLine - 1 : 0; }
                else { if ($external !== null) { throw new \RuntimeException('External annotations must include a line and column.'); } $line = $nextLine; $column = (int) $parts[0]; }
                if ($line < 1 || $column < 1) { throw new \RuntimeException('Diagnostic positions must be positive.'); }
                return ['line' => $line, 'column' => $column];
            };
            $range = ['start' => $convert($positions[0]), 'end' => $convert($positions[1] ?? $positions[0])];
            if ([$range['start']['line'], $range['start']['column']] > [$range['end']['line'], $range['end']['column']]) { throw new \RuntimeException('Reversed diagnostic range.'); }
            $text = $m[2];
        }
        return new Diagnostic($kind, str_replace('VERSION', '0.15.1', trim($text)), $range, $external);
    }
}
