--TEST--
Request-local domain instance tokens preserve strict identity and refuse missing or conflicting identity evidence
--EXTENSIONS--
kumwe_engine
--FILE--
<?php
require __DIR__ . '/common.inc';
$path = __DIR__ . '/../vendor/engine/corpus/document/preparation-v1.json';
$corpus = json_decode(file_get_contents($path), false, 512, JSON_THROW_ON_ERROR);
$fixture = null;
foreach ($corpus->fixtures as $candidate) {
    if ($candidate->id === 'update_immutable_money_same_instance') { $fixture = $candidate; break; }
}
if ($fixture === null) { throw new RuntimeException('Required owner instance-identity fixture is missing.'); }
$runtime = new Kumwe\Engine\Runtime();
$plan = $runtime->compile(['wire_version' => 1, 'profile' => 'normalized-preparation-draft/1',
    'corpus_digest' => hash_file('sha256', $path), 'program' => json_encode($fixture->program, JSON_THROW_ON_ERROR)]);
$execute = static function (stdClass $fields) use ($runtime, $plan, $fixture): array {
    $input = json_encode(['fields' => $fields, 'lines' => $fixture->lines], JSON_THROW_ON_ERROR);
    $row = $runtime->execute(['plan_id' => $plan['plan_id'], 'batch' => batch_envelope([
        ['correlation' => 'identity', 'input' => $input],
    ])])['results'][0];
    if ($row['correlation'] !== 'identity' || str_contains($row['result_json'], '"instance"')) {
        throw new RuntimeException('Transport identity leaked into canonical storage.');
    }
    return [json_decode($row['result_json'], false, 512, JSON_THROW_ON_ERROR), $row['result_json']];
};
[$accepted, $bytes] = $execute($fixture->input);
if ($accepted->findings !== [] || !same_fixture_value($accepted->values, $fixture->expected->values)) {
    throw new RuntimeException('Same domain instance was not accepted.');
}
echo "same:accepted\n";
$distinct = json_decode(json_encode($fixture->input, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
$distinct->current->amount->instance = 2;
[$changed] = $execute($distinct);
if (!same_fixture_value($changed->findings, [(object) ['field' => 'amount', 'code' => 'immutable']])) {
    throw new RuntimeException('Equal-looking distinct domain instances lost strict identity.');
}
echo "distinct:immutable\n";
foreach (['missing' => 3, 'conflicting' => 1] as $case => $expected) {
    $invalid = json_decode(json_encode($fixture->input, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    if ($case === 'missing') { unset($invalid->current->amount->instance); }
    else { $invalid->input[0]->submitted->value->amount = '12.31'; }
    try {
        $execute($invalid);
        throw new RuntimeException('Invalid identity evidence was accepted.');
    } catch (Kumwe\Engine\Exception\BindingFailure $failure) {
        if ($failure->getCode() !== $expected) { throw $failure; }
        echo $case, ':', $failure->getCode(), "\n";
    }
}
[, $reused] = $execute($fixture->input);
if ($reused !== $bytes) { throw new RuntimeException('Identity refusal changed the reusable native plan.'); }
echo "reused:accepted\n";
?>
--EXPECT--
same:accepted
distinct:immutable
missing:3
conflicting:1
reused:accepted
