--TEST--
Canonical native dispatch preserves binary floats, mixed keys and shared references without callbacks
--EXTENSIONS--
kumwe_engine
--FILE--
<?php
$r = new Kumwe\Engine\Runtime();
function canonical(Kumwe\Engine\Runtime $r, mixed $value, string $operation = 'encode'): array {
    return $r->execute(['wire_version' => 1, 'profile' => 'kumwe-canonical-json/generic-v1',
        'corpus_digest' => hash_file('sha256', __DIR__ . '/../vendor/engine/corpus/canonical/generic-v1.json'),
        'operation' => $operation, 'input' => $value]);
}
foreach ([[], -0.0, ['z'=>1, 'a'=>'01.00'], ['first', 'second']] as $input) {
    echo canonical($r, $input)['output'], "\n";
}
$shared = ['one']; $input = ['a' => &$shared, 'b' => &$shared];
echo canonical($r, $input)['output'], "\n";
$object = new class implements JsonSerializable { public function jsonSerialize(): mixed { echo 'CALLBACK'; return 1; } };
echo canonical($r, $object)['finding'], "\n";
echo canonical($r, NAN)['finding'], "\n";
echo canonical($r, "\xff")['finding'], "\n";
echo canonical($r, ['a' => 1], 'digest')['sha256'], "\n";
?>
--EXPECT--
[]
-0.0
{"a":"01.00","z":1}
["first","second"]
{"a":["one"],"b":["one"]}
canonical.unsupported-type
canonical.non-finite-number
canonical.invalid-utf8
015abd7f5cc57a2dd94b7590f04ad8084273905ee33ec5cebeae62276a97f862
