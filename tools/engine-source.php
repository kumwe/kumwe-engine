<?php

declare(strict_types=1);

namespace Kumwe\EngineSource;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * The embedded Engine is the exact published release archive, byte for byte. This file owns
 * the lock schema, the source policy and the verification shared by configure, CI and sync.
 */
const LOCK_SCHEMA = 'kumwe-embedded-engine/v2';
const ENGINE_REPOSITORY = 'https://github.com/kumwe/engine';
const ENGINE_ARCHIVE = 'kumwe-engine-source.tar.gz';
const ENGINE_ARCHIVE_PREFIX = 'kumwe-engine/';
const SEMVER = '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/D';

function safePath(string $path): bool
{
    return $path !== '' && $path[0] !== '/' && !preg_match('/[\\\\\x00-\x1f\x7f]/', $path)
        && !array_intersect(explode('/', $path), ['', '.', '..']);
}

function digestMap(mixed $map): array
{
    if (!is_array($map) || $map === []) {
        throw new RuntimeException('The Engine source digest map must be nonempty.');
    }
    foreach ($map as $path => $digest) {
        if (!is_string($path) || !safePath($path) || !is_string($digest)
            || !preg_match('/^[a-f0-9]{64}$/D', $digest)) {
            throw new RuntimeException('Invalid Engine source path or digest.');
        }
    }
    $sorted = $map;
    ksort($sorted, SORT_STRING);
    if ($sorted !== $map) {
        throw new RuntimeException('The Engine source digest map must be sorted.');
    }
    return $map;
}

/** Every field of the lock is exact; a release tag is present only for a published Engine release. */
function validateLock(array $lock): void
{
    $expectedKeys = ['schema', 'repository', 'version', 'release', 'commit', 'archive_name', 'archive_sha256', 'files'];
    if (array_keys($lock) !== $expectedKeys) {
        throw new RuntimeException('The embedded Engine lock must contain exactly: ' . implode(', ', $expectedKeys) . '.');
    }
    if ($lock['schema'] !== LOCK_SCHEMA || $lock['repository'] !== ENGINE_REPOSITORY
        || !is_string($lock['version']) || !preg_match(SEMVER, $lock['version'])
        || !is_string($lock['commit']) || !preg_match('/^[a-f0-9]{40}$/D', $lock['commit'])
        || $lock['archive_name'] !== ENGINE_ARCHIVE
        || !is_string($lock['archive_sha256']) || !preg_match('/^[a-f0-9]{64}$/D', $lock['archive_sha256'])) {
        throw new RuntimeException('Invalid embedded Engine source identity.');
    }
    if ($lock['release'] !== null && $lock['release'] !== 'v' . $lock['version']) {
        throw new RuntimeException('The embedded Engine release tag must be null or v' . $lock['version'] . '.');
    }
    digestMap($lock['files']);
}

function readFile(string $path): string
{
    $content = file_get_contents($path);
    if ($content === false) {
        throw new RuntimeException('Cannot read ' . $path);
    }
    return $content;
}

function writeFile(string $path, string $content): void
{
    if (file_put_contents($path, $content) !== strlen($content)) {
        throw new RuntimeException('Cannot write ' . $path);
    }
}

function makeDirectory(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0777, true)) {
        throw new RuntimeException('Cannot create directory ' . $path);
    }
}

function removeTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        if (!unlink($path)) {
            throw new RuntimeException('Cannot remove ' . $path);
        }
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) {
        removeTree($entry->getPathname());
    }
    if (!rmdir($path)) {
        throw new RuntimeException('Cannot remove directory ' . $path);
    }
}

/**
 * Source policy for the embedded Engine: C, C++, CMake, Shell, data and documentation only.
 * Third-party dependency bytes are immutable material. Prose may mention any language; only
 * executable tooling is refused when it would invoke an interpreter this repository does not use.
 */
function nativeFile(string $path, string $content): void
{
    if (str_starts_with($path, 'third_party/')) {
        return;
    }
    if (preg_match('/\.(?:py[coiw]?|mjs|cjs|js|ts)$/iD', $path)) {
        throw new RuntimeException('Engine source contains an unsupported implementation language: ' . $path);
    }
    $executable = preg_match('/\.(?:sh|cmake|m4|ya?ml)$/iD', $path)
        || in_array(basename($path), ['CMakeLists.txt', 'Makefile'], true);
    if ($executable && preg_match('/\b(?:python[0-9.]*|pip[0-9.]*|node|npm|npx)\b/i', $content)) {
        throw new RuntimeException('Engine tooling invokes an unsupported interpreter: ' . $path);
    }
}

function treeFiles(string $directory, bool $native = true): array
{
    if (is_link($directory) || !is_dir($directory)) {
        throw new RuntimeException('The Engine bundle must be a regular directory.');
    }
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $entry) {
        if ($entry->isLink() || (!$entry->isFile() && !$entry->isDir())) {
            throw new RuntimeException('Engine source must contain only regular files and directories.');
        }
        if ($entry->isFile()) {
            $path = substr($entry->getPathname(), strlen($directory) + 1);
            if (!safePath($path)) {
                throw new RuntimeException('Invalid Engine source path.');
            }
            $content = readFile($entry->getPathname());
            if ($native) {
                nativeFile($path, $content);
            }
            $files[$path] = hash('sha256', $content);
        }
    }
    ksort($files, SORT_STRING);
    return $files;
}

/** The compiled handshake header carries the exact embedded identity into the module. */
function buildHeader(array $lock): string
{
    return "#ifndef PHP_KUMWE_ENGINE_BUILD_H\n#define PHP_KUMWE_ENGINE_BUILD_H\n"
        . '#define KUMWE_EMBEDDED_ENGINE_VERSION "' . $lock['version'] . "\"\n"
        . '#define KUMWE_EMBEDDED_ENGINE_RELEASE "' . ($lock['release'] ?? '') . "\"\n"
        . '#define KUMWE_EMBEDDED_ENGINE_COMMIT "' . $lock['commit'] . "\"\n"
        . '#define KUMWE_EMBEDDED_ENGINE_SHA256 "' . $lock['archive_sha256'] . "\"\n#endif\n";
}

function bindingVersion(string $root): string
{
    if (!preg_match('/^#define PHP_KUMWE_ENGINE_VERSION "([^"]+)"$/m', readFile($root . '/php_kumwe_engine.h'), $match)
        || !preg_match(SEMVER, $match[1])) {
        throw new RuntimeException('php_kumwe_engine.h must declare exactly one MAJOR.MINOR.PATCH extension version.');
    }
    return $match[1];
}

function readLock(string $root): array
{
    $lock = json_decode(readFile($root . '/resources/engine-lock.json'), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($lock)) {
        throw new RuntimeException('The embedded Engine lock must be a JSON object.');
    }
    validateLock($lock);
    return $lock;
}

/**
 * Verify the embedded tree against its lock and the compiled handshake, and enforce the hard
 * version link: the extension version equals the embedded Engine version everywhere it is declared.
 */
function verifyBundle(string $root, array $lock, bool $native = true): void
{
    validateLock($lock);
    if (treeFiles($root . '/vendor/engine', $native) !== $lock['files']) {
        throw new RuntimeException('Embedded Engine differs from its exact source lock; replacement refused.');
    }
    if (readFile($root . '/php_kumwe_engine_build.h') !== buildHeader($lock)) {
        throw new RuntimeException('Compiled handshake identity differs from the source lock.');
    }
    $capabilities = json_decode(readFile($root . '/vendor/engine/resources/capabilities.json'), true, 512, JSON_THROW_ON_ERROR);
    if (($capabilities['version'] ?? null) !== $lock['version']
        || ($capabilities['computation']['engine_version'] ?? null) !== $lock['version']) {
        throw new RuntimeException('The embedded Engine declares a different version than the lock.');
    }
    $compatibility = json_decode(readFile($root . '/resources/compatibility/v1.json'), true, 512, JSON_THROW_ON_ERROR);
    $binding = bindingVersion($root);
    if ($binding !== $lock['version'] || ($compatibility['version'] ?? null) !== $lock['version']) {
        throw new RuntimeException('The extension version (' . $binding . ') must equal the embedded Engine version ('
            . $lock['version'] . '); versions are hard-linked.');
    }
}
