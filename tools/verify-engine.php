<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$lock = json_decode(file_get_contents($root . '/resources/engine-lock.json'), true, 512, JSON_THROW_ON_ERROR);
if ($lock['schema'] !== 'kumwe-embedded-engine/v1' || !preg_match('/^[a-f0-9]{40}$/D', $lock['commit'])
    || !preg_match('/^[a-f0-9]{64}$/D', $lock['archive_sha256']) || $lock['files'] === []) {
    throw new RuntimeException('Invalid embedded Engine source identity.');
}
$actual = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/vendor/engine', FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->isLink()) { throw new RuntimeException('Embedded Engine source cannot contain symbolic links.'); }
    if ($file->isFile()) {
        $path = substr($file->getPathname(), strlen($root . '/vendor/engine/'));
        $actual[$path] = hash_file('sha256', $file->getPathname());
    }
}
ksort($actual);
if ($actual !== $lock['files']) { throw new RuntimeException('Embedded Engine differs from its exact source lock.'); }
$header = file_get_contents($root . '/php_kumwe_engine_build.h');
if (!str_contains($header, '"' . $lock['commit'] . '"')
    || !str_contains($header, '"' . $lock['archive_sha256'] . '"')) {
    throw new RuntimeException('Compiled handshake identity differs from the source lock.');
}
echo count($actual) . " embedded Engine source digests verified; release verification is separate.\n";
