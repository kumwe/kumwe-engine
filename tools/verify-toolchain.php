<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$pipes = [];
$process = proc_open(['git', '-C', $root, 'ls-files', '-z'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes);
if (!is_resource($process)) { throw new RuntimeException('Cannot enumerate committed source.'); }
fclose($pipes[0]);
$paths = explode("\0", rtrim(stream_get_contents($pipes[1]), "\0"));
fclose($pipes[1]);
if (proc_close($process) !== 0) { throw new RuntimeException('Source enumeration failed.'); }
$checked = 0;
foreach ($paths as $path) {
    if (preg_match('/\.(py[co]?|m?js|cjs|ts)$/iD', $path)) {
        throw new RuntimeException('Unsupported implementation language: ' . $path);
    }
    if (str_contains('/' . $path, '/tools/') && !preg_match('/\.(php|c|cpp|h|hpp|sh)$/D', $path)) {
        throw new RuntimeException('Tool source must use the declared PHP/native toolchain: ' . $path);
    }
    if (preg_match('/\.(sh|yml|yaml)$/D', $path)
        && preg_match('/\b(?:python[0-9.]*|pip[0-9.]*|node|npm|npx)\b/i', file_get_contents($root . '/' . $path))) {
        throw new RuntimeException('Unsupported interpreter invocation: ' . $path);
    }
    if (str_ends_with($path, '.php')) {
        $lint = proc_open([PHP_BINARY, '-l', $root . '/' . $path],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => STDERR], $unused);
        if (!is_resource($lint) || proc_close($lint) !== 0) {
            throw new RuntimeException('PHP syntax check failed: ' . $path);
        }
        ++$checked;
    }
}
echo $checked . " PHP files linted; source and workflows use the declared toolchain.\n";
