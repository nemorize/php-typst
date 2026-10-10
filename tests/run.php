<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';

use Nemorize\Typst\{Compiler, CompileException};
use Nemorize\Typst\Font\{FontBook, Unicode};
use Nemorize\Typst\Layout\FlowLayout;
use Nemorize\Typst\Runtime\{Budget, Content, Environment, Evaluator, ModuleResolver, StandardLibrary};
use Nemorize\Typst\Syntax\{Parser, Source};

set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$tests = [];
function test(string $name, Closure $test): void
{
    global $tests;
    $tests[$name] = $test;
}
function same(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Expected ' . var_export($expected, true) . ', received ' . var_export($actual, true));
    }
}
function check(bool $condition, string $message = 'Assertion failed'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function rejects(Closure $callback, string $message, string $type = CompileException::class): void
{
    try {
        $callback();
    } catch (Throwable $error) {
        check($error instanceof $type, 'Unexpected exception type: ' . $error::class . ': ' . $error->getMessage());
        check(str_contains($error->getMessage(), $message), 'Unexpected error message: ' . $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected an exception containing: ' . $message);
}
function evaluate(string $source, array $inputs = []): Content
{
    $modules = new ModuleResolver(__DIR__ . '/fixtures/modules', new Budget());
    return (new Evaluator($modules))->document(Parser::parse(new Source($source)), StandardLibrary::environment($inputs));
}
function plain(Content $content): string
{
    if ($content->kind === 'text' || $content->kind === 'raw') {
        return $content->attributes['text'];
    }
    return implode('', array_map(plain(...), $content->children));
}
function value(string $source): string
{
    return trim(plain(evaluate($source)));
}
function frames(string $source): array
{
    static $fonts = new FontBook();
    return (new FlowLayout($fonts))->layout(evaluate($source));
}
function frameText(array $pages): string
{
    $text = '';
    foreach ($pages as $page) {
        foreach ($page->items as $item) {
            if ($item['kind'] === 'text') {
                $text .= $item['text'];
            }
        }
    }
    return $text;
}

test('markup headings, emphasis and nested content', function (): void {
    $tree = Parser::parse(new Source("= Title\n\nA *bold _word_* and [content]."));
    same('heading', $tree->children[0]->kind);
    same('Title A bold word and content.', trim(preg_replace('/\s+/', ' ', plain(evaluate("= Title\n\nA *bold _word_* and [content].")))));
});
test('nested comments and escapes', function (): void {
    same('ab # $ *', value('a/* outer /* inner */ end */b \\# \\$ \\*'));
});
test('Unicode escapes without mbstring', function (): void {
    same('한🙂', value('\\u{d55c}\\u{1f642}'));
    same(0x1f642, Unicode::codepoint('🙂'));
});
test('CRLF and CR source normalization', function (): void {
    same("a\nb\nc", (new Source("a\r\nb\rc"))->text);
    same('a b', value("a\rb"));
});
test('raw text preserves markup characters', function (): void {
    same('#*literal*', value('`#*literal*`'));
});
test('invalid UTF-8 is rejected', function (): void {
    rejects(fn() => new Source("\xff"), 'UTF-8', InvalidArgumentException::class);
});
test('unclosed delimiters have source diagnostics', function (): void {
    foreach (['[x' => 'content block', '*x' => 'emphasis', '$x' => 'equation', '`x' => 'raw text', '/*x' => 'comment', '#"x' => 'string'] as $source => $message) {
        rejects(fn() => evaluate($source), $message);
    }
});
test('source columns count Unicode characters', function (): void {
    rejects(fn() => evaluate("한글 #missing"), '<string>:1:5');
});
test('embedded code ends before markup emphasis', function (): void {
    same('Hello world!', value('#let name = "world";' . "\n" . 'Hello *#name*!'));
});
test('embedded values do not consume markup operators', function (): void {
    same('1 + 2', value('#let x = 1; #x + 2'));
});
test('embedded values preserve punctuation and spaced parentheses', function (): void {
    same('42. 42 (answer)', value('#let x = 42; #x. #x (answer)'));
});
test('URLs are rejected instead of swallowed as comments', function (): void {
    rejects(fn() => evaluate('Visit https://typst.app/ today.'), 'Hyperlinks are not implemented');
    same('https://typst.app/', value('`https://typst.app/`'));
});
test('numeric equality and boolean type distinction', function (): void {
    same('true false', value('#(1 == 1.0) #(true == 1)'));
});
test('recursive functions compare without traversing captured environments', function (): void {
    same('true false', value('#let f(x) = f(x); #let g(x) = g(x); #(f == f) #(f == g)'));
});
test('assertion members and diagnostics', function (): void {
    same('', value('#assert.eq((a: 1, b: 2), (b: 2, a: 1)); #assert.ne(true, 1);'));
    rejects(fn() => evaluate('#assert.eq(1, 2)'), 'equality assertion failed: value 1 was not equal to 2');
    rejects(fn() => evaluate('#assert.ne(1, 1)'), 'inequality assertion failed: value 1 was equal to 1');
    rejects(fn() => evaluate('#assert("x")'), 'expected boolean, found string');
    rejects(fn() => evaluate('#assert(false, message: "details")'), 'assertion failed: details');
});
test('float to int overflow emits a source diagnostic rather than a PHP warning', function (): void {
    rejects(fn() => evaluate('#int(9223372036854775808.0)'), 'number too large');
    rejects(fn() => evaluate('#int(-9223372036854777856.0)'), 'number too large');
    same('-9223372036854775808', value('#int(-9223372036854775808.0)'));
});
test('official test helpers are absent from the normal standard library', function (): void {
    rejects(fn() => evaluate('#test(1, 1)'), "Unknown identifier 'test'");
    rejects(fn() => evaluate('#test-repr(1, 1)'), "Unknown identifier 'test-repr'");
});
test('operator precedence and grouping', function (): void {
    same('14', value('#(2 + 3 * 4)'));
    same('20', value('#((2 + 3) * 4)'));
});
test('named functions and recursion', function (): void {
    same('120', value('#let f(x) = if x == 0 { 1 } else { x * f(x - 1) }; #f(5)'));
});
test('anonymous functions and default named arguments', function (): void {
    same('9', value('#let f = (x, y) => x + y; #f(4, 5)'));
    same('Hi PHP!', value('#let greet(x, suffix: "!") = [Hi #x#suffix]; #greet("PHP")'));
    same('Hi PHP?', value('#let greet(x, suffix: "!") = [Hi #x#suffix]; #greet("PHP", suffix: "?")'));
});
test('lexical scoping of content bindings', function (): void {
    same('inner outer', value('#let x = "outer"; [#let x = "inner"; #x] #x'));
});
test('content-valued function output is evaluated at call time', function (): void {
    same('PHP Typst', value('#let label(x) = [#x]; #label("PHP") #label("Typst")'));
});
test('strict argument checks', function (): void {
    rejects(fn() => evaluate('#let f(x) = x; #f()'), 'Missing function argument');
    rejects(fn() => evaluate('#let f(x) = x; #f(1, 2)'), 'Unexpected function arguments');
    rejects(fn() => evaluate('#text(size: 10pt, size: 12pt)[x]'), 'Duplicate argument');
    rejects(fn() => evaluate('#let f(x, x) = x;'), 'Duplicate parameter');
});
test('short-circuit boolean evaluation', function (): void {
    same('false true', value('#(false and missing) #(true or missing)'));
});
test('if/else and range for loops', function (): void {
    same('yes 0123', value('#if true [yes] else [no] #for x in range(4) [#x]'));
});
test('range bounds and negative step', function (): void {
    same('321', value('#for x in range(3, 0, step: -1) [#x]'));
    rejects(fn() => evaluate('#range(2, step: 0)'), 'nonzero');
});
test('array and dictionary access', function (): void {
    same('Ada', value('#let data = (name: "Ada", count: 3); #data.name'));
    same('123', value('#for x in (1, 2, 3) [#x]'));
});
test('string concatenation and content joining', function (): void {
    same('PHP Typst', value('#("PHP" + " Typst")'));
    same('AB', value('#([A] + [B])'));
});
test('dimensions parse em and exponents correctly', function (): void {
    $env = StandardLibrary::environment();
    $modules = new ModuleResolver(__DIR__, new Budget());
    (new Evaluator($modules))->document(Parser::parse(new Source('#let x = 2em; #let y = 1e2pt;')), $env);
    same('em', $env->values['x']->unit);
    same(22.0, $env->values['x']->points());
    same(100.0, $env->values['y']->points());
});
test('module imports and exported functions', function (): void {
    same('49 Hello PHP!', value('#import "helpers.typ": square, label; #square(7) #label("PHP")'));
});
test('module namespace, alias and wildcard', function (): void {
    same('9', value('#import "helpers.typ"; #helpers.square(3)'));
    same('16', value('#import "helpers.typ" as h; #h.square(4)'));
    same('25', value('#import "helpers.typ": *; #square(5)'));
});
test('module include and root-relative path', function (): void {
    same('Included module content.', value('#include "/include.typ"'));
});
test('modules cannot traverse outside project root', function (): void {
    rejects(fn() => evaluate('#include "../../../composer.json"'), 'outside the project root');
});
test('circular modules are rejected', function (): void {
    rejects(fn() => evaluate('#import "cycle-a.typ"'), 'Circular module import');
});
test('unknown module export is diagnosed', function (): void {
    rejects(fn() => evaluate('#import "helpers.typ": missing;'), 'does not export');
});
test('unimplemented features fail explicitly', function (): void {
    foreach (['#show heading: none' => 'not implemented', '#context [x]' => 'not implemented', '#import "@preview/pkg:1.0.0"' => 'Package imports', '#unknown()' => 'Unknown identifier', '#set text(tracking: 1pt)' => 'not implemented'] as $source => $message) {
        rejects(fn() => evaluate($source), $message);
    }
});
test('no PHP expression execution', function (): void {
    rejects(fn() => evaluate('#shell_exec("echo unsafe")'), 'Unknown identifier');
});
test('division and invalid operand errors have source locations', function (): void {
    rejects(fn() => evaluate('#(1 / 0)'), '<string>:1:');
    rejects(fn() => evaluate('#("2" * 3)'), 'Expected a number');
});
test('recursion and parser depth limits', function (): void {
    rejects(fn() => evaluate('#let f(x) = f(x); #f(1)'), 'nesting limit');
    rejects(fn() => evaluate(str_repeat('[', 150) . 'x' . str_repeat(']', 150)), 'nesting limit');
});
test('plain text and Korean glyph layout', function (): void {
    same('Hello 한국어', frameText(frames('Hello 한국어')));
});
test('font style and color selection', function (): void {
    $items = frames('*Bold* _Italic_ #text(fill: rgb("#ff0000"))[Red]')[0]->items;
    same(1, $items[0]['font']);
    check($items[2]['italic']);
    same([1, 0, 0], array_map(fn($v) => (int) $v, $items[4]['color']));
});
test('positional text size works with trailing content', function (): void {
    same(14.0, frames('#text(14pt)[Hello]')[0]->items[0]['size']);
});
test('math fractions, attachments and square roots', function (): void {
    $items = frames('$ frac(a + b, 2) = sqrt(x^2 + y_1) $')[0]->items;
    check(count(array_filter($items, fn($v) => $v['kind'] === 'line')) >= 2);
    check(count(array_filter($items, fn($v) => $v['kind'] === 'text' && $v['size'] < 11)) >= 2);
});
test('unsupported math syntax is rejected', function (): void {
    rejects(fn() => frames('$mat(1, 2)$'), 'math symbol');
    rejects(fn() => frames('$x^$'), 'Expected math');
});
test('table cells wrap and have row borders', function (): void {
    $page = frames('#table(columns: 2, [Name], [Value], [PHP], [8.5])')[0];
    same('NameValuePHP8.5', frameText([$page]));
    same(16, count(array_filter($page->items, fn($v) => $v['kind'] === 'line')));
});
test('page settings and explicit page breaks', function (): void {
    $pages = frames('#set page(width: 100mm, height: 120mm, margin: 10mm); One #pagebreak() Two');
    same(2, count($pages));
    check(abs($pages[0]->width - 283.4646) < 0.001);
    same('OneTwo', frameText($pages));
});
test('automatic page overflow and long word breaking', function (): void {
    $pages = frames('#set page(width: 90pt, height: 100pt, margin: 10pt); ' . str_repeat('abcdefghij ', 40));
    check(count($pages) > 1);
    foreach ($pages as $page) {
        foreach ($page->items as $item) {
            check($item['y'] <= $page->height - 10);
        }
    }
});
test('invalid page, table and font settings', function (): void {
    rejects(fn() => frames('#set page(width: 10pt, margin: 20pt); x'), 'Invalid page geometry');
    rejects(fn() => frames('#table(columns: 0, [x])'), 'table columns');
    rejects(fn() => frames('#set text(size: -1pt); x'), 'positive');
    rejects(fn() => frames('#set text(font: "missing"); x'), 'Only the bundled');
});
test('sys.inputs passed as inert strings', function (): void {
    same('#shell_exec("bad")', trim(plain(evaluate('#sys.inputs.value', ['value' => '#shell_exec("bad")']))));
});
test('PDF header, cross-reference offsets and Unicode font maps', function (): void {
    $result = (new Compiler())->compile('한글 *Bold* $x^2$');
    same(1, $result->pageCount);
    check(str_starts_with($result->pdf, '%PDF-1.7'));
    check(str_contains($result->pdf, '/FontFile2'));
    check(str_contains($result->pdf, '/ToUnicode'));
    check(str_contains($result->pdf, '<D55C>'));
    preg_match('/startxref\n(\d+)\n%%EOF/', $result->pdf, $m);
    $xref = substr($result->pdf, (int) $m[1]);
    check(str_starts_with($xref, "xref\n"));
    preg_match_all('/^(\d{10}) 00000 n /m', $xref, $offsets);
    foreach ($offsets[1] as $i => $offset) {
        check(str_starts_with(substr($result->pdf, (int) $offset), ($i + 1) . ' 0 obj'), 'Invalid PDF object offset');
    }
});
test('PDF compilation is deterministic and compiler state is isolated', function (): void {
    $compiler = new Compiler();
    $a = $compiler->compile('Hello')->pdf;
    $compiler->compile('#set text(24pt); Other');
    same($a, $compiler->compile('Hello')->pdf);
});
test('empty source emits one valid blank page', function (): void {
    $r = (new Compiler())->compile('');
    same(1, $r->pageCount);
    check(str_contains($r->pdf, '/Count 1'));
});
test('file compilation and failed output preservation', function (): void {
    $dir = sys_get_temp_dir() . '/php-typst-' . bin2hex(random_bytes(6));
    mkdir($dir);
    try {
        file_put_contents($dir . '/document.typ', 'Hello');
        file_put_contents($dir . '/output.pdf', 'original');
        $compiler = new Compiler();
        $result = $compiler->compileFile($dir . '/document.typ', $dir . '/output.pdf');
        same($result->pdf, file_get_contents($dir . '/output.pdf'));
        file_put_contents($dir . '/document.typ', '#missing');
        rejects(fn() => $compiler->compileFile($dir . '/document.typ', $dir . '/output.pdf'), 'Unknown identifier');
        same($result->pdf, file_get_contents($dir . '/output.pdf'));
        rejects(fn() => $compiler->compileFile($dir . '/document.typ', $dir . '/document.typ'), 'different files', InvalidArgumentException::class);
        same([], glob($dir . '/.php-typst-*'));
    } finally {
        unlink($dir . '/document.typ');
        unlink($dir . '/output.pdf');
        rmdir($dir);
    }
});
test('example exercises full compiler pipeline', function (): void {
    $compiler = new Compiler();
    $source = file_get_contents(dirname(__DIR__) . '/examples/document.typ');
    $result = $compiler->compile($source, dirname(__DIR__) . '/examples');
    same(2, $result->pageCount);
});

$failed = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        fwrite(STDOUT, "PASS {$name}\n");
    } catch (Throwable $error) {
        $failed++;
        fwrite(STDERR, "FAIL {$name}\n  {$error->getMessage()}\n");
    }
}
fwrite(STDOUT, count($tests) . " tests, {$failed} failures\n");
exit($failed === 0 ? 0 : 1);
