<?php

declare(strict_types=1);

// Build tooling only. Do not load the extension while recording its independent expected identity.
if (count($argv) !== 11) {
    throw new RuntimeException('Supply source/build roots, php-config, CC, CFLAGS, CPPFLAGS, LDFLAGS, CXX, host and LD.');
}
[, $source, $build, $phpConfig, $cc, $cflags, $cppflags, $ldflags, $cxx, $host, $ld] = $argv;
$run = static function (array $command): string {
    if (($command[0] ?? '') === '') { throw new RuntimeException('Configured build command is empty.'); }
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Build identity command could not start.'); }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0 || !is_string($output) || strlen($output) > 32768) {
        throw new RuntimeException('Build identity command failed: ' . $command[0]);
    }
    return trim($output);
};
$phpVersion = $run([$phpConfig, '--version']);
// --phpapi is a distro extension, absent from the pinned upstream PHP build.
$includeRoot = $run([$phpConfig, '--include-dir']);
$zendHeader = file_get_contents($includeRoot . '/Zend/zend_modules.h');
if (!is_string($zendHeader) || preg_match('/^#define[ \t]+ZEND_MODULE_API_NO[ \t]+([0-9]+)[ \t]*$/m', $zendHeader, $apiMatch) !== 1) {
    throw new RuntimeException('The selected PHP development headers have no exact Zend module API.');
}
$zendApi = $apiMatch[1];
if ($phpVersion !== PHP_VERSION || preg_match('/^[0-9]+$/D', $zendApi) !== 1 || PHP_OS_FAMILY !== 'Linux'
    || php_uname('m') !== 'x86_64' || PHP_INT_SIZE !== 8) {
    throw new RuntimeException('Unverified PHP or target build tuple.');
}
$cache = file_get_contents($build . '/engine-build/CMakeCache.txt');
if (!is_string($cache)) { throw new RuntimeException('Missing configured Engine build.'); }
$cacheValue = static function (string $key) use ($cache): string {
    if (!preg_match('/^' . preg_quote($key, '/') . ':[^=\r\n]+=([^\r\n]*)$/m', $cache, $match)) {
        throw new RuntimeException('Missing configured Engine option: ' . $key);
    }
    return $match[1];
};
$flags = static function (string $target) use ($build): array {
    $bytes = file_get_contents($build . '/engine-build/CMakeFiles/' . $target . '.dir/flags.make');
    if (!is_string($bytes)) { throw new RuntimeException('Missing actual Engine compile flags.'); }
    preg_match_all('/^(C|CXX)_(DEFINES|FLAGS) = ([^\r\n]*)$/m', $bytes, $matches, PREG_SET_ORDER);
    $result = [];
    foreach ($matches as $match) { $result[$match[1] . '_' . $match[2]] = $match[3]; }
    if ($result === []) { throw new RuntimeException('Engine flags were not captured.'); }
    return $result;
};
$engineFlags = ['build_type' => $cacheValue('CMAKE_BUILD_TYPE'),
    'engine' => $flags('kumwe_engine'), 'pcre2' => $flags('kumwe_pcre2')];
$firstLine = static fn (string $value): string => explode("\n", $value)[0];
$versionOf = static fn (string $command): string => $firstLine($run([...preg_split('/\s+/', trim($command)), '--version']));
$libc = $run(['getconf', 'GNU_LIBC_VERSION']);
if (!preg_match('/^glibc [0-9]+\.[0-9]+$/D', $libc)) {
    throw new RuntimeException('This source distribution requires a verified glibc build tuple.');
}
$configure = $run([$phpConfig, '--configure-options']);
$extensionFlags = ['cflags' => $cflags, 'cppflags' => $cppflags, 'ldflags' => $ldflags,
    'extra_cflags' => '-std=c11 -Wall -Wextra -fstack-protector-strong'];
$allFlags = json_encode([$extensionFlags, $engineFlags, $configure], JSON_THROW_ON_ERROR);
$sanitizers = [];
preg_match_all('/-fsanitize=([a-zA-Z0-9_,-]+)/', $allFlags, $matches);
foreach ($matches[1] as $list) { foreach (explode(',', $list) as $item) { $sanitizers[$item] = true; } }
if (str_contains($configure, '--enable-address-sanitizer')) { $sanitizers['address'] = true; }
if (str_contains($configure, '--enable-undefined-sanitizer')) { $sanitizers['undefined'] = true; }
$sanitizers = array_keys($sanitizers); sort($sanitizers, SORT_STRING);
$abiPath = $source . '/vendor/engine/resources/abi-manifest.json';
$abi = json_decode(file_get_contents($abiPath), true, 64, JSON_THROW_ON_ERROR);
if (($abi['abi_major'] ?? null) !== 1 || ($abi['abi_minor'] ?? null) !== 0) {
    throw new RuntimeException('Unknown Engine ABI manifest.');
}
$files = [];
foreach (['config.m4', 'php_kumwe_engine.h', 'php_kumwe_engine_build.h', 'resources/api/v1.json',
    'src/kumwe_engine.c', 'src/kumwe_engine_arginfo.h', 'stubs/kumwe_engine.stub.php', 'tools/record-build.php'] as $file) {
    $digest = hash_file('sha256', $source . '/' . $file);
    if (!is_string($digest)) { throw new RuntimeException('Missing binding source input.'); }
    $files[$file] = $digest;
}
$record = ['schema' => 'kumwe-zend-build/v1', 'php_version' => PHP_VERSION, 'php_version_id' => PHP_VERSION_ID,
    'zend_module_api' => (int) $zendApi, 'thread_model' => PHP_ZTS ? 'ZTS' : 'NTS', 'os' => PHP_OS_FAMILY,
    'os_release' => php_uname('r'), 'architecture' => php_uname('m'), 'host' => $host, 'libc' => $libc,
    'compiler' => ['c' => ['command' => $cc, 'version' => $versionOf($cc)],
        'cxx' => ['command' => $cacheValue('CMAKE_CXX_COMPILER'),
            'version' => $firstLine($run([$cacheValue('CMAKE_CXX_COMPILER'), '--version']))]],
    'linker' => ['command' => $ld, 'version' => $versionOf($ld)],
    'extension_flags' => $extensionFlags, 'engine_flags' => $engineFlags,
    'php_configure_options' => $configure, 'debug' => (bool) PHP_DEBUG, 'sanitizers' => $sanitizers,
    'distribution' => 'self-contained-source', 'abi_major' => $abi['abi_major'], 'abi_minor' => $abi['abi_minor'],
    'abi_manifest_sha256' => hash_file('sha256', $abiPath),
    'extension_source_sha256' => hash('sha256', json_encode($files, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))];
$bytes = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
file_put_contents($build . '/build-identity.json', $bytes);
$header = "#ifndef KUMWE_ENGINE_BUILD_CONFIG_H\n#define KUMWE_ENGINE_BUILD_CONFIG_H\n"
    . '#define KUMWE_BINDING_PHP_VERSION ' . json_encode(PHP_VERSION, JSON_THROW_ON_ERROR) . "\n"
    . '#define KUMWE_BINDING_BUILD_JSON ' . json_encode($bytes, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
    . '#define KUMWE_BINDING_BUILD_SHA256 "' . hash('sha256', $bytes) . "\"\n"
    . '#define KUMWE_BINDING_DECIMAL_CORPUS "' . hash_file('sha256', $source . '/vendor/engine/corpus/decimal/decimal-v1.tsv')
    . "\"\n#endif\n";
file_put_contents($build . '/kumwe_engine_build_config.h', $header);
echo 'Recorded exact independent binding build tuple ' . hash('sha256', $bytes) . ".\n";
