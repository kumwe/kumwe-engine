--TEST--
Canonical module, PHP classes, exact handshake and stub signatures
--EXTENSIONS--
kumwe_engine
--FILE--
<?php
$r = new Kumwe\Engine\Runtime();
$c = $r->capabilities();
var_dump(extension_loaded('kumwe_engine'), $c['abi_major'], strlen($c['embedded_engine_commit']), strlen($c['embedded_source_sha256']));
foreach (['capabilities' => 0, 'compile' => 1, 'execute' => 1] as $method => $count) {
    $m = new ReflectionMethod($r, $method);
    var_dump($m->getNumberOfRequiredParameters() === $count, (string)$m->getReturnType() === 'array');
}
var_dump((new ReflectionClass($r))->isInternal(), (new ReflectionClass($r))->isFinal());
?>
--EXPECT--
bool(true)
int(1)
int(40)
int(64)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
