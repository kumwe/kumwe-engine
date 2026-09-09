#!/usr/bin/env php
<?php

declare(strict_types=1);

namespace Kumwe\EngineSource;

use RuntimeException;
use Throwable;

require_once __DIR__ . '/engine-source.php';
require_once __DIR__ . '/release-source.php';

use function Kumwe\ReleaseSource\encode;
use function Kumwe\ReleaseSource\run;
use function Kumwe\ReleaseSource\tar_entries;

/** Validate every archive entry before any source tree mutation. */
function sourceEntries(string $archive): array
{
    $entries = [];
    $names = [];
    foreach (tar_entries($archive) as $member) {
        $directory = $member['type'] === '5';
        $path = $directory ? rtrim($member['name'], '/') : $member['name'];
        if (!safePath($path) || isset($names[$path])
            || !in_array($member['type'], ["\0", '0', '5'], true)
            || ($directory && $member['data'] !== '')) {
            throw new RuntimeException('Source archive must contain unique regular relative files and directories.');
        }
        $names[$path] = $directory;
        if (!$directory) {
            $entries[$path] = ['data' => $member['data'], 'mode' => $member['mode'] & 0777];
        }
    }
    if ($entries === []) {
        throw new RuntimeException('An empty source archive cannot replace the Engine bundle.');
    }
    foreach ($names as $path => $_directory) {
        for ($parent = dirname($path); $parent !== '.'; $parent = dirname($parent)) {
            if (array_key_exists($parent, $names) && !$names[$parent]) {
                throw new RuntimeException('Source archive file and directory paths overlap.');
            }
        }
    }
    ksort($entries, SORT_STRING);
    return $entries;
}

function entryHashes(array $entries): array
{
    $hashes = [];
    foreach ($entries as $path => $entry) {
        $hashes[$path] = hash('sha256', $entry['data']);
    }
    ksort($hashes, SORT_STRING);
    return $hashes;
}

/**
 * Carry reviewed packaging changes forward from the authenticated current tree.
 * A changed upstream input to an overlay requires a fresh review, never a blind patch.
 */
function replaySnapshot(array $entries, array $previous, string $target): array
{
    if (!isset($previous['snapshot'])) {
        return $entries;
    }
    $upstream = $previous['snapshot']['upstream_files'];
    foreach ($upstream as $path => $originalDigest) {
        if (!array_key_exists($path, $previous['files'])) {
            unset($entries[$path]);
            continue;
        }
        if ($previous['files'][$path] === $originalDigest) {
            continue;
        }
        if (!isset($entries[$path]) || hash('sha256', $entries[$path]['data']) !== $originalDigest) {
            throw new RuntimeException('Changed upstream overlay input requires review: ' . $path);
        }
        $entries[$path]['data'] = readFile($target . '/' . $path);
    }
    foreach ($previous['files'] as $path => $_digest) {
        if (array_key_exists($path, $upstream)) {
            continue;
        }
        if (isset($entries[$path])) {
            throw new RuntimeException('Upstream source collides with a reviewed snapshot addition: ' . $path);
        }
        $entries[$path] = [
            'data' => readFile($target . '/' . $path),
            'mode' => fileperms($target . '/' . $path) & 0777,
        ];
    }
    ksort($entries, SORT_STRING);
    return $entries;
}

function main(array $arguments): void
{
    if (!in_array(count($arguments), [3, 4], true)
        || !preg_match('/^[a-f0-9]{40}$/D', $arguments[2] ?? '')
        || (isset($arguments[3]) && !in_array($arguments[3], ['--same-source', '--replace-source'], true))) {
        throw new RuntimeException('Usage: php tools/embed-engine.php ENGINE_REPOSITORY FULL_COMMIT [--same-source|--replace-source]');
    }
    $root = dirname(__DIR__);
    $source = realpath($arguments[1]);
    if ($source === false || !is_dir($source)) {
        throw new RuntimeException('The Engine source repository does not exist.');
    }
    $commit = $arguments[2];
    $mode = $arguments[3] ?? null;
    if (trim(run($source, 'git', 'rev-parse', '--verify', $commit . '^{commit}')) !== $commit) {
        throw new RuntimeException('A complete committed source identity is required.');
    }
    $archive = run($source, 'git', 'archive', '--format=tar', $commit);
    $entries = sourceEntries($archive);
    $upstream = entryHashes($entries);
    $target = $root . '/vendor/engine';
    $lockPath = $root . '/resources/engine-lock.json';
    $headerPath = $root . '/php_kumwe_engine_build.h';
    foreach ([$root . '/vendor', $root . '/resources', $target, $lockPath, $headerPath] as $path) {
        if (is_link($path)) {
            throw new RuntimeException('Engine bundle and identity paths must not be symbolic links.');
        }
    }
    $previous = null;
    if (file_exists($target)) {
        if ($mode === null) {
            throw new RuntimeException('Existing bundle needs --same-source or verified --replace-source.');
        }
        $previous = json_decode(readFile($lockPath), true, 512, JSON_THROW_ON_ERROR);
        verifyBundle($root, $previous, false);
        $entries = replaySnapshot($entries, $previous, $target);
    } elseif ($mode !== null) {
        throw new RuntimeException('A replacement option requires an existing verified Engine bundle.');
    }
    foreach ($entries as $path => $entry) {
        nativeFile($path, $entry['data']);
    }
    $expected = entryHashes($entries);
    if ($mode === '--same-source' && $expected !== $previous['files']) {
        throw new RuntimeException('Provenance refresh requires an identical existing source tree.');
    }
    $manifest = [
        'schema' => 'kumwe-embedded-engine/v1',
        'state' => 'candidate',
        'repository' => 'https://github.com/kumwe/engine',
        'commit' => $commit,
        'release' => null,
        'archive_sha256' => hash('sha256', $archive),
        'files' => $expected,
        'release_verified' => false,
    ];
    if (isset($previous['snapshot'])) {
        $manifest['snapshot'] = ['profile' => 'php-native-tooling/v1', 'upstream_files' => $upstream];
    }
    $header = "#ifndef PHP_KUMWE_ENGINE_BUILD_H\n#define PHP_KUMWE_ENGINE_BUILD_H\n"
        . '#define KUMWE_EMBEDDED_ENGINE_COMMIT "' . $commit . "\"\n"
        . '#define KUMWE_EMBEDDED_ENGINE_SHA256 "' . $manifest['archive_sha256'] . "\"\n#endif\n";
    makeDirectory(dirname($target));
    makeDirectory(dirname($lockPath));
    $staging = dirname($target) . '/.engine-source-' . bin2hex(random_bytes(12));
    makeDirectory($staging . '/bundle');
    $preserveBackup = false;
    try {
        foreach ($entries as $path => $entry) {
            $destination = $staging . '/bundle/' . $path;
            makeDirectory(dirname($destination));
            writeFile($destination, $entry['data']);
            if (!chmod($destination, $entry['mode'])) {
                throw new RuntimeException('Cannot preserve Engine source permissions.');
            }
        }
        writeFile($staging . '/lock.json', encode($manifest));
        writeFile($staging . '/build.h', $header);
        $previousLock = is_file($lockPath) ? readFile($lockPath) : null;
        $previousHeader = is_file($headerPath) ? readFile($headerPath) : null;
        if ($previous !== null) {
            verifyBundle($root, $previous, false);
        }
        $backup = $staging . '/previous';
        if (file_exists($target) && !rename($target, $backup)) {
            throw new RuntimeException('Cannot stage the previous Engine bundle.');
        }
        try {
            if (!rename($staging . '/bundle', $target)
                || !rename($staging . '/lock.json', $lockPath)
                || !rename($staging . '/build.h', $headerPath)) {
                throw new RuntimeException('Cannot atomically install the Engine source identities.');
            }
        } catch (Throwable $error) {
            try {
                removeTree($target);
                if (is_dir($backup) && !rename($backup, $target)) {
                    throw new RuntimeException('Cannot restore previous Engine bundle.');
                }
                foreach ([$lockPath => $previousLock, $headerPath => $previousHeader] as $path => $content) {
                    if ($content === null) {
                        if (file_exists($path) && !unlink($path)) {
                            throw new RuntimeException('Cannot restore absent source identity.');
                        }
                    } else {
                        writeFile($path, $content);
                    }
                }
            } catch (Throwable $rollbackError) {
                $preserveBackup = true;
                throw new RuntimeException('Engine replacement failed; recovery files retained in ' . $staging
                    . ': ' . $rollbackError->getMessage(), 0, $error);
            }
            throw $error;
        }
    } finally {
        if (!$preserveBackup) {
            removeTree($staging);
        }
    }
    $description = isset($manifest['snapshot']) ? 'reviewed native-tooling snapshot' : 'unmodified native source';
    echo 'Embedded ' . count($entries) . ' Engine files from ' . $commit . '; ' . $description . "; candidate only.\n";
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    try {
        main($argv);
    } catch (Throwable $error) {
        fwrite(STDERR, 'Engine embedding refused: ' . $error->getMessage() . "\n");
        exit(1);
    }
}
