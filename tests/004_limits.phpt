--TEST--
Plan capacity, cancellation and batch limits fail with stable statuses
--EXTENSIONS--
kumwe_engine
--FILE--
<?php
require __DIR__ . '/common.inc';
$r = new Kumwe\Engine\Runtime(); $p = literal_plan($r);
for ($i = 1; $i < 64; ++$i) { literal_plan($r); }
try { literal_plan($r); } catch (Kumwe\Engine\Exception\BindingFailure $e) { echo 'plans:', $e->getCode(), "\n"; }
try { $r->execute(['plan_id' => $p['plan_id'], 'batch' => batch_envelope([document()]), 'cancelled' => true]); }
catch (Kumwe\Engine\Exception\BindingFailure $e) { echo 'cancel:', $e->getCode(), "\n"; }
$batch = batch_envelope([document()]); $batch['limits']['max_instructions'] = 0;
try { $r->execute(['plan_id' => $p['plan_id'], 'batch' => $batch]); }
catch (Kumwe\Engine\Exception\BindingFailure $e) { echo 'budget:', $e->getCode(), "\n"; }
$batch = batch_envelope([document('duplicate'), document('duplicate')]);
try { $r->execute(['plan_id' => $p['plan_id'], 'batch' => $batch]); }
catch (Kumwe\Engine\Exception\BindingFailure $e) { echo 'correlation:', $e->getCode(), "\n"; }
?>
--EXPECT--
plans:6
cancel:7
budget:6
correlation:1
