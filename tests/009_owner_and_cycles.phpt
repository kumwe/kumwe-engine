--TEST--
Canonical cycles terminate and immutable plans ignore mutations of returned descriptors
--EXTENSIONS--
kumwe_engine
--FILE--
<?php
require __DIR__ . '/common.inc';
$r = new Kumwe\Engine\Runtime();
$cycle = []; $cycle['self'] = &$cycle;
$request = ['wire_version' => 1, 'profile' => 'kumwe-canonical-json/generic-v1',
    'corpus_digest' => hash_file('sha256', __DIR__ . '/../vendor/engine/corpus/canonical/generic-v1.json'),
    'operation' => 'encode', 'input' => $cycle];
echo $r->execute($request)['finding'], "\n";
$stream = fopen('php://memory', 'r+'); $request['input'] = $stream;
echo $r->execute($request)['finding'], "\n"; fclose($stream);
$p = literal_plan($r);
$p['descriptor']['program']['value'] = 'changed';
$out = $r->execute(['plan_id' => $p['plan_id'], 'batch' => batch_envelope([document()])]);
echo $out['results'][0]['result']['value'], "\n";
$compile = formula_envelope(['op' => 'literal', 'type' => 'string', 'value' => 'bounded']);
$compile['limits'] = batch_envelope([])['limits']; $compile['limits']['max_input_bytes'] = 1;
try { $r->compile($compile); } catch (Kumwe\Engine\Exception\BindingFailure $e) { echo 'compile budget:', $e->getCode(), "\n"; }
try { $r->extra = 1; } catch (Error $e) { echo "dynamic property refused\n"; }
try { $r->execute('not an array'); } catch (TypeError $e) { echo "parameter type refused\n"; }
?>
--EXPECT--
canonical.depth-limit
canonical.unsupported-type
123.4500
compile budget:6
dynamic property refused
parameter type refused
