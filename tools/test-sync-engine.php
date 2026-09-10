#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/engine-source.php';
require_once __DIR__ . '/release-source.php';

use function Kumwe\EngineSource\makeDirectory;
use function Kumwe\EngineSource\readFile;
use function Kumwe\EngineSource\readLock;
use function Kumwe\EngineSource\removeTree;
use function Kumwe\EngineSource\treeFiles;
use function Kumwe\EngineSource\verifyBundle;
use function Kumwe\EngineSource\writeFile;
use function Kumwe\ReleaseSource\process;
use function Kumwe\ReleaseSource\run;

/** Isolated admission, replacement and refusal cases for tools/sync-engine.php. */
function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$checks = 0;
$workspace = sys_get_temp_dir() . '/kumwe-engine-sync-' . bin2hex(random_bytes(12));
$engine = $workspace . '/engine';
$binding = $workspace . '/binding';
makeDirectory($engine);
makeDirectory($binding . '/tools');
makeDirectory($binding . '/resources/compatibility');
try {
    foreach (['sync-engine.php', 'engine-source.php', 'release-source.php'] as $name) {
        writeFile($binding . '/tools/' . $name, readFile(__DIR__ . '/' . $name));
    }
    writeFile($binding . '/php_kumwe_engine.h', "#ifndef PHP_KUMWE_ENGINE_H\n#define PHP_KUMWE_ENGINE_H\n"
        . "extern zend_module_entry kumwe_engine_module_entry;\n#define PHP_KUMWE_ENGINE_VERSION \"0.0.0\"\n#endif\n");
    $compatibility = "{\n  \"schema\": \"kumwe-zend-compatibility/v1\",\n  \"version\": \"0.0.0\",\n"
        . "  \"statuses\": {\n    \"0\": \"result\",\n    \"1\": \"BindingFailure:1\"\n  }\n}\n";
    writeFile($binding . '/resources/compatibility/v1.json', $compatibility);
    $zeroDigest = str_repeat('0', 64);
    $apiDigest = str_repeat('1', 64);
    writeFile($binding . '/MIGRATION-HANDOFF.md', "---\nschema: kumwe-migration-handoff/v2\nsource:\n  semantic_inputs:\n"
        . "  - owner: kumwe/engine\n    version_or_commit: 0.0.0 at " . str_repeat('0', 40) . "\n"
        . "    manifest_or_corpus: resources/engine-lock.json; exact published release archive and complete\n"
        . "      per-file closure\n    sha256: $zeroDigest\nownership:\n  public_manifests:\n"
        . "  - path: resources/api/v1.json\n    sha256: $apiDigest\n  - path: resources/engine-lock.json\n    sha256: $zeroDigest\n"
        . "  - path: resources/compatibility/v1.json\n    sha256: $zeroDigest\n"
        . "php_extension:\n  embedded_engine:\n    version: 0.0.0\n    source_commit: " . str_repeat('0', 40) . "\n"
        . "    source_archive_sha256: $zeroDigest\n    abi_major: 1\n---\n");
    $handoffPath = $binding . '/MIGRATION-HANDOFF.md';
    $handoffRecords = static function (array $lock) use ($handoffPath, $binding): void {
        $handoff = readFile($handoffPath);
        $coordinate = $lock['version'] . ($lock['release'] === null ? '' : ' (' . $lock['release'] . ')') . ' at ' . $lock['commit'];
        check(str_contains($handoff, "    version_or_commit: $coordinate\n"), 'Handoff semantic coordinate is stale.');
        check(str_contains($handoff, "      per-file closure\n    sha256: {$lock['archive_sha256']}\n"), 'Handoff semantic digest is stale.');
        check(str_contains($handoff, "  - path: resources/engine-lock.json\n    sha256: " . hash_file('sha256', $binding . '/resources/engine-lock.json') . "\n"),
            'Handoff lock digest is stale.');
        check(str_contains($handoff, "  - path: resources/compatibility/v1.json\n    sha256: " . hash_file('sha256', $binding . '/resources/compatibility/v1.json') . "\n"),
            'Handoff compatibility digest is stale.');
        check(str_contains($handoff, "  embedded_engine:\n    version: {$lock['version']}\n    source_commit: {$lock['commit']}\n"
            . "    source_archive_sha256: {$lock['archive_sha256']}\n"), 'Handoff embedded Engine block is stale.');
        check(str_contains($handoff, "  - path: resources/api/v1.json\n    sha256: " . str_repeat('1', 64) . "\n"), 'Unrelated handoff digest changed.');
    };

    $git = static fn(string ...$arguments): string => trim(run($engine, 'git', ...$arguments));
    $git('init', '-q');
    $git('config', 'user.name', 'Source fixture');
    $git('config', 'user.email', 'source-fixture@example.invalid');
    $git('config', 'commit.gpgsign', 'false');
    $engineSource = static function (string $version, ?string $runtimeVersion = null) use ($engine): void {
        foreach (['include/kumwe/engine', 'resources', 'src', 'tools'] as $directory) { makeDirectory($engine . '/' . $directory); }
        writeFile($engine . '/CMakeLists.txt', "cmake_minimum_required(VERSION 3.25)\nproject(Fixture VERSION $version)\n");
        writeFile($engine . '/LICENSE', "Apache-2.0\n");
        writeFile($engine . '/include/kumwe/engine/engine.h', "#pragma once\n");
        writeFile($engine . '/resources/capabilities.json', json_encode(['engine' => 'kumwe/engine', 'version' => $version,
            'computation' => ['engine_version' => $runtimeVersion ?? $version]], JSON_PRETTY_PRINT) . "\n");
        writeFile($engine . '/resources/abi-manifest.json', "{\"abi_major\": 1, \"abi_minor\": 0}\n");
        writeFile($engine . '/resources/contracts.json', "{\"modules\": []}\n");
        writeFile($engine . '/src/engine.cpp', "// native source $version\n");
        writeFile($engine . '/tools/check.sh', "#!/bin/sh\necho ok\n");
        chmod($engine . '/tools/check.sh', 0755);
    };
    $commit = static function (string $message) use ($git): string {
        $git('add', '--all');
        $git('commit', '-qm', $message);
        return $git('rev-parse', 'HEAD');
    };
    $archive = static function (string $sha) use ($engine, $workspace): string {
        $tar = run($engine, 'git', 'archive', '--format=tar', '--prefix=kumwe-engine/', $sha);
        $gzip = process($engine, ['gzip', '-n'], $tar);
        check($gzip['returncode'] === 0, 'Fixture compression failed.');
        $path = $workspace . '/' . $sha . '.tar.gz';
        writeFile($path, $gzip['stdout']);
        return $path;
    };
    $sync = static function (array $arguments, bool $succeeds = true, ?string $expectedMessage = null)
        use ($binding, &$checks): array {
        $result = process($binding, [PHP_BINARY, $binding . '/tools/sync-engine.php', ...$arguments]);
        check(($result['returncode'] === 0) === $succeeds,
            'Unexpected sync result: ' . $result['stdout'] . $result['stderr']);
        if ($expectedMessage !== null) {
            check(str_contains($result['stderr'], $expectedMessage), 'Unexpected refusal: ' . $result['stderr']);
        }
        ++$checks;
        return $result;
    };
    $lockPath = $binding . '/resources/engine-lock.json';
    $headerPath = $binding . '/php_kumwe_engine_build.h';
    $versionPath = $binding . '/php_kumwe_engine.h';
    $compatibilityPath = $binding . '/resources/compatibility/v1.json';
    $bundle = $binding . '/vendor/engine';

    // Initial embedding of unreleased source hard-links every declared version to the Engine.
    $engineSource('1.2.3');
    $first = $commit('first source');
    $firstArchive = $archive($first);
    $firstDigest = hash_file('sha256', $firstArchive);
    $sync([$firstArchive, '--commit', $first, '--expected-sha256', $firstDigest]);
    $lock = readLock($binding);
    check($lock['version'] === '1.2.3' && $lock['release'] === null && $lock['commit'] === $first
        && $lock['archive_sha256'] === $firstDigest && $lock['archive_name'] === 'kumwe-engine-source.tar.gz', 'Initial lock identity is wrong.');
    check($lock['files'] === treeFiles($bundle), 'Lock digests differ from the installed tree.');
    check(str_contains(readFile($versionPath), '#define PHP_KUMWE_ENGINE_VERSION "1.2.3"'), 'Extension version was not hard-linked.');
    $compatibilityText = readFile($compatibilityPath);
    check(str_contains($compatibilityText, "\"version\": \"1.2.3\"") && str_contains($compatibilityText, "\"statuses\": {\n    \"0\": \"result\""),
        'Compatibility version was not updated in place.');
    check(is_executable($bundle . '/tools/check.sh'), 'Executable permission was not preserved.');
    check(str_contains(readFile($headerPath), '#define KUMWE_EMBEDDED_ENGINE_RELEASE ""'), 'Unreleased source must record an empty release.');
    verifyBundle($binding, $lock);
    $handoffRecords($lock);
    ++$checks;

    // Re-embedding the identical archive is idempotent.
    $lockBytes = readFile($lockPath);
    $sync([$firstArchive, '--commit', $first]);
    check(readFile($lockPath) === $lockBytes, 'Idempotent sync changed the lock.');

    // A published release records its tag; the tag must match the archived version.
    $sync([$firstArchive, '--commit', $first, '--release', 'v9.9.9'], false, 'does not match the archived Engine version');
    check(readFile($lockPath) === $lockBytes, 'A refused release changed the lock.');
    $sync([$firstArchive, '--commit', $first, '--release', 'v1.2.3']);
    $lock = readLock($binding);
    check($lock['release'] === 'v1.2.3' && str_contains(readFile($headerPath), '#define KUMWE_EMBEDDED_ENGINE_RELEASE "v1.2.3"'),
        'Release identity was not recorded.');
    verifyBundle($binding, $lock);
    $releasedLock = readFile($lockPath);

    // Refusals leave the reviewed identity untouched.
    $sync([$firstArchive, '--commit', $first, '--expected-sha256', str_repeat('0', 64)], false, 'differs from the expected');
    $sync([$firstArchive], false, 'Usage');
    $sync([$firstArchive, '--commit', 'not-a-commit'], false, 'Usage');
    $sync([$firstArchive, '--commit', $first, '--release', '1.2.3'], false, 'exact vMAJOR.MINOR.PATCH');
    $sync([$workspace . '/missing.tar.gz', '--commit', $first], false, 'regular file');
    writeFile($engine . '/tools/obsolete.py', "print('retired')\n");
    $python = $commit('unsupported implementation language');
    $sync([$archive($python), '--commit', $python], false, 'unsupported implementation language');
    unlink($engine . '/tools/obsolete.py');
    writeFile($engine . '/tools/check.sh', "#!/bin/sh\npython3 generator.py\n");
    $interpreter = $commit('unsupported interpreter invocation');
    $sync([$archive($interpreter), '--commit', $interpreter], false, 'unsupported interpreter');
    writeFile($engine . '/tools/check.sh', "#!/bin/sh\necho ok\n");
    writeFile($engine . '/docs.md', "Prose may mention Python, node and npm without being tooling.\n");
    unlink($engine . '/CMakeLists.txt');
    $incomplete = $commit('missing build recipe');
    $sync([$archive($incomplete), '--commit', $incomplete], false, 'lacks required source material');
    $engineSource('1.2.3', '9.9.9');
    $disagreeing = $commit('disagreeing runtime version');
    $sync([$archive($disagreeing), '--commit', $disagreeing], false, 'one exact MAJOR.MINOR.PATCH version');
    $engineSource('1.2.3');
    check(symlink('src/engine.cpp', $engine . '/link'), 'Cannot create symlink fixture.');
    $linked = $commit('symbolic link in source');
    $sync([$archive($linked), '--commit', $linked], false, 'Invalid Engine release archive');
    unlink($engine . '/link');
    check(readFile($lockPath) === $releasedLock, 'A refused archive changed the lock.');
    verifyBundle($binding, readLock($binding));

    // Local edits under vendor/engine are never silently discarded.
    writeFile($bundle . '/src/engine.cpp', "// unreviewed local edit\n");
    $sync([$firstArchive, '--commit', $first], false, 'differs from its lock');
    writeFile($bundle . '/src/engine.cpp', "// native source 1.2.3\n");
    writeFile($bundle . '/stray.txt', "untracked work\n");
    $sync([$firstArchive, '--commit', $first], false, 'differs from its lock');
    unlink($bundle . '/stray.txt');
    check(readFile($lockPath) === $releasedLock, 'A refused replacement changed the lock.');

    // A new Engine version replaces the whole tree and moves every declared version with it.
    $engineSource('1.3.0');
    writeFile($engine . '/src/added.cpp', "// new file\n");
    unlink($engine . '/docs.md');
    $next = $commit('next release');
    $nextArchive = $archive($next);
    $sync([$nextArchive, '--commit', $next, '--release', 'v1.3.0', '--expected-sha256', hash_file('sha256', $nextArchive)]);
    $lock = readLock($binding);
    check($lock['version'] === '1.3.0' && $lock['release'] === 'v1.3.0' && $lock['commit'] === $next, 'Replacement identity is wrong.');
    check(!file_exists($bundle . '/docs.md') && is_file($bundle . '/src/added.cpp'), 'Replacement did not install exactly the new tree.');
    check(str_contains(readFile($versionPath), '"1.3.0"') && str_contains(readFile($compatibilityPath), "\"version\": \"1.3.0\""),
        'Declared versions did not follow the Engine.');
    verifyBundle($binding, $lock);
    $handoffRecords($lock);
    $handoffBytes = readFile($handoffPath);
    writeFile($handoffPath, str_replace("  embedded_engine:\n    version: 1.3.0\n", "  embedded_engine:\n    edition: 1.3.0\n", $handoffBytes));
    $sync([$nextArchive, '--commit', $next, '--release', 'v1.3.0'], false, 'MIGRATION-HANDOFF.md embedded Engine version');
    check(readLock($binding)['version'] === '1.3.0' && verifyBundle($binding, readLock($binding)) === null, 'A refused handoff update changed the embedding.');
    writeFile($handoffPath, $handoffBytes);

    // A missing generated manifest declaration refuses the sync before any identity is changed.
    writeFile($handoffPath, str_replace('  - path: resources/compatibility/v1.json', '  - path: resources/compatibility/missing.json', $handoffBytes));
    $sync([$nextArchive, '--commit', $next, '--release', 'v1.3.0'], false, 'MIGRATION-HANDOFF.md compatibility manifest digest');
    check(readLock($binding)['version'] === '1.3.0' && verifyBundle($binding, readLock($binding)) === null, 'A refused compatibility handoff update changed the embedding.');
    writeFile($handoffPath, $handoffBytes);

    // The hard link is enforced: a drifted extension version fails verification.
    writeFile($versionPath, str_replace('"1.3.0"', '"1.3.1"', readFile($versionPath)));
    try {
        verifyBundle($binding, $lock);
        throw new LogicException('Drifted extension version was accepted.');
    } catch (RuntimeException $expected) {
        check(str_contains($expected->getMessage(), 'hard-linked'), 'Unexpected drift refusal: ' . $expected->getMessage());
    }
    ++$checks;
    echo $checks . " isolated Engine sync admission, replacement and refusal cases passed.\n";
} finally {
    removeTree($workspace);
}
