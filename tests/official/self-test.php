<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Nemorize\Typst\CompileException;
use Nemorize\Typst\Runtime\{ValueEquality, Builtin};
use Nemorize\Typst\Tests\Official\{Baseline, Diagnostic, Report, Runner, Suite};

set_error_handler(static function (int $severity, string $message, string $file, int $line): never { throw new ErrorException($message, 0, $severity, $file, $line); });

$tests = [];
function test(string $name, Closure $callback): void { global $tests; $tests[$name] = $callback; }
function same(mixed $expected, mixed $actual): void { if ($expected !== $actual) { throw new RuntimeException('Expected ' . var_export($expected, true) . ', received ' . var_export($actual, true)); } }
function check(bool $condition): void { if (!$condition) { throw new RuntimeException('Assertion failed'); } }
function rejects(Closure $callback, string $message): void
{
    try { $callback(); } catch (Throwable $error) { check(str_contains($error->getMessage(), $message)); return; }
    throw new RuntimeException('Expected rejection: ' . $message);
}
function runCase(string $body, string $attrs = 'eval'): array
{
    $case = Suite::parseFile('tests/suite/self-test.typ', "--- self-test {$attrs} ---\n{$body}")[0];
    return (new Runner(dirname(__DIR__) . '/upstream/typst-0.15.1'))->run($case);
}

test('sections preserve names, attributes and physical file lines', function (): void {
    $cases = Suite::parseFile('test.typ', "// preamble\n\n--- first eval ---\n#test(1, 1)\n\n--- second paged empty pdfstandard(a-2a, ua-1) ---\nx\n");
    same(2, count($cases)); same('first', $cases[0]->name); same(3, $cases[0]->headerLine); same(4, $cases[0]->sourceLines[1]);
    same(['paged', 'empty', 'pdfstandard(a-2a, ua-1)'], $cases[1]->attributes);
});
test('upstream skipped module files are not collected', function (): void { same([], Suite::parseFile('module.typ', "// SKIP\n#let value = 1\n")); });
test('non-comment preambles and malformed headers fail', function (): void {
    rejects(fn () => Suite::parseFile('a.typ', "#let x = 1\n--- test eval ---\n"), 'preamble');
    rejects(fn () => Suite::parseFile('a.typ', "--- test unknown ---\n"), 'Unknown test attribute');
    rejects(fn () => Suite::parseFile('a.typ', "--- test eval ??? ---\n"), 'Invalid test attributes');
    rejects(fn () => Suite::parseFile('a.typ', "--- test ---\n"), 'Invalid test header');
});
test('annotation lines are removed and all annotations attach to the next source line', function (): void {
    $case = Suite::parseFile('a.typ', "--- a eval ---\n// Error: 2-4 bad\n// Hint: 3 hint\nαβγ\n")[0];
    same("αβγ\n", $case->source); same(4, $case->sourceLines[1]);
    same(['start' => ['line' => 1, 'column' => 2], 'end' => ['line' => 1, 'column' => 4]], $case->diagnostics[0]->range);
    same(1, $case->diagnostics[1]->range['start']['line']);
});
test('relative multiline ranges use the next non-annotation line as their base', function (): void {
    $note = Suite::annotation('Error', '2:3-3:5 message', 7);
    same(['start' => ['line' => 8, 'column' => 3], 'end' => ['line' => 9, 'column' => 5]], $note->range);
});
test('external ranges are absolute and VERSION placeholders are expanded', function (): void {
    $note = Suite::annotation('Error', '"tests/suite/module.typ" 3:2-4:5 compiler VERSION', 8);
    same('/tests/suite/module.typ', $note->externalFile); same(3, $note->range['start']['line']); same('compiler 0.15.1', $note->message);
});
test('detached diagnostic notes and zero-width ranges are distinct', function (): void {
    same(null, Suite::annotation('Error', 'message only', 1)->range);
    $note = Suite::annotation('Error', '3 message', 2); same($note->range['start'], $note->range['end']);
    rejects(fn () => Suite::annotation('Error', '7-2 message', 1), 'Reversed');
});
test('test helper assertions are executed rather than ignored', function (): void {
    $result = runCase('#test(1, 1) #test("x", "x")'); same('PASS', $result['status']); same(2, $result['assertions']);
    $result = runCase('#test(1, 2)'); same('FAIL', $result['status']); same(1, $result['assertions']); check(str_contains($result['actual_diagnostics'][0]->message, 'Assertion failed'));
});
test('test helper equality is strict for scalar types and recursive in arrays', function (): void {
    same('FAIL', runCase('#test(true, 1)')['status']); same('PASS', runCase('#test((1, 2), (1.0, 2))')['status']);
    same('PASS', runCase('#test((a: 1, b: 2), (b: 2, a: 1))')['status']);
});
test('function equality uses identity without following recursive environments', function (): void {
    same('PASS', runCase('#let f(x) = f(x); #test(f, f)')['status']);
    same('PASS', runCase('#let f(x) = f(x); #let g(x) = g(x); #test(f == g, false)')['status']);
    check(ValueEquality::equals(new Builtin('int'), new Builtin('int')));
});
test('test-repr and print helpers have observable results', function (): void {
    $r = runCase('#test-repr(1, 1) #print("safe", 2)'); same('PASS', $r['status']); same(1, $r['assertions']); same(1, count($r['printed']));
    same('FAIL', runCase('#test-repr(1, 1.0)')['status']);
});
test('diagnostic pass requires exact kind, message and both positions', function (): void {
    same('PASS', runCase("// Error: 2-15 assertion failed\n#assert(false)")['status']);
    same('FAIL', runCase("// Error: 2-15 wrong message\n#assert(false)")['status']);
    same('FAIL', runCase("// Error: 3-15 assertion failed\n#assert(false)")['status']);
    same('FAIL', runCase("// Warning: 2-15 assertion failed\n#assert(false)")['status']);
});
test('missing and unexpected diagnostics cannot pass', function (): void {
    same('FAIL', runCase("// Error: 2-15 assertion failed\n#assert(true)")['status']);
    same('FAIL', runCase('#assert(false)')['status']);
    same('FAIL', runCase("// Error: 2-15 assertion failed\n// Hint: extra diagnostic\n#assert(false)")['status']);
});
test('Unicode diagnostic columns use characters rather than bytes', function (): void {
    $r = runCase('// ordinary comment' . "\n" . '#let 한글 = 1; #missing');
    same(15, $r['actual_diagnostics'][0]->range['start']['column']);
});
test('unknown user identifiers stay failures while known missing globals are unsupported', function (): void {
    same('FAIL', runCase('#misspelled')['status']); same('UNSUPPORTED', runCase('#type(1)')['status']);
    same('UNSUPPORTED', runCase('#show heading: none')['status']);
});
test('assertion messages cannot turn mismatches into unsupported skips', function (): void {
    same('FAIL', runCase('#test("not implemented", "other")')['status']);
    same('FAIL', runCase('#assert(false, message: "unimplemented")')['status']);
});
test('test helpers are inherited in imported modules without becoming exports', function (): void {
    $dir = sys_get_temp_dir() . '/typst-module-' . bin2hex(random_bytes(6)); mkdir($dir);
    try {
        file_put_contents($dir . '/helper.typ', '// SKIP' . "\n" . '#test(1, 1); #let answer = 42;');
        $case = Suite::parseFile('main.typ', "--- module eval ---\n#import \"helper.typ\": answer; #test(answer, 42)")[0];
        $r = (new Runner($dir))->run($case); same('PASS', $r['status']); same(2, $r['assertions']);
        $case = Suite::parseFile('main.typ', "--- module eval ---\n#import \"helper.typ\": test;")[0];
        same('FAIL', (new Runner($dir))->run($case)['status']);
    } finally { unlink($dir . '/helper.typ'); rmdir($dir); }
});
test('eval tests must produce empty content', function (): void { same('FAIL', runCase('Visible')['status']); same('PASS', runCase(" \n\n")['status']); });
test('output stages are explicitly not executed', function (): void { same('NOT_RUN', runCase('#missing', 'paged')['status']); });
test('unexpected host failures are reported as ERROR', function (): void {
    $case = Suite::parseFile('main.typ', "--- host-error eval ---\n#test(1, 1)")[0];
    $runner = new Runner(__DIR__ . '/missing-' . bin2hex(random_bytes(6)));
    same('ERROR', $runner->run($case)['status']);
});
test('HTML report escapes test-derived strings', function (): void {
    $dir = sys_get_temp_dir() . '/typst-report-' . bin2hex(random_bytes(6));
    try {
        $result = runCase('#test("<script>bad</script>", "other")');
        $report = Report::build([$result], ['version' => '0.15.1', 'commit' => Suite::COMMIT], 1, null); Report::save($report, $dir);
        $html = file_get_contents($dir . '/report.html'); check(!str_contains($html, '<script>bad</script>')); check(str_contains($html, '&lt;script&gt;'));
        same(1, json_decode(file_get_contents($dir . '/report.json'), true)['counts']['FAIL']);
    } finally { foreach (glob($dir . '/*') as $file) { unlink($file); } if (is_dir($dir)) { rmdir($dir); } }
});
test('baseline enforces required passes rather than allowing skipped regressions', function (): void {
    $r = Report::build([runCase('#test(1, 1)')], ['version' => '0.15.1', 'commit' => Suite::COMMIT], 1, null);
    $b = ['commit' => Suite::COMMIT, 'discovered' => 1, 'passing' => ['self-test']]; same([], Baseline::regressions($b, $r));
    foreach (['FAIL', 'UNSUPPORTED', 'NOT_RUN', 'ERROR'] as $status) { $r['results'][0]['status'] = $status; same(['self-test'], Baseline::regressions($b, $r)); }
    $b['passing'] = [['invalid']]; rejects(fn () => Baseline::regressions($b, $r), 'non-empty strings');
    $b['passing'] = ['self-test', 'self-test']; rejects(fn () => Baseline::regressions($b, $r), 'unique list');
    $b['passing'] = ['self-test'];
    $r['filtered_out'] = 1; rejects(fn () => Baseline::regressions($b, $r), 'complete pinned suite');
});
test('fixtures are pinned and tampering is rejected', function (): void {
    $suite = new Suite(dirname(__DIR__) . '/upstream/typst-0.15.1'); $suite->verify();
    same(3684, count($suite->cases())); same(1267, count(array_filter($suite->cases(), fn ($case) => $case->isEval())));
    $dir = sys_get_temp_dir() . '/typst-fixture-' . bin2hex(random_bytes(6)); mkdir($dir);
    try {
        file_put_contents($dir . '/sample.typ', 'modified');
        file_put_contents($dir . '/manifest.json', json_encode(['version' => '0.15.1', 'commit' => Suite::COMMIT, 'files' => ['sample.typ' => hash('sha256', 'original')]]));
        rejects(fn () => (new Suite($dir))->verify(), 'modified');
        file_put_contents($dir . '/sample.typ', 'original');
        file_put_contents($dir . '/extra.typ', 'extra');
        rejects(fn () => (new Suite($dir))->verify(), 'Unexpected files');
        unlink($dir . '/extra.typ');
    } finally { unlink($dir . '/sample.typ'); unlink($dir . '/manifest.json'); rmdir($dir); }
});

$failed = 0;
foreach ($tests as $name => $callback) {
    try { $callback(); fwrite(STDOUT, "PASS {$name}\n"); }
    catch (Throwable $error) { $failed++; fwrite(STDERR, "FAIL {$name}: {$error->getMessage()}\n"); }
}
fwrite(STDOUT, count($tests) . " official-runner checks, {$failed} failures\n"); exit($failed === 0 ? 0 : 1);
