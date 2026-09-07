--TEST--
Native build identity matches its independent configure record and the actual PHP host
--EXTENSIONS--
kumwe_engine
--FILE--
<?php
$root = dirname(__DIR__);
$runtime = new Kumwe\Engine\Runtime();
$capabilities = $runtime->capabilities();
$bytes = file_get_contents($root . '/build-identity.json');
if (!is_string($bytes)) { throw new RuntimeException('Independent configure build record is missing'); }
$independent = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
if (($independent['schema'] ?? null) !== 'kumwe-zend-build/v1'
    || ($capabilities['binding_build'] ?? null) !== $independent
    || ($capabilities['binding_build_digest'] ?? null) !== hash('sha256', $bytes)) {
    throw new RuntimeException('Native build identity differs from independent configure bytes');
}
foreach ([
    'php_version' => PHP_VERSION,
    'php_version_id' => PHP_VERSION_ID,
    'thread_model' => PHP_ZTS ? 'ZTS' : 'NTS',
    'debug' => (bool) PHP_DEBUG,
    'os' => PHP_OS_FAMILY,
    'architecture' => php_uname('m'),
] as $key => $expected) {
    if ($independent[$key] !== $expected) {
        throw new RuntimeException('Build identity does not describe actual PHP host: ' . $key);
    }
}
$manifestPath = $root . '/vendor/engine/resources/abi-manifest.json';
$manifest = json_decode(file_get_contents($manifestPath), true, 64, JSON_THROW_ON_ERROR);
foreach (['abi_major', 'abi_minor'] as $key) {
    if (($capabilities[$key] ?? null) !== $manifest[$key] || $independent[$key] !== $manifest[$key]) {
        throw new RuntimeException('Public ABI identity differs from pinned Engine manifest: ' . $key);
    }
}
$manifestDigest = hash_file('sha256', $manifestPath);
if (($capabilities['abi_manifest_sha256'] ?? null) !== $manifestDigest
    || $independent['abi_manifest_sha256'] !== $manifestDigest) {
    throw new RuntimeException('Public ABI manifest fingerprint differs');
}
// Compiler, linker, libc, flags and sanitizers come from the independent build
// record. A normal build and an instrumented build must each report their own facts.
foreach (['zend_module_api', 'host', 'libc', 'compiler', 'linker', 'extension_flags',
    'engine_flags', 'php_configure_options', 'sanitizers', 'extension_source_sha256'] as $key) {
    if (!array_key_exists($key, $independent)) {
        throw new RuntimeException('Required native build identity field is missing: ' . $key);
    }
}
$lock = json_decode(file_get_contents($root . '/resources/engine-lock.json'), true, 512, JSON_THROW_ON_ERROR);
$api = json_decode(file_get_contents($root . '/resources/api/v1.json'), true, 64, JSON_THROW_ON_ERROR);
if ($capabilities['embedded_engine_commit'] !== $lock['commit']
    || $capabilities['embedded_source_sha256'] !== $lock['archive_sha256']
    || $capabilities['extension_package'] !== $api['owner']
    || $capabilities['extension_module'] !== $api['module']) {
    throw new RuntimeException('Reported source or public module identity differs from pinned metadata');
}
$capabilities['binding_build']['php_version'] = 'caller mutation';
$capabilities['binding_build']['sanitizers'][] = 'caller mutation';
$again = $runtime->capabilities();
if ($again['binding_build'] !== $independent || $again['binding_build_digest'] !== hash('sha256', $bytes)) {
    throw new RuntimeException('Caller mutation changed native build identity');
}
echo "independent build bytes and digest match\n";
echo "actual PHP version, thread model and debug mode match\n";
echo "ABI manifest, source identity and detached metadata match\n";
?>
--EXPECT--
independent build bytes and digest match
actual PHP version, thread model and debug mode match
ABI manifest, source identity and detached metadata match
