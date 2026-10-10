<?php

declare(strict_types=1);

namespace Nemorize\Typst\Tests\Official;

final class Report
{
    public static function build(array $results, array $manifest, int $discovered, ?string $filter): array
    {
        $counts = array_fill_keys(['PASS', 'FAIL', 'UNSUPPORTED', 'NOT_RUN', 'ERROR'], 0);
        $groups = [];
        foreach ($results as $result) {
            $counts[$result['status']]++;
            $category = explode('/', $result['file'])[2] ?? 'other';
            $groups[$category] ??= array_fill_keys(array_keys($counts), 0);
            $groups[$category][$result['status']]++;
        }
        ksort($groups);
        return ['schema_version' => 1, 'upstream' => ['version' => $manifest['version'], 'commit' => $manifest['commit']], 'scope' => 'explicit eval stage; no layout, render, PDF, SVG, HTML or bundle verification', 'discovered' => $discovered, 'selected' => count($results), 'filtered_out' => $discovered - count($results), 'filter' => $filter, 'counts' => $counts, 'categories' => $groups, 'results' => $results];
    }

    public static function save(array $report, string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0777, true)) {
            throw new \RuntimeException('Cannot create report directory.');
        }
        self::write($directory . '/report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        $counts = [];
        foreach ($report['counts'] as $status => $count) {
            $counts[] = "{$status}: {$count}";
        }
        $html = '<!doctype html><html lang="en"><meta charset="utf-8"><title>Typst conformance: eval</title><style>body{font:15px system-ui;margin:32px;max-width:1400px}table{border-collapse:collapse;width:100%}td,th{border:1px solid #ddd;padding:8px;text-align:left;vertical-align:top}pre{white-space:pre-wrap;overflow-wrap:anywhere}.PASS{color:#176c31}.FAIL,.ERROR{color:#b42318}.UNSUPPORTED{color:#8a5700}summary{cursor:pointer}a{color:#175ca8}</style><h1>Official Typst tests: PHP eval</h1><p>Typst ' . self::escape($report['upstream']['version']) . ' @ ' . self::escape($report['upstream']['commit']) . '</p><p>' . self::escape($report['scope']) . '</p><p>' . implode(' · ', $counts) . '</p><p>Unsupported and unexecuted tests are never counted as passes.</p><table><thead><tr><th>Status</th><th>Test</th><th>Details</th></tr></thead><tbody>';
        foreach ($report['results'] as $result) {
            $url = 'https://github.com/typst/typst/blob/' . $report['upstream']['commit'] . '/' . $result['file'] . '#L' . $result['line'];
            $detail = json_encode(['reason' => $result['reason'], 'assertions' => $result['assertions'], 'expected' => $result['expected_diagnostics'], 'actual' => $result['actual_diagnostics'], 'printed' => $result['printed']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $html .= '<tr><td class="' . $result['status'] . '">' . $result['status'] . '</td><td><a href="' . self::escape($url) . '">' . self::escape($result['name']) . '</a><br>' . self::escape($result['file'] . ':' . $result['line']) . '</td><td><details><summary>' . self::escape($result['reason']) . '</summary><pre>' . self::escape($detail) . '</pre></details></td></tr>';
        }
        self::write($directory . '/report.html', $html . '</tbody></table></html>');
    }

    private static function write(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) !== strlen($contents)) {
            throw new \RuntimeException("Cannot write {$path}.");
        }
    }
    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
