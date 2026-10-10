<?php

declare(strict_types=1);

namespace Nemorize\Typst\Runtime;

use Nemorize\Typst\CompileException;
use Nemorize\Typst\Syntax\{Parser, Source, Span};

final class ModuleResolver
{
    private array $cache = [];
    private array $loading = [];
    public readonly string $root;

    public function __construct(string $root, public readonly Budget $budget, private readonly ?\Closure $environmentFactory = null)
    {
        $canonical = realpath($root);
        if ($canonical === false || !is_dir($canonical)) { throw new \InvalidArgumentException('Project root must be an existing directory.'); }
        $this->root = $canonical;
    }

    public function load(string $path, Span $from): Module
    {
        if (str_starts_with($path, '@')) { throw new CompileException('Package imports are not implemented yet.', $from); }
        $base = is_file($from->source->name) ? dirname($from->source->name) : $this->root;
        $target = str_starts_with($path, '/') ? $this->root . $path : $base . DIRECTORY_SEPARATOR . $path;
        $canonical = realpath($target);
        $prefix = rtrim($this->root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if ($canonical === false || !is_file($canonical) || !str_starts_with($canonical, $prefix)) {
            throw new CompileException("Module '{$path}' is missing or outside the project root.", $from);
        }
        if (isset($this->loading[$canonical])) { throw new CompileException("Circular module import: {$path}.", $from); }
        if (count($this->loading) >= 64) { throw new CompileException('Module nesting limit exceeded.', $from); }
        if (isset($this->cache[$canonical])) { return $this->cache[$canonical]; }
        $this->loading[$canonical] = true;
        try {
            $text = file_get_contents($canonical);
            if ($text === false || strlen($text) > 8 * 1024 * 1024) { throw new CompileException('Module is unreadable or exceeds 8 MiB.', $from); }
            $environment = $this->environmentFactory === null ? StandardLibrary::environment() : ($this->environmentFactory)();
            $content = (new Evaluator($this))->document(Parser::parse(new Source($text, $canonical)), $environment);
            // Export only module-defined bindings, not the parent standard library.
            return $this->cache[$canonical] = new Module($environment->values, $content);
        } finally { unset($this->loading[$canonical]); }
    }
}
