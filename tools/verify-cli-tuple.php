<?php

declare(strict_types=1);

// Independent Engine executable and source metadata; never query/load the Zend module.
$root = dirname(__DIR__);
$read = static fn (string $path): mixed => json_decode(file_get_contents($root . '/' . $path), true, 64, JSON_THROW_ON_ERROR);
$cli = $read('engine-cli-capabilities.json');
$source = $read('engine-build/generated/capabilities.json');
$expected = $read('candidate-compatibility.json');
if (!is_array($cli) || $cli !== $source || $cli['computation'] !== $expected['capabilities']
    || $cli['abi_major'] !== $expected['binding_build']['abi_major']
    || $cli['abi_minor'] !== $expected['binding_build']['abi_minor']
    || $cli['abi_manifest_sha256'] !== $expected['binding_build']['abi_manifest_sha256']) {
    throw new RuntimeException('Standalone Engine CLI differs from independent source/build compatibility.');
}
echo "Standalone Engine CLI, verified source capabilities and independent expected tuple match.\n";
