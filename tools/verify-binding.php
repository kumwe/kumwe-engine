<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$record = file_get_contents($root . '/docs/release-record.md');
if (!preg_match('/^  public_manifests:\n((?:  - path: [^\n]+\n    sha256: [a-f0-9]{64}\n)+)(?=  intentionally_excluded:)/m', $record, $inventory)) {
    throw new RuntimeException('The complete record public manifest inventory is malformed.');
}
preg_match_all('/^  - path: ([^\n]+)\n    sha256: ([a-f0-9]{64})$/m', $inventory[1], $manifests, PREG_SET_ORDER);
$seen = [];
foreach ($manifests as [, $path, $digest]) {
    if (!preg_match('/\A[a-zA-Z0-9_-]+(?:\/[a-zA-Z0-9_.-]+)+\z/D', $path)
        || in_array('..', explode('/', $path), true) || isset($seen[$path])
        || !is_file($root . '/' . $path) || is_link($root . '/' . $path)
        || hash_file('sha256', $root . '/' . $path) !== $digest) {
        throw new RuntimeException('Record public manifest hash or path differs: ' . $path);
    }
    $seen[$path] = true;
}
$composer = json_decode(file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
if ($composer['name'] !== 'kumwe/kumwe-engine' || $composer['type'] !== 'php-ext'
    || $composer['php-ext']['extension-name'] !== 'kumwe_engine' || isset($composer['autoload'])) {
    throw new RuntimeException('PIE, module or exclusive native ownership identity is invalid.');
}
$api = json_decode(file_get_contents($root . '/resources/api/v1.json'), true, 512, JSON_THROW_ON_ERROR);
if ($api['owner'] !== $composer['name'] || $api['module'] !== 'kumwe_engine' || $api['platform'] !== 'ext-kumwe_engine'
    || $api['binding_features'] !== ['opaque-compiled-results/1']
    || $api['execute_profiles']['compiled-plan']['result_format']['allowed'] !== ['both', 'opaque']
    || $api['runtime'] !== 'zend' || array_keys($api['classes']) !== ['Kumwe\\Engine\\Runtime', 'Kumwe\\Engine\\Exception\\BindingFailure']
    || $api['classes']['Kumwe\\Engine\\Runtime']['methods'] !== ['capabilities(): array', 'compile(array $envelope): array', 'execute(array $envelope): array', 'release(string $planId): void']) {
    throw new RuntimeException('The public manifest differs from the reviewed native owner/API.');
}
$stub = file_get_contents($root . '/stubs/kumwe_engine.stub.php');
$arginfo = file_get_contents($root . '/src/kumwe_engine_arginfo.h');
$source = file_get_contents($root . '/src/kumwe_engine.c');
if (!str_contains($source, '"opaque-compiled-results/1"')
    || !str_contains($source, '"result_format"')) {
    throw new RuntimeException('Binding feature and compiled result-format source drift.');
}
foreach (['capabilities' => '', 'compile' => 'array $envelope', 'execute' => 'array $envelope', 'release' => 'string $planId'] as $method => $parameters) {
    $return = $method === 'release' ? 'void' : 'array';
    if (!str_contains($stub, "public function $method($parameters): $return")
        || !str_contains($source, "PHP_METHOD(Kumwe_Engine_Runtime, $method)")
        || !str_contains($arginfo, "PHP_ME(Kumwe_Engine_Runtime, $method,")) {
        throw new RuntimeException('Stub, arginfo and implementation drift: ' . $method);
    }
}
foreach (['call_user_function', 'zend_call_function', 'zend_eval_string', 'popen(', 'system(', 'exec(', 'FFI', 'class_alias'] as $forbidden) {
    if (str_contains($source, $forbidden)) { throw new RuntimeException('Forbidden runtime path: ' . $forbidden); }
}
if (!str_contains($source, 'ZEND_ACC_NOT_SERIALIZABLE') || !str_contains($source, 'runtime_handlers.clone_obj = NULL')) {
    throw new RuntimeException('Opaque native plan owners must not clone or serialize.');
}
echo "Binding identity, reviewed methods, stub/arginfo declarations and runtime boundary verified.\n";
