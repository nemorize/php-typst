<?php

declare(strict_types=1);

namespace Nemorize\Typst\Tests\Official;

final class Baseline
{
    /** @return list<string> Names of required passing cases that regressed. */
    public static function regressions(array $baseline, array $report): array
    {
        if (($baseline['commit'] ?? '') !== Suite::COMMIT || ($report['upstream']['commit'] ?? '') !== Suite::COMMIT || ($baseline['discovered'] ?? null) !== $report['discovered'] || ($report['filtered_out'] ?? 1) !== 0) {
            throw new \RuntimeException('Baseline must match the complete pinned suite.');
        }
        $passing = $baseline['passing'] ?? [];
        if (!is_array($passing) || !array_is_list($passing) || $passing === []) { throw new \RuntimeException('Baseline must contain a non-empty unique list of passing names.'); }
        foreach ($passing as $name) { if (!is_string($name) || $name === '') { throw new \RuntimeException('Baseline names must be non-empty strings.'); } }
        if (count(array_unique($passing)) !== count($passing)) { throw new \RuntimeException('Baseline must contain a non-empty unique list of passing names.'); }
        $byName = array_column($report['results'], null, 'name'); $regressions = [];
        foreach ($passing as $name) {
            if (($byName[$name]['status'] ?? null) !== 'PASS') { $regressions[] = $name; }
        }
        return $regressions;
    }
}
