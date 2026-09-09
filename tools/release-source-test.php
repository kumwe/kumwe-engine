#!/usr/bin/env php
<?php
declare(strict_types=1);

namespace Kumwe\ReleaseSource\Tests;

require_once __DIR__ . '/release-source.php';
require_once __DIR__ . '/engine-source.php';

use Kumwe\ReleaseSource\ReleaseError;
use function Kumwe\ReleaseSource\{archive_contents, archive_files, decode_object, digest, process, read_bytes, run,
    safe_path, source_facts, tar_entries, write_bytes, remove_directory};
use function Kumwe\EngineSource\makeDirectory;
use function Kumwe\EngineSource\removeTree;

/** Deterministic packaging, parsing and refusal tests for tools/release-source.php. */
function check(bool $condition, string $message = 'Assertion failed.'): void
{
    if (!$condition) { throw new \RuntimeException($message); }
}

function refuses(callable $operation, ?string $message = null): void
{
    try { $operation(); }
    catch (ReleaseError $error) {
        if ($message !== null) { check(str_contains($error->getMessage(), $message), $error->getMessage()); }
        return;
    }
    throw new \RuntimeException('Invalid source/evidence unexpectedly accepted.');
}

function tar_member(string $name, string $data = 'x', string $type = '0', string $link = ''): string
{
    check(strlen($name) <= 100, 'Fixture name exceeds header field.');
    $header = str_pad($name, 100, "\0") . sprintf('%07o', 0644) . "\0"
        . sprintf('%07o', 0) . "\0" . sprintf('%07o', 0) . "\0"
        . sprintf('%011o', strlen($data)) . "\0" . sprintf('%011o', 0) . "\0"
        . str_repeat(' ', 8) . $type . str_pad($link, 100, "\0") . "ustar\0" . '00'
        . str_repeat("\0", 32 + 32 + 8 + 8 + 155 + 12);
    check(strlen($header) === 512);
    $checksum = sprintf('%06o', array_sum(unpack('C*', $header))) . "\0 ";
    $header = substr_replace($header, $checksum, 148, 8);
    return $header . $data . str_repeat("\0", (512 - strlen($data) % 512) % 512);
}

function tar_gzip(string $members): string
{
    $encoded = gzencode($members . str_repeat("\0", 1024), 6, ZLIB_ENCODING_GZIP);
    check(is_string($encoded), 'Fixture compression failed.');
    return $encoded;
}

function pax_record(string $key, string $value): string
{
    $body = $key . '=' . $value . "\n";
    $length = strlen($body) + 2;
    while (strlen((string) $length) + 1 + strlen($body) !== $length) {
        $length = strlen((string) $length) + 1 + strlen($body);
    }
    return $length . ' ' . $body;
}

$checks = 0;
$prefix = 'kumwe-engine-php/';

// Path and archive admission.
foreach (['', '/abs', 'a/../b', './a', 'a//b', "a\0b", 'a\\b'] as $unsafe) { check(!safe_path($unsafe), 'Unsafe path accepted: ' . $unsafe); }
check(safe_path('src/kumwe_engine.c'));
$valid = tar_gzip(tar_member($prefix, '', '5') . tar_member($prefix . 'composer.json', '{}') . tar_member($prefix . 'src/', '', '5') . tar_member($prefix . 'src/a.c', 'int a;'));
$files = archive_files($valid, $prefix);
check(array_keys($files) === ['composer.json', 'src/a.c'] && $files['src/a.c']['data'] === 'int a;' && $files['src/a.c']['mode'] === 0644, 'Archive contents misread.');
check(archive_contents($files) === ['composer.json' => '{}', 'src/a.c' => 'int a;']);
refuses(static fn() => archive_files($valid, 'kumwe-engine/'), 'declared package root');
refuses(static fn() => archive_files('not gzip', $prefix), 'Invalid compressed');
refuses(static fn() => archive_files(tar_gzip(tar_member($prefix . 'a', '1') . tar_member($prefix . 'a', '2')), $prefix), 'Duplicate archive path');
refuses(static fn() => archive_files(tar_gzip(tar_member($prefix . '../escape', 'x')), $prefix), 'Unsafe archive path');
refuses(static fn() => archive_files(tar_gzip(tar_member($prefix . 'link', '', '2', 'target')), $prefix), 'regular files and directories');
refuses(static fn() => archive_files(tar_gzip(tar_member($prefix . '.git/config', 'x')), $prefix), 'not source distribution material');
refuses(static fn() => archive_files(tar_gzip(tar_member($prefix . '.github/workflows/ci.yml', 'x')), $prefix), 'not source distribution material');
refuses(static fn() => archive_files(tar_gzip(tar_member($prefix . 'secret.pem', 'x')), $prefix), 'Credential-like');
refuses(static fn() => archive_files(tar_gzip(tar_member($prefix . 'dir/', 'payload', '5')), $prefix), 'directory has a payload');
refuses(static fn() => archive_files(tar_gzip(tar_member($prefix, '', '5')), $prefix), 'empty archive');
refuses(static fn() => archive_files(gzencode(tar_member($prefix . 'a', 'x') . 'trailing garbage'), $prefix), 'Incomplete TAR');
refuses(static fn() => archive_files(gzencode(tar_member($prefix . 'a', 'x') . str_repeat("\0", 1024) . 'trailing garbage'), $prefix), 'Invalid TAR end marker');
$corrupt = tar_member($prefix . 'a', 'x');
$corrupt[150] = $corrupt[150] === '0' ? '1' : '0';
refuses(static fn() => archive_files(tar_gzip($corrupt), $prefix), 'checksum');
$longPath = $prefix . str_repeat('directory/', 12) . 'file.c';
$pax = tar_member('./PaxHeaders/x', pax_record('path', $longPath), 'x') . tar_member($prefix . 'short', 'long', '0');
check(array_keys(archive_files(tar_gzip($pax), $prefix)) === [substr($longPath, strlen($prefix))], 'PAX long path was not honoured.');
refuses(static fn() => tar_entries(tar_member($prefix . 'a', 'x')), 'Incomplete TAR');
refuses(static fn() => tar_entries(tar_member('a', '', 'K', 'link') . str_repeat("\0", 1024)), 'links are refused');
$checks += 19;

// A complete fixture checkout: embed a fixture Engine, then prepare and verify the bundle.
$workspace = sys_get_temp_dir() . '/kumwe-binding-release-' . bin2hex(random_bytes(12));
$engine = $workspace . '/engine';
$repo = $workspace . '/binding';
$evidence = $workspace . '/evidence';
makeDirectory($engine);
makeDirectory($repo . '/tools');
makeDirectory($repo . '/resources/compatibility');
try {
    $root = dirname(__DIR__);
    foreach (['release-source.php', 'engine-source.php', 'sync-engine.php'] as $name) {
        write_bytes($repo . '/tools/' . $name, read_bytes(__DIR__ . '/' . $name));
    }
    write_bytes($repo . '/php_kumwe_engine.h', "#ifndef PHP_KUMWE_ENGINE_H\n#define PHP_KUMWE_ENGINE_H\n#define PHP_KUMWE_ENGINE_VERSION \"0.0.0\"\n#endif\n");
    write_bytes($repo . '/resources/compatibility/v1.json', "{\n  \"schema\": \"kumwe-zend-compatibility/v1\",\n  \"version\": \"0.0.0\"\n}\n");
    makeDirectory($repo . '/resources/api');
    write_bytes($repo . '/resources/api/v1.json', "{\"schema\": \"kumwe-zend-api/v1\"}\n");
    write_bytes($repo . '/composer.json', read_bytes($root . '/composer.json'));
    write_bytes($repo . '/config.m4', "PHP_ARG_ENABLE([kumwe_engine])\n");
    write_bytes($repo . '/LICENSE', "Apache-2.0\n");
    write_bytes($repo . '/.gitattributes', "/.gitattributes export-ignore\n/tests/benchmark export-ignore\n");
    makeDirectory($repo . '/tests/benchmark');
    write_bytes($repo . '/tests/benchmark/composer.json', "{}\n");

    $git = static fn(string $directory, string ...$arguments): string => trim(run($directory, 'git', ...$arguments));
    foreach ([$engine, $repo] as $directory) {
        $git($directory, 'init', '-q');
        $git($directory, 'config', 'user.name', 'Source fixture');
        $git($directory, 'config', 'user.email', 'source-fixture@example.invalid');
        $git($directory, 'config', 'commit.gpgsign', 'false');
    }
    foreach (['include/kumwe/engine', 'resources', 'src'] as $directory) { makeDirectory($engine . '/' . $directory); }
    write_bytes($engine . '/CMakeLists.txt', "project(Fixture VERSION 4.5.6)\n");
    write_bytes($engine . '/LICENSE', "Apache-2.0\n");
    write_bytes($engine . '/include/kumwe/engine/engine.h', "#pragma once\n");
    write_bytes($engine . '/resources/capabilities.json', "{\"version\": \"4.5.6\", \"capabilities\": [\"fixture/1\"], \"computation\": {\"engine_version\": \"4.5.6\"}}\n");
    write_bytes($engine . '/resources/abi-manifest.json', "{\"abi_major\": 1, \"abi_minor\": 0, \"status\": \"frozen\"}\n");
    write_bytes($engine . '/resources/contracts.json', "{\"modules\": []}\n");
    write_bytes($engine . '/src/engine.cpp', "// fixture\n");
    $git($engine, 'add', '--all');
    $git($engine, 'commit', '-qm', 'fixture engine');
    $engineCommit = $git($engine, 'rev-parse', 'HEAD');
    $tar = run($engine, 'git', 'archive', '--format=tar', '--prefix=kumwe-engine/', 'HEAD');
    $gzip = process($engine, ['gzip', '-n'], $tar);
    check($gzip['returncode'] === 0, 'Fixture compression failed.');
    write_bytes($workspace . '/engine.tar.gz', $gzip['stdout']);
    $sync = process($repo, [PHP_BINARY, $repo . '/tools/sync-engine.php', $workspace . '/engine.tar.gz', '--commit', $engineCommit, '--release', 'v4.5.6']);
    check($sync['returncode'] === 0, 'Fixture embedding failed: ' . $sync['stderr']);
    $git($repo, 'add', '--all');
    $git($repo, 'commit', '-qm', 'fixture binding');
    $commit = $git($repo, 'rev-parse', 'HEAD');
    $tool = static fn(string ...$arguments): array => process($repo, [PHP_BINARY, $repo . '/tools/release-source.php', ...$arguments]);

    $prepared = $tool('prepare', $evidence);
    check($prepared['returncode'] === 0, 'Preparation failed: ' . $prepared['stderr']);
    $names = array_values(array_diff(scandir($evidence), ['.', '..']));
    sort($names, SORT_STRING);
    check($names === ['SHA256SUMS', 'kumwe-engine-php-source.tar.gz', 'source.json', 'source.spdx.json'], 'Bundle files differ.');
    $record = decode_object(read_bytes($evidence . '/source.json'));
    check($record['version'] === '4.5.6' && $record['tag'] === 'v4.5.6' && $record['source']['commit'] === $commit
        && $record['engine']['release'] === 'v4.5.6' && $record['engine']['commit'] === $engineCommit
        && $record['php']['thread_safety'] === ['nts' => true, 'zts' => true] && $record['capabilities'] === ['fixture/1']
        && $record['archive']['sha256'] === digest(read_bytes($evidence . '/kumwe-engine-php-source.tar.gz')), 'Source record is wrong.');
    $sums = read_bytes($evidence . '/SHA256SUMS');
    foreach (['kumwe-engine-php-source.tar.gz', 'source.spdx.json', 'source.json'] as $name) {
        check(str_contains($sums, digest(read_bytes($evidence . '/' . $name)) . '  ' . $name . "\n"), 'Checksum missing: ' . $name);
    }
    $exported = archive_contents(archive_files(read_bytes($evidence . '/kumwe-engine-php-source.tar.gz'), $prefix));
    check(!isset($exported['.gitattributes']) && !isset($exported['tests/benchmark/composer.json']) && isset($exported['vendor/engine/src/engine.cpp']),
        'Export-ignore policy or embedded tree not honoured.');
    $sbom = decode_object(read_bytes($evidence . '/source.spdx.json'));
    check(count($sbom['files']) === count($exported) && $sbom['packages'][1]['versionInfo'] === '4.5.6+' . $engineCommit, 'SBOM inventory is wrong.');
    check($tool('verify', $evidence, '--expected-commit', $commit)['returncode'] === 0, 'Verification of a fresh bundle failed.');
    check($tool('verify', $evidence, '--expected-commit', $commit, '--expected-sha256', $record['archive']['sha256'])['returncode'] === 0);
    check($tool('verify', $evidence, '--expected-commit', $commit, '--expected-sha256', str_repeat('0', 64))['returncode'] !== 0, 'Wrong digest accepted.');
    check($tool('verify', $evidence, '--expected-commit', str_repeat('0', 40))['returncode'] !== 0, 'Wrong commit accepted.');
    check($tool('verify', $evidence)['returncode'] !== 0, 'Verification without an expected commit was accepted.');
    check($tool('prepare', $evidence)['returncode'] !== 0, 'Existing evidence was overwritten.');
    check($tool('prepare', $repo . '/inside')['returncode'] !== 0, 'Evidence inside the source tree was accepted.');
    check($tool('prepare', $evidence, '--require-stable')['returncode'] !== 0, 'Unknown option accepted.');
    $archivePath = $evidence . '/kumwe-engine-php-source.tar.gz';
    $original = read_bytes($archivePath);
    $tampered = $original;
    $tampered[strlen($tampered) - 5] = chr(ord($tampered[strlen($tampered) - 5]) ^ 1);
    write_bytes($archivePath, $tampered);
    check($tool('verify', $evidence, '--expected-commit', $commit)['returncode'] !== 0, 'Tampered archive accepted.');
    write_bytes($archivePath, $original);
    unlink($evidence . '/source.json');
    check($tool('verify', $evidence, '--expected-commit', $commit)['returncode'] !== 0, 'Incomplete bundle accepted.');
    remove_directory($evidence);
    write_bytes($repo . '/LICENSE', "changed\n");
    check($tool('prepare', $evidence)['returncode'] !== 0, 'Dirty tracked source accepted.');
    $git($repo, 'checkout', '--', 'LICENSE');
    $checks += 12;

    // The hard link is enforced inside the exported facts.
    $facts = static function (array $edit) use ($evidence, $tool, $repo, $prefix): array {
        $prepared = $tool('prepare', $evidence);
        check($prepared['returncode'] === 0, 'Preparation failed: ' . $prepared['stderr']);
        $files = archive_contents(archive_files(read_bytes($evidence . '/kumwe-engine-php-source.tar.gz'), $prefix));
        remove_directory($evidence);
        return source_facts(array_replace($files, $edit));
    };
    check($facts([])['version'] === '4.5.6');
    refuses(static fn() => $facts(['php_kumwe_engine.h' => "#define PHP_KUMWE_ENGINE_VERSION \"4.5.7\"\n"]), 'hard-linked');
    refuses(static fn() => $facts(['vendor/engine/src/engine.cpp' => "// drifted\n"]), 'differs from the exact lock');
    refuses(static fn() => $facts(['composer.json' => "{\"name\": \"other/package\", \"type\": \"php-ext\"}\n"]), 'PIE package');
    $checks += 4;
    echo $checks . " source packaging, archive parsing and refusal cases passed.\n";
} finally {
    removeTree($workspace);
}
