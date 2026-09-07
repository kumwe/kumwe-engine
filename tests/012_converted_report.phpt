--TEST--
Converted money and quantity report values retain canonical provenance and exact rounding through native execution
--EXTENSIONS--
kumwe_engine
--FILE--
<?php
require __DIR__ . '/common.inc';
$runtime = new Kumwe\Engine\Runtime();
$path = __DIR__ . '/../vendor/engine/corpus/reporting/materialization-v1.json';
$corpus = json_decode(file_get_contents($path), false, 512, JSON_THROW_ON_ERROR);
$checked = 0;
foreach (['converted_money_half_up', 'converted_quantity_half_up'] as $id) {
    $fixture = null;
    foreach ($corpus->fixtures as $candidate) {
        if ($candidate->id === $id) { $fixture = $candidate; break; }
    }
    if ($fixture === null) { throw new RuntimeException('The committed converted-value fixture is missing.'); }
    $program = [];
    foreach (['columns', 'groups', 'aggregates', 'formulas', 'sorts'] as $key) {
        $program[$key] = $fixture->plan->{$key};
    }
    $plan = $runtime->compile(['wire_version' => 1, 'profile' => 'report-materialization-draft/1',
        'corpus_digest' => hash_file('sha256', $path), 'program' => json_encode($program, JSON_THROW_ON_ERROR)]);
    $input = json_encode(['fields' => ['rows' => $fixture->authorized_rows], 'lines' => new stdClass()], JSON_THROW_ON_ERROR);
    $request = ['plan_id' => $plan['plan_id'], 'batch' => batch_envelope([['correlation' => $id, 'input' => $input]])];
    $result = json_decode($runtime->execute($request)['results'][0]['result_json'], false, 512, JSON_THROW_ON_ERROR);
    if (!same_fixture_value($result->rows, $fixture->expected->rows) || $result->findings !== []) {
        throw new RuntimeException('Converted report parity failed for ' . $id);
    }
    $bad = $request;
    $bad['batch']['documents'][0]['input'] = str_replace('1.25', '1.24', $input);
    try {
        $runtime->execute($bad);
        throw new RuntimeException('Incorrectly rounded converted value was accepted.');
    } catch (Kumwe\Engine\Exception\BindingFailure $failure) {
        echo $id, ':refused:', $failure->getCode(), "\n";
    }
    $reused = json_decode($runtime->execute($request)['results'][0]['result_json'], false, 512, JSON_THROW_ON_ERROR);
    if (!same_fixture_value($reused->rows, $fixture->expected->rows)) { throw new RuntimeException('Plan changed after report refusal.'); }
    ++$checked;
}
echo 'converted profiles:', $checked, "\n";
?>
--EXPECT--
converted_money_half_up:refused:1
converted_quantity_half_up:refused:1
converted profiles:2
