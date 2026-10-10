<?php

declare(strict_types=1);

namespace Nemorize\Typst\Tests\Official;

use Nemorize\Typst\CompileException;
use Nemorize\Typst\Syntax\Span;

final readonly class Diagnostic implements \JsonSerializable
{
    /** Range positions are 1-based character columns and end-exclusive. */
    public function __construct(public string $kind, public string $message, public ?array $range = null, public ?string $externalFile = null) {}

    public static function emitted(CompileException $error, string $main, string $root): self
    {
        $span = $error->span;
        $external = $span !== null && $span->source->name !== $main ? self::relative($span->source->name, $root) : null;
        $range = $span === null ? null : ['start' => self::position($span, $span->start), 'end' => self::position($span, $span->end)];
        return new self('Error', str_replace("\n", '\\n', $error->diagnosticMessage), $range, $external);
    }

    public static function position(Span $span, int $offset): array
    {
        $prefix = substr($span->source->text, 0, $offset);
        $last = strrpos($prefix, "\n");
        return ['line' => substr_count($prefix, "\n") + 1, 'column' => preg_match_all('/./us', substr($prefix, $last === false ? 0 : $last + 1)) + 1];
    }

    public function matches(self $other): bool { return $this->jsonSerialize() === $other->jsonSerialize(); }
    public function jsonSerialize(): array { return ['kind' => $this->kind, 'message' => $this->message, 'range' => $this->range, 'external_file' => $this->externalFile]; }

    private static function relative(string $path, string $root): string
    {
        $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return str_starts_with($path, $prefix) ? '/' . str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($prefix))) : $path;
    }
}
