<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$composer = json_decode(file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
if ($composer['name'] !== 'kumwe/kumwe-engine' || $composer['type'] !== 'php-ext'
    || $composer['php-ext']['extension-name'] !== 'kumwe_engine' || isset($composer['autoload'])) {
    throw new RuntimeException('PIE, module or exclusive native ownership identity is invalid.');
}
$stub = file_get_contents($root . '/stubs/kumwe_engine.stub.php');
$arginfo = file_get_contents($root . '/src/kumwe_engine_arginfo.h');
$source = file_get_contents($root . '/src/kumwe_engine.c');
foreach (['capabilities' => '', 'compile' => 'array $envelope', 'execute' => 'array $envelope'] as $method => $parameters) {
    if (!str_contains($stub, "public function $method($parameters): array")
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
