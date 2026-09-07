<?php
require __DIR__ . '/../tests/common.inc';
for ($i = 0; $i < 100; ++$i) {
    $runtime = new Kumwe\Engine\Runtime();
    $plan = literal_plan($runtime);
    $runtime->execute(['plan_id' => $plan['plan_id'], 'batch' => batch_envelope([document()])]);
    try { $runtime->execute(['plan_id' => str_repeat('0', 32), 'batch' => []]); }
    catch (Kumwe\Engine\Exception\BindingFailure) {}
    unset($runtime);
}
echo "100 owners compiled, executed and destroyed.\n";
