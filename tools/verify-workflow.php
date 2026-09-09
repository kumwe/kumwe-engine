<?php
declare(strict_types=1);

function read_json(string $path): mixed {
    return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}
function require_equal(mixed $actual, mixed $expected, string $message): void {
    if ($actual !== $expected) { throw new RuntimeException($message); }
}
switch ($argv[1] ?? '') {
    case 'json-equal':
        if ($argc !== 4) { throw new InvalidArgumentException('json-equal requires two paths.'); }
        require_equal(read_json($argv[2]), read_json($argv[3]), 'Consumer tuples differ.');
        break;
    case 'module':
        if ($argc !== 4) { throw new InvalidArgumentException('module requires fixture and module paths.'); }
        $fixture = read_json($argv[2]);
        require_equal(hash_file('sha256', $argv[3]), $fixture['native_module_probe']['sha256'], 'Downloaded module differs from captured module probe.');
        break;
    default:
        throw new InvalidArgumentException('Usage: verify-workflow.php json-equal|module [paths]');
}
echo "Workflow source and artifact identity verified.\n";
