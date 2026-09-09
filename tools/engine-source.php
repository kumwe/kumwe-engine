<?php

declare(strict_types=1);

namespace Kumwe\EngineSource;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

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

function validateLock(array $lock): void
{
    if (($lock['schema'] ?? null) !== 'kumwe-embedded-engine/v1'
        || !is_string($lock['commit'] ?? null)
        || !preg_match('/^[a-f0-9]{40}$/D', $lock['commit'])
        || !is_string($lock['archive_sha256'] ?? null)
        || !preg_match('/^[a-f0-9]{64}$/D', $lock['archive_sha256'])) {
        throw new RuntimeException('Invalid embedded Engine source identity.');
    }
    digestMap($lock['files'] ?? null);
    if (array_key_exists('snapshot', $lock)) {
        if (!is_array($lock['snapshot'])
            || array_keys($lock['snapshot']) !== ['profile', 'upstream_files']
            || $lock['snapshot']['profile'] !== 'php-native-tooling/v1') {
            throw new RuntimeException('Unknown embedded Engine snapshot profile.');
        }
        digestMap($lock['snapshot']['upstream_files']);
    }
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

function nativeFile(string $path, string $content): void
{
    // Third-party source bytes and native literals are immutable dependency material.
    $tooling = !str_starts_with($path, 'third_party/')
        && (preg_match('~(?:^|/)(?:tools|docs|\.github)/|\.(?:sh|php|cmake|m4|md|ya?ml)$~iD', $path)
            || in_array(basename($path), ['CMakeLists.txt', 'Makefile'], true));
    if (preg_match('/\.(?:py[coiw]?|mjs|cjs|js|ts)$/iD', $path)
        || ($tooling && preg_match('/\bpython(?:[23](?:\.[0-9]+)*)?\b/i', $content))) {
        throw new RuntimeException('Engine snapshot contains unsupported tooling: ' . $path);
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

function verifyBundle(string $root, array $lock, bool $native = true): void
{
    validateLock($lock);
    if (treeFiles($root . '/vendor/engine', $native) !== $lock['files']) {
        throw new RuntimeException('Embedded Engine differs from its reviewed source lock; replacement refused.');
    }
    $header = readFile($root . '/php_kumwe_engine_build.h');
    if (!str_contains($header, '"' . $lock['commit'] . '"')
        || !str_contains($header, '"' . $lock['archive_sha256'] . '"')) {
        throw new RuntimeException('Compiled handshake identity differs from the source lock.');
    }
}
