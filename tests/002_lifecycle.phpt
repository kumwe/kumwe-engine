--TEST--
Owned native plans execute only in their Runtime and cannot clone or serialize
--EXTENSIONS--
kumwe_engine
--FILE--
<?php
require __DIR__ . '/common.inc';
$r = new Kumwe\Engine\Runtime(); $other = new Kumwe\Engine\Runtime();
$p = literal_plan($r);
$out = $r->execute(['plan_id' => $p['plan_id'], 'batch' => batch_envelope([document()])]);
var_dump($out['results'][0]['result']['value'], $p['descriptor']['profile']);
try { $other->execute(['plan_id' => $p['plan_id'], 'batch' => batch_envelope([document()])]); }
catch (Kumwe\Engine\Exception\BindingFailure $e) { echo 'foreign:', $e->getCode(), "\n"; }
try { $copy = clone $r; } catch (Error $e) { echo "clone refused\n"; }
try { serialize($r); } catch (Exception $e) { echo "serialize refused\n"; }
unset($r);
for ($i = 0; $i < 100; ++$i) { $r = new Kumwe\Engine\Runtime(); literal_plan($r); unset($r); }
echo "lifecycle clean\n";
?>
--EXPECT--
string(8) "123.4500"
string(15) "formula-draft/1"
foreign:1
clone refused
serialize refused
lifecycle clean
