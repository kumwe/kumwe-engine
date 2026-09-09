<?php
declare(strict_types=1);

function read_json(string $path): mixed {
    return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}
function require_equal(mixed $actual, mixed $expected, string $message): void {
    if ($actual !== $expected) { throw new RuntimeException($message); }
}
$root = dirname(__DIR__);
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
    case 'engine-ref':
        $lock = read_json($root . '/resources/engine-lock.json');
        if (!is_string($lock['commit']) || !preg_match('/^[a-f0-9]{40}$/D', $lock['commit'])) {
            throw new RuntimeException('Engine source must be an exact commit.');
        }
        $output = getenv('GITHUB_OUTPUT');
        if (!$output || file_put_contents($output, 'commit=' . $lock['commit'] . "\n", FILE_APPEND) === false) {
            throw new RuntimeException('Cannot record the Engine source identity.');
        }
        break;
    case 'upstream':
        if ($argc !== 3) { throw new InvalidArgumentException('upstream requires an Engine checkout.'); }
        $lock = read_json($root . '/resources/engine-lock.json');
        $pipes = [];
        $process = proc_open(['git', '-C', $argv[2], 'archive', '--format=tar', $lock['commit']],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('Cannot read upstream source archive.'); }
        fclose($pipes[0]);
        $digest = hash_init('sha256');
        hash_update_stream($digest, $pipes[1]);
        fclose($pipes[1]);
        require_equal(proc_close($process), 0, 'Upstream source archive failed.');
        require_equal(hash_final($digest), $lock['archive_sha256'], 'Upstream Engine archive differs.');
        foreach ($lock['snapshot']['upstream_files'] ?? $lock['files'] as $path => $expected) {
            if (str_starts_with($path, '/') || in_array('..', explode('/', $path), true)) {
                throw new RuntimeException('Unsafe upstream source path.');
            }
            require_equal(hash_file('sha256', $argv[2] . '/' . $path), $expected, 'Upstream source differs: ' . $path);
        }
        break;
    default:
        throw new InvalidArgumentException('Usage: verify-workflow.php json-equal|module|engine-ref|upstream [paths]');
}
echo "Workflow source and artifact identity verified.\n";
