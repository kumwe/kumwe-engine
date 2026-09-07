<?php
require __DIR__ . '/../tests/common.inc';
for ($i = 0; $i < 100; ++$i) {
    $runtime = new Kumwe\Engine\Runtime();
    $plan = literal_plan($runtime);
    $runtime->execute(['plan_id' => $plan['plan_id'], 'batch' => batch_envelope([document()])]);
    $validator = unicode_validator_plan($runtime);
    $result = $runtime->execute(['plan_id' => $validator['plan_id'], 'batch' => batch_envelope([
        document('valid', ['value' => 'Ångström']), document('invalid', ['value' => 'wrong 42']),
    ])]);
    if ($result['results'][0]['result']['findings'] !== []
        || $result['results'][1]['result']['findings'] !== [['code' => 'pattern', 'field' => 'value']]) {
        throw new RuntimeException('Native validator lifecycle execution changed.');
    }
    try { $runtime->execute(['plan_id' => str_repeat('0', 32), 'batch' => []]); }
    catch (Kumwe\Engine\Exception\BindingFailure) {}
    $runtime->release($plan['plan_id']);
    for ($reuse = 0; $reuse < 70; ++$reuse) {
        $temporary = literal_plan($runtime);
        $runtime->release($temporary['plan_id']);
    }
    unset($runtime);
}
echo "100 owners with formula and PCRE2 plans and 7000 explicit plan releases completed.\n";
