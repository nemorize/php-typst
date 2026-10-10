<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Nemorize\Typst\Tests\Official\{Baseline, Report, Runner, Suite};

set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    $options = ['filter' => null, 'report' => dirname(__DIR__, 2) . '/build/official-tests', 'baseline' => null, 'verbose' => false, 'list' => false];
    for ($i = 1; $i < count($argv); $i++) {
        $argument = $argv[$i];
        if ($argument === '--help') {
            fwrite(STDOUT, "Usage: php tests/official/run.php [--filter TEXT] [--report DIRECTORY] [--baseline FILE] [--verbose] [--list]\nFull scan exits 1 for failures or unsupported eval tests. Baseline mode only gates the committed passing cases. Output stages are NOT_RUN.\n");
            exit(0);
        }
        if ($argument === '--verbose' || $argument === '--list') {
            $options[substr($argument, 2)] = true;
            continue;
        }
        if (in_array($argument, ['--filter', '--report', '--baseline'], true) && isset($argv[$i + 1])) {
            $options[substr($argument, 2)] = $argv[++$i];
            continue;
        }
        throw new InvalidArgumentException("Unknown or incomplete option: {$argument}");
    }
    if ($options['baseline'] !== null && $options['filter'] !== null) {
        throw new InvalidArgumentException('Baseline checks must run the entire suite without a filter.');
    }
    $suite = new Suite(dirname(__DIR__) . '/upstream/typst-0.15.1');
    $suite->verify();
    $cases = $suite->cases();
    $selected = array_values(array_filter($cases, fn($case) => $options['filter'] === null || str_contains($case->name, $options['filter']) || str_contains($case->file, $options['filter'])));
    if ($selected === []) {
        throw new RuntimeException('No tests matched the filter.');
    }
    if ($options['list']) {
        foreach ($selected as $case) {
            fwrite(STDOUT, $case->name . ' [' . implode(' ', $case->attributes) . "] {$case->file}:{$case->headerLine}\n");
        } exit(0);
    }
    if (!array_filter($selected, fn($case) => $case->isEval())) {
        throw new RuntimeException('No executable eval tests matched; use --list to inspect output tests.');
    }
    $runner = new Runner($suite->root);
    $results = [];
    foreach ($selected as $case) {
        $result = $runner->run($case);
        $results[] = $result;
        if ($options['verbose']) {
            fwrite(STDOUT, "{$result['status']} {$case->name}: {$result['reason']}\n");
        }
    }
    $report = Report::build($results, $suite->manifest, count($cases), $options['filter']);
    Report::save($report, $options['report']);
    fwrite(STDOUT, "Official Typst {$suite->manifest['version']}: " . count($cases) . ' discovered, ' . count($selected) . " selected\n");
    foreach ($report['counts'] as $status => $count) {
        fwrite(STDOUT, "{$status}: {$count}\n");
    }
    fwrite(STDOUT, "Reports: {$options['report']}/report.json and report.html\n");
    $failed = $report['counts']['FAIL'] + $report['counts']['UNSUPPORTED'] + $report['counts']['ERROR'] > 0;
    if ($options['baseline'] !== null) {
        $baseline = json_decode(file_get_contents($options['baseline']), true, flags: JSON_THROW_ON_ERROR);
        $regressions = Baseline::regressions($baseline, $report);
        $failed = $regressions !== [] || $report['counts']['ERROR'] > 0;
        foreach ($regressions as $name) {
            fwrite(STDERR, "REGRESSION {$name}\n");
        }
        fwrite(STDOUT, 'Baseline: ' . count($baseline['passing']) . ' required passes, ' . count($regressions) . " regressions. This does not certify full conformance.\n");
    }
    exit($failed ? 1 : 0);
} catch (Throwable $error) {
    fwrite(STDERR, $error::class . ': ' . $error->getMessage() . "\n");
    exit(2);
}
