--TEST--
Explicit plan release reclaims slots and source bytes without affecting other owners
--EXTENSIONS--
kumwe_engine
--FILE--
<?php
declare(strict_types=1);
require __DIR__ . '/common.inc';
$runtime = new Kumwe\Engine\Runtime();
$foreign = new Kumwe\Engine\Runtime();
$plans = [];
for ($i = 0; $i < 64; ++$i) { $plans[] = literal_plan($runtime); }
try { literal_plan($runtime); }
catch (Kumwe\Engine\Exception\BindingFailure $e) { echo 'full:', $e->getCode(), "\n"; }
try { $foreign->release($plans[0]['plan_id']); }
catch (Kumwe\Engine\Exception\BindingFailure $e) { echo 'foreign:', $e->getCode(), "\n"; }
$method = new ReflectionMethod($runtime, 'release');
var_dump((string)$method->getReturnType(), (string)$method->getParameters()[0]->getType());
var_dump($runtime->release($plans[0]['plan_id']));
foreach ([$plans[0]['plan_id'], '', str_repeat('0', 32)] as $id) {
    try { $runtime->release($id); }
    catch (Kumwe\Engine\Exception\BindingFailure $e) { echo 'invalid:', $e->getCode(), "\n"; }
}
try { $runtime->execute(['plan_id' => $plans[0]['plan_id'], 'batch' => batch_envelope([document()])]); }
catch (Kumwe\Engine\Exception\BindingFailure $e) { echo 'released:', $e->getCode(), "\n"; }
$replacement = literal_plan($runtime, 'replacement');
$output = $runtime->execute(['plan_id' => $replacement['plan_id'], 'batch' => batch_envelope([document()])]);
echo $output['results'][0]['result']['value'], "\n";
$runtime->release($replacement['plan_id']);
for ($i = 1; $i < 64; ++$i) { $runtime->release($plans[$i]['plan_id']); }
// Each encoded source includes 1 MiB of semantically inert JSON whitespace.
// More than 16 iterations prove the byte budget is refunded as well as slots.
$envelope = formula_envelope(['op' => 'literal', 'type' => 'string', 'value' => 'retained']);
$envelope['program'] = json_encode($envelope['program'], JSON_THROW_ON_ERROR) . str_repeat(' ', 1048576);
for ($i = 0; $i < 20; ++$i) {
    $plan = $runtime->compile($envelope);
    $runtime->release($plan['plan_id']);
}
for ($i = 0; $i < 256; ++$i) {
    $plan = literal_plan($runtime);
    $runtime->release($plan['plan_id']);
}
$plan = literal_plan($runtime, 'reused after release');
$output = $runtime->execute(['plan_id' => $plan['plan_id'], 'batch' => batch_envelope([document()])]);
echo $output['results'][0]['result']['value'], "\n";
?>
--EXPECT--
full:6
foreign:1
string(4) "void"
string(6) "string"
NULL
invalid:1
invalid:1
invalid:1
released:1
replacement
reused after release
