--TEST--
Hostile inputs refuse before callbacks, native allocation or unexpected coercion
--EXTENSIONS--
kumwe_engine
--FILE--
<?php
require __DIR__ . '/common.inc';
$r = new Kumwe\Engine\Runtime();
$hostile = new class implements JsonSerializable { public function jsonSerialize(): mixed { echo 'CALLBACK'; return 1; } };
$reference = 'value'; $withReference = ['op' => 'literal', 'type' => 'string', 'value' => &$reference];
foreach ([$hostile, fopen('php://memory', 'r'), 1.5, $withReference] as $value) {
    try { $r->compile(formula_envelope(is_array($value) ? $value : ['op' => 'literal', 'type' => 'string', 'value' => $value])); }
    catch (Kumwe\Engine\Exception\BindingFailure $e) { echo $e->getCode(), "\n"; }
}
$deep = [];
for ($i = 0; $i < 80; ++$i) { $deep = ['x' => $deep]; }
try { $r->compile(formula_envelope($deep)); } catch (Kumwe\Engine\Exception\BindingFailure $e) { echo $e->getCode(), "\n"; }
$invalid = formula_envelope(['op' => 'literal', 'type' => 'string', 'value' => 'x']);
$invalid['corpus_digest'] = str_repeat('0', 64);
try { $r->compile($invalid); } catch (Kumwe\Engine\Exception\BindingFailure $e) { echo $e->getCode(), "\n"; }
?>
--EXPECT--
1
1
1
1
6
4
