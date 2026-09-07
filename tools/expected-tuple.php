<?php
// Independent expected identity from verified source and the selected CMake recipe.
// This script deliberately does not load or query the extension.
$root = dirname(__DIR__);
$lock = json_decode(file_get_contents($root . '/resources/engine-lock.json'), true, 512, JSON_THROW_ON_ERROR);
$buildBytes = file_get_contents($root . '/build-identity.json');
$build = json_decode($buildBytes, true, 64, JSON_THROW_ON_ERROR);
$capabilities = json_decode(file_get_contents($root . '/engine-build/generated/capabilities.json'), true, 512, JSON_THROW_ON_ERROR);
$api = json_decode(file_get_contents($root . '/resources/api/v1.json'), true, 64, JSON_THROW_ON_ERROR);
if (!preg_match('/#define PHP_KUMWE_ENGINE_VERSION "([^"]+)"/', file_get_contents($root . '/php_kumwe_engine.h'), $version)) {
    throw new RuntimeException('Missing source extension version.');
}
echo json_encode(['capabilities' => $capabilities['computation'], 'extension_version' => $version[1],
    'binding_features' => $api['binding_features'],
    'embedded_engine_commit' => $lock['commit'], 'embedded_source_sha256' => $lock['archive_sha256'],
    'binding_build_digest' => hash('sha256', $buildBytes), 'binding_build' => $build],
    JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
