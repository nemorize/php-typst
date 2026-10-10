<?php

declare(strict_types=1);

namespace Nemorize\Typst\Tests\Official;

use Nemorize\Typst\CompileException;
use Nemorize\Typst\Runtime\{Budget, Content, Environment, Evaluator, ModuleResolver, StandardLibrary};
use Nemorize\Typst\Syntax\{Parser, Source};

final class Runner
{
    // A missing known standard-library global is an implementation gap. Unknown
    // user variables and unrecognized syntax remain failures, not blanket skips.
    private const GLOBALS = ['type', 'repr', 'eval', 'calc', 'decimal', 'datetime', 'duration', 'label', 'panic', 'version', 'array', 'bytes', 'dictionary', 'regex', 'selector', 'symbol', 'path', 'std', 'ltr', 'rtl', 'direction', 'length', 'relative', 'angle', 'ratio', 'fraction', 'color', 'auto', 'content', 'function', 'module', 'arguments', 'bool', 'int', 'float', 'str', 'range', 'read', 'csv', 'json', 'yaml', 'xml', 'toml', 'cbor', 'metadata', 'query', 'state', 'counter', 'here', 'context', 'locate', 'image', 'math', 'plugin', 'text', 'par', 'heading', 'rect', 'box', 'block', 'grid', 'assert', 'black', 'white', 'red', 'blue', 'green', 'nan', 'inf', 'sym'];

    public function __construct(private readonly string $root) {}

    public function run(TestCase $case): array
    {
        $result = ['name' => $case->name, 'file' => $case->file, 'line' => $case->headerLine, 'stage' => $case->isEval() ? 'eval' : 'other', 'attributes' => $case->attributes, 'status' => 'NOT_RUN', 'reason' => 'Only explicit eval tests are executed; output comparison stages are pending.', 'assertions' => 0, 'expected_diagnostics' => $case->diagnostics, 'actual_diagnostics' => [], 'printed' => []];
        if (!$case->isEval()) { return $result; }
        $helpers = new Helpers(); $error = null; $content = null;
        $main = $this->root . '/' . $case->file;
        try {
            $factory = static function () use ($helpers): Environment {
                $builtins = StandardLibrary::environment(); $helpers->install($builtins);
                return new Environment($builtins);
            };
            $modules = new ModuleResolver($this->root, new Budget(50000), $factory);
            $env = $factory();
            $content = (new Evaluator($modules))->document(Parser::parse(new Source($case->source, $main)), $env);
        } catch (CompileException $caught) {
            $error = $caught; $result['actual_diagnostics'][] = Diagnostic::emitted($caught, $main, $this->root);
        } catch (\Throwable $caught) {
            $result['status'] = 'ERROR'; $result['reason'] = $caught::class . ': ' . $caught->getMessage();
        }
        $result['assertions'] = $helpers->assertions; $result['printed'] = $helpers->printed;
        if ($result['status'] === 'ERROR') { return $result; }
        $same = self::diagnosticsMatch($case->diagnostics, $result['actual_diagnostics']);
        if ($same && ($content === null || self::empty($content))) {
            $result['status'] = 'PASS'; $result['reason'] = $error === null ? 'Evaluation succeeded, assertions passed, and content is empty.' : 'Expected diagnostic message and source range match exactly.';
        } elseif ($error !== null && ($gap = self::unsupported($error->diagnosticMessage)) !== null) {
            $result['status'] = 'UNSUPPORTED'; $result['reason'] = $gap;
        } else {
            $result['status'] = 'FAIL';
            $result['reason'] = !$same ? 'Expected and actual diagnostics differ.' : 'eval test produced non-empty content.';
        }
        if ($error?->span !== null && $error->span->source->name === $main) {
            $position = Diagnostic::position($error->span, $error->span->start);
            $result['diagnostic_file_line'] = $case->sourceLines[$position['line']] ?? null;
        }
        return $result;
    }

    public static function diagnosticsMatch(array $expected, array $actual): bool
    {
        if (count($expected) !== count($actual)) { return false; }
        $remaining = $actual;
        foreach ($expected as $note) {
            foreach ($remaining as $i => $emitted) { if ($note->matches($emitted)) { unset($remaining[$i]); continue 2; } }
            return false;
        }
        return true;
    }

    private static function empty(Content $content): bool
    {
        if ($content->kind === 'text') { return preg_match('/^\s*$/u', $content->attributes['text']) === 1; }
        if ($content->kind === 'parbreak' || $content->kind === 'set') { return true; }
        if ($content->kind === 'sequence') { foreach ($content->children as $child) { if (!self::empty($child)) { return false; } } return true; }
        return false;
    }

    private static function unsupported(string $message): ?string
    {
        // Assertion text may itself contain words such as "not implemented".
        // A failed assertion is evidence of a mismatch, never a feature skip.
        if (preg_match('/^(?:assertion failed|(?:in)?equality assertion failed)/i', $message)) { return null; }
        if (preg_match('/not implemented|unimplemented|Only the bundled|currently (?:expects|accepts|requires)|Currently table/', $message) && !str_starts_with($message, 'Unknown identifier')) { return $message; }
        if (preg_match("/Unknown identifier '([^']+)'/", $message, $m) && in_array($m[1], self::GLOBALS, true)) { return "Missing standard-library global: {$m[1]}"; }
        return null;
    }
}
