<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';

$output = $argv[1] ?? __DIR__ . '/../build/example.pdf';
if (!is_dir(dirname($output))) { mkdir(dirname($output), 0777, true); }
$result = (new \Nemorize\Typst\Compiler())->compileFile(__DIR__ . '/document.typ', $output);
echo "Saved {$result->pageCount} pages to {$output}\n";
