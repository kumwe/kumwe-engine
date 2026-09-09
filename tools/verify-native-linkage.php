#!/usr/bin/env php
<?php
declare(strict_types=1);
namespace Kumwe\Tools\NativeLinkage;
require_once __DIR__ . '/diagnostic-runtime.php';

use Kumwe\Tools\DiagnosticRuntime\Invalid;
use function Kumwe\Tools\DiagnosticRuntime\run;

try {
    $module = $argv[1] ?? __DIR__ . '/../modules/kumwe_engine.so';
    if ($argc > 2 || !is_file($module)) {
        throw new Invalid('Usage: verify-native-linkage.php [BUILT_MODULE]');
    }
    $dynamic = run('readelf', '-d', $module);
    preg_match_all('/Shared library: \[([^]]+)\]/', $dynamic, $matches);
    $needed = array_unique($matches[1]);
    sort($needed, SORT_STRING);
    $allowed = ['libstdc++.so.6', 'libm.so.6', 'libgcc_s.so.1', 'libc.so.6', 'libpthread.so.0', 'librt.so.1'];
    $unexpected = array_diff($needed, $allowed);
    if (!$needed || $unexpected) {
        throw new Invalid('Unexpected extension shared-library dependencies: ' . implode(', ', $unexpected));
    }
    $exports = run('nm', '-D', '--defined-only', $module);
    if (preg_match('/\bpcre2_[A-Za-z0-9_]+/', $exports)) {
        throw new Invalid('Vendored PCRE2 internals must remain hidden inside the native module.');
    }
    echo 'Native module uses only documented system runtimes: ' . implode(', ', $needed) . "\n";
} catch (\Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
