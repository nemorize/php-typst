<?php

declare(strict_types=1);

namespace Nemorize\Typst;

use Nemorize\Typst\Export\PdfWriter;
use Nemorize\Typst\Font\FontBook;
use Nemorize\Typst\Layout\FlowLayout;
use Nemorize\Typst\Runtime\{Budget, Evaluator, ModuleResolver, StandardLibrary};
use Nemorize\Typst\Syntax\{Parser, Source};

final class Compiler
{
    public const TARGET_TYPST_VERSION = '0.15.1';
    private readonly FontBook $fonts;

    public function __construct()
    {
        $this->fonts = new FontBook();
    }

    /** @param array<string, string> $inputs */
    public function compile(string $source, ?string $root = null, array $inputs = []): CompilationResult
    {
        return $this->compileSource(new Source($source), $root ?? getcwd(), $inputs);
    }

    /** @param array<string, string> $inputs */
    public function compileFile(string $input, string $output, ?string $root = null, array $inputs = []): CompilationResult
    {
        $path = realpath($input);
        if ($path === false || !is_file($path) || !is_readable($path)) {
            throw new \RuntimeException("Cannot read input '{$input}'.");
        }
        if (realpath($output) === $path) {
            throw new \InvalidArgumentException('Input and output must be different files.');
        }
        $source = file_get_contents($path);
        if ($source === false) {
            throw new \RuntimeException("Cannot read input '{$input}'.");
        }
        $result = $this->compileSource(new Source($source, $path), $root ?? dirname($path), $inputs);
        $result->save($output);
        return $result;
    }

    private function compileSource(Source $source, string $root, array $inputs): CompilationResult
    {
        if (strlen($source->text) > 8 * 1024 * 1024) {
            throw new CompileException('Source exceeds the 8 MiB limit.');
        }
        foreach ($inputs as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                throw new \InvalidArgumentException('sys.inputs must contain string keys and values.');
            }
        }
        $modules = new ModuleResolver($root, new Budget());
        $tree = Parser::parse($source);
        $content = (new Evaluator($modules))->document($tree, StandardLibrary::environment($inputs));
        $pages = (new FlowLayout($this->fonts))->layout($content);
        return new CompilationResult((new PdfWriter($this->fonts))->write($pages), count($pages));
    }
}
