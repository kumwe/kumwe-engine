--TEST--
Opaque JSON crosses without losing numeric spelling and empty line rows remain maps
--EXTENSIONS--
kumwe_engine
--FILE--
<?php
require __DIR__ . '/common.inc';
$r = new Kumwe\Engine\Runtime();
$envelope = formula_envelope([]);
$envelope['program'] = '{"op":"literal","type":"decimal","value":1e-9999}';
try { $r->compile($envelope); }
catch (Kumwe\Engine\Exception\BindingFailure $e) { echo 'opaque invalid decimal:', $e->getCode(), "\n"; }
$envelope['program'] = '{"op":"literal","type":"string","value":"1e-9999"}';
$p = $r->compile($envelope);
$batch = batch_envelope([['correlation' => 'opaque', 'input' => '{"fields":{},"lines":null}']]);
$out = execute_formats($r, ['plan_id' => $p['plan_id'], 'batch' => $batch]);
echo $out['results'][0]['result_json'], "\n";
$batch = batch_envelope([document(lines: ['items' => [[]]])]);
$out = execute_formats($r, ['plan_id' => $p['plan_id'], 'batch' => $batch]);
echo $out['results'][0]['result']['value'], "\n";
?>
--EXPECT--
opaque invalid decimal:5
{"findings":[],"value":"1e-9999"}
1e-9999
