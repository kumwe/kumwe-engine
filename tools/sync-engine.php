#!/usr/bin/env php
<?php

declare(strict_types=1);

namespace Kumwe\EngineSource;

use RuntimeException;
use Throwable;

require_once __DIR__ . '/engine-source.php';
require_once __DIR__ . '/release-source.php';

use function Kumwe\ReleaseSource\archive_files;
use function Kumwe\ReleaseSource\encode;

/**
 * Embed one exact Engine release archive and hard-link the extension version to it.
 *
 *   php tools/sync-engine.php kumwe-engine-source.tar.gz --commit SHA [--release vX.Y.Z] [--expected-sha256 HEX]
 *
 * The archive is the published `kumwe-engine-source.tar.gz` asset (or the same recipe run on an
 * unreleased commit when --release is omitted). Its bytes are installed under vendor/engine
 * unchanged, `resources/engine-lock.json` and `php_kumwe_engine_build.h` record the identity,
 * and `php_kumwe_engine.h` plus `resources/compatibility/v1.json` take the Engine version.
 */
function usage(): never
{
    throw new RuntimeException('Usage: php tools/sync-engine.php ARCHIVE.tar.gz --commit SHA [--release vX.Y.Z] [--expected-sha256 HEX]');
}

function parseArguments(array $arguments): array
{
    $parsed = ['archive' => null, 'commit' => null, 'release' => null, 'expected_sha256' => null];
    for ($index = 1; $index < count($arguments); ++$index) {
        $value = $arguments[$index];
        if (str_starts_with($value, '--')) {
            $key = str_replace('-', '_', substr($value, 2));
            if (!in_array($key, ['commit', 'release', 'expected_sha256'], true) || !isset($arguments[$index + 1])) {
                usage();
            }
            $parsed[$key] = $arguments[++$index];
        } elseif ($parsed['archive'] === null) {
            $parsed['archive'] = $value;
        } else {
            usage();
        }
    }
    if ($parsed['archive'] === null || !is_string($parsed['commit']) || !preg_match('/^[a-f0-9]{40}$/D', $parsed['commit'])) {
        usage();
    }
    if ($parsed['release'] !== null && !preg_match('/^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/D', $parsed['release'])) {
        throw new RuntimeException('A release must be an exact vMAJOR.MINOR.PATCH tag.');
    }
    if ($parsed['expected_sha256'] !== null && !preg_match('/^[a-f0-9]{64}$/D', $parsed['expected_sha256'])) {
        throw new RuntimeException('The expected archive digest must be 64 lowercase hex characters.');
    }
    return $parsed;
}

/** Replace exactly one declaration; any other count means the file no longer has the expected shape. */
function replaceOnce(string $content, string $pattern, string $replacement, string $what): string
{
    $result = preg_replace($pattern, $replacement, $content, -1, $count);
    if ($result === null || $count !== 1) {
        throw new RuntimeException('Cannot update ' . $what . ': expected exactly one declaration.');
    }
    return $result;
}

function main(array $arguments): void
{
    $options = parseArguments($arguments);
    $root = dirname(__DIR__);
    if (is_link($options['archive']) || !is_file($options['archive'])) {
        throw new RuntimeException('The Engine release archive must be a regular file.');
    }
    $bytes = readFile($options['archive']);
    $archiveDigest = hash('sha256', $bytes);
    if ($options['expected_sha256'] !== null && $options['expected_sha256'] !== $archiveDigest) {
        throw new RuntimeException('The Engine archive digest ' . $archiveDigest . ' differs from the expected ' . $options['expected_sha256'] . '.');
    }
    try {
        $entries = archive_files($bytes, ENGINE_ARCHIVE_PREFIX);
    } catch (\Kumwe\ReleaseSource\ReleaseError $error) {
        throw new RuntimeException('Invalid Engine release archive: ' . $error->getMessage(), 0, $error);
    }
    $files = [];
    foreach ($entries as $path => $entry) {
        nativeFile($path, $entry['data']);
        $files[$path] = hash('sha256', $entry['data']);
    }
    foreach (['CMakeLists.txt', 'LICENSE', 'include/kumwe/engine/engine.h', 'resources/capabilities.json',
              'resources/abi-manifest.json', 'resources/contracts.json'] as $required) {
        if (!isset($entries[$required])) {
            throw new RuntimeException('The Engine archive lacks required source material: ' . $required);
        }
    }
    $capabilities = json_decode($entries['resources/capabilities.json']['data'], true, 512, JSON_THROW_ON_ERROR);
    $version = $capabilities['version'] ?? null;
    if (!is_string($version) || !preg_match(SEMVER, $version)
        || ($capabilities['computation']['engine_version'] ?? null) !== $version) {
        throw new RuntimeException('The Engine archive does not declare one exact MAJOR.MINOR.PATCH version.');
    }
    if ($options['release'] !== null && $options['release'] !== 'v' . $version) {
        throw new RuntimeException('Release ' . $options['release'] . ' does not match the archived Engine version ' . $version . '.');
    }
    $lock = [
        'schema' => LOCK_SCHEMA,
        'repository' => ENGINE_REPOSITORY,
        'version' => $version,
        'release' => $options['release'],
        'commit' => $options['commit'],
        'archive_name' => ENGINE_ARCHIVE,
        'archive_sha256' => $archiveDigest,
        'files' => $files,
    ];
    validateLock($lock);

    $target = $root . '/vendor/engine';
    $lockPath = $root . '/resources/engine-lock.json';
    $headerPath = $root . '/php_kumwe_engine_build.h';
    $versionHeaderPath = $root . '/php_kumwe_engine.h';
    $compatibilityPath = $root . '/resources/compatibility/v1.json';
    foreach ([$root . '/vendor', $root . '/resources', $target, $lockPath, $headerPath, $versionHeaderPath, $compatibilityPath] as $path) {
        if (is_link($path)) {
            throw new RuntimeException('Engine bundle and identity paths must not be symbolic links.');
        }
    }
    if (file_exists($target)) {
        $previous = readLock($root);
        if (treeFiles($target, false) !== $previous['files']) {
            throw new RuntimeException('The current vendor/engine differs from its lock; refusing to replace unreviewed local edits.');
        }
    }
    $versionHeader = replaceOnce(readFile($versionHeaderPath), '/^#define PHP_KUMWE_ENGINE_VERSION "[^"]*"$/m',
        '#define PHP_KUMWE_ENGINE_VERSION "' . $version . '"', 'php_kumwe_engine.h');
    // Edit the declaration in place: re-encoding would turn the numeric-keyed status map into a list.
    $compatibility = replaceOnce(readFile($compatibilityPath), '/^(  "version": ")[^"]*(",?)$/m',
        '${1}' . $version . '${2}', 'resources/compatibility/v1.json');

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
            if (!chmod($destination, ($entry['mode'] & 0777) !== 0 ? $entry['mode'] & 0777 : 0644)) {
                throw new RuntimeException('Cannot preserve Engine source permissions.');
            }
        }
        $previousFiles = [];
        foreach ([$lockPath, $headerPath, $versionHeaderPath, $compatibilityPath] as $path) {
            $previousFiles[$path] = is_file($path) ? readFile($path) : null;
        }
        $backup = $staging . '/previous';
        if (file_exists($target) && !rename($target, $backup)) {
            throw new RuntimeException('Cannot stage the previous Engine bundle.');
        }
        try {
            if (!rename($staging . '/bundle', $target)) {
                throw new RuntimeException('Cannot install the Engine source tree.');
            }
            writeFile($lockPath, encode($lock));
            writeFile($headerPath, buildHeader($lock));
            writeFile($versionHeaderPath, $versionHeader);
            writeFile($compatibilityPath, $compatibility);
        } catch (Throwable $error) {
            try {
                removeTree($target);
                if (is_dir($backup) && !rename($backup, $target)) {
                    throw new RuntimeException('Cannot restore previous Engine bundle.');
                }
                foreach ($previousFiles as $path => $content) {
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
    verifyBundle($root, readLock($root));
    echo 'Embedded Engine ' . ($options['release'] ?? 'unreleased source') . ' (' . $version . ', ' . $options['commit'] . ', '
        . count($files) . ' files, archive sha256 ' . $archiveDigest . '); extension version is now ' . $version . ".\n";
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    try {
        main($argv);
    } catch (Throwable $error) {
        fwrite(STDERR, 'Engine sync refused: ' . $error->getMessage() . "\n");
        exit(1);
    }
}
