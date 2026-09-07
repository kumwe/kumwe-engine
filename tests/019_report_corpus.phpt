--TEST--
Public report compilation and execution replay every frozen materialization vector and refusal mode
--EXTENSIONS--
kumwe_engine
--FILE--
<?php
require __DIR__ . '/common.inc';
$path = __DIR__ . '/../vendor/engine/corpus/reporting/materialization-v1.json';
$corpus = json_decode(file_get_contents($path), false, 512, JSON_THROW_ON_ERROR);
$digest = hash_file('sha256', $path);
$counts = ['rows' => 0, 'refused' => 0];
foreach ($corpus->fixtures as $fixture) {
    $runtime = new Kumwe\Engine\Runtime();
    // Match the native corpus runner's pure materialization projection exactly.
    $program = [];
    foreach (['columns', 'groups', 'aggregates', 'formulas', 'sorts'] as $key) {
        $program[$key] = $fixture->plan->{$key};
    }
    try {
        $plan = $runtime->compile(['wire_version' => 1, 'profile' => 'report-materialization-draft/1',
            'corpus_digest' => $digest,
            'program' => json_encode($program, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)]);
    } catch (Kumwe\Engine\Exception\BindingFailure $failure) {
        throw new RuntimeException($fixture->id . ': valid report plan refused at compilation', 0, $failure);
    }
    $input = json_encode(['fields' => ['rows' => $fixture->authorized_rows], 'lines' => new stdClass()],
        JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    $request = ['plan_id' => $plan['plan_id'], 'batch' => batch_envelope([
        ['correlation' => $fixture->id, 'input' => $input],
    ])];
    try {
        $output = execute_formats($runtime, $request);
    } catch (Kumwe\Engine\Exception\BindingFailure $failure) {
        // ReportUnavailable is the owner's runtime refusal, mapped by the public ABI to INVALID_INPUT.
        if (($fixture->expected->refusal_class ?? null) !== 'Kumwe\\App\\BusinessReporting\\Application\\ReportUnavailable'
            || $failure->getCode() !== 1) {
            throw new RuntimeException($fixture->id . ': unexpected report refusal ' . $failure->getCode(), 0, $failure);
        }
        ++$counts['refused'];
        continue;
    }
    if (!property_exists($fixture->expected, 'rows')) {
        throw new RuntimeException($fixture->id . ': invalid report input was accepted');
    }
    if ($output['wire_version'] !== 1 || count($output['results']) !== 1) {
        throw new RuntimeException($fixture->id . ': batch framing mismatch');
    }
    $row = $output['results'][0];
    $result = json_decode($row['result_json'], false, 512, JSON_THROW_ON_ERROR);
    if ($row['correlation'] !== $fixture->id || $row['findings'] !== [] || $result->findings !== []
        || !same_fixture_value($result->rows, $fixture->expected->rows)) {
        throw new RuntimeException($fixture->id . ': report rows or scalar types mismatch: ' . $row['result_json']);
    }
    ++$counts['rows'];
}
if ($counts !== ['rows' => 54, 'refused' => 62]) {
    throw new RuntimeException('The complete 116-vector report corpus was not replayed.');
}
echo 'report corpus:116:rows=', $counts['rows'], ':runtime refusals=', $counts['refused'], "\n";

$runtime = new Kumwe\Engine\Runtime();
// The same five invalid-program guards exercised by the C++ report corpus runner.
$invalidPrograms = [
    '{"columns":[{"alias":"n","type":"decimal"}],"query":"SELECT secret"}',
    '{"columns":[{"alias":"n","type":"string"}],"aggregates":[{"alias":"s","function":"sum","column":"n"}]}',
    '{"columns":[{"alias":"n","type":"integer"}],"groups":[{"column":"absent"}]}',
    '{"columns":[{"alias":"n","type":"integer"}],"formulas":[{"alias":"x","type":"integer","expression":{"op":"field","type":"integer","field":"absent"}}]}',
    '{"columns":[{"alias":"n","type":"integer"}],"formulas":[{"alias":"x","type":"integer","expression":{"op":"line_aggregate","type":"integer","lines":"hidden","aggregate":"count"}}]}',
];
foreach ($invalidPrograms as $program) {
    try {
        $runtime->compile(['wire_version' => 1, 'profile' => 'report-materialization-draft/1',
            'corpus_digest' => $digest, 'program' => $program]);
        throw new RuntimeException('Invalid report program compiled.');
    } catch (Kumwe\Engine\Exception\BindingFailure $failure) {
        if ($failure->getCode() !== 5) { throw $failure; }
    }
}
$program = '{"columns":[{"alias":"n","type":"integer"}],"formulas":[{"alias":"double_n","type":"integer","expression":{"op":"multiply","type":"integer","args":[{"op":"field","type":"integer","field":"n"},{"op":"literal","type":"integer","value":2}]}},{"alias":"next","type":"integer","expression":{"op":"add","type":"integer","args":[{"op":"field","type":"integer","field":"double_n"},{"op":"literal","type":"integer","value":1}]}}],"sorts":[{"output":"next","direction":"desc","nulls_last":true}]}';
$plan = $runtime->compile(['wire_version' => 1, 'profile' => 'report-materialization-draft/1',
    'corpus_digest' => $digest, 'program' => $program]);
$request = ['plan_id' => $plan['plan_id'], 'batch' => batch_envelope([
    ['correlation' => 'reuse', 'input' => '{"fields":{"rows":[{"n":2},{"n":3}]},"lines":{}}'],
])];
$expected = json_decode('[{"n":3,"double_n":6,"next":7},{"n":2,"double_n":4,"next":5}]', false, 512, JSON_THROW_ON_ERROR);
foreach (['max_instructions' => 1, 'max_output_bytes' => 2] as $limit => $value) {
    $limited = $request;
    $limited['batch']['limits'][$limit] = $value;
    try {
        execute_formats($runtime, $limited);
        throw new RuntimeException('Report ignored ' . $limit);
    } catch (Kumwe\Engine\Exception\BindingFailure $failure) {
        if ($failure->getCode() !== 6) { throw $failure; }
    }
    $result = json_decode(execute_formats($runtime, $request)['results'][0]['result_json'], false, 512, JSON_THROW_ON_ERROR);
    if (!same_fixture_value($result->rows, $expected)) {
        throw new RuntimeException('Report declaration order, sorting or plan reuse failed.');
    }
}
$invalid = $request;
$invalid['batch']['documents'][0]['input'] = '{"fields":{"rows":[{"n":{"secret":"value"}}]},"lines":{}}';
try {
    execute_formats($runtime, $invalid);
    throw new RuntimeException('Structured report cell was accepted.');
} catch (Kumwe\Engine\Exception\BindingFailure $failure) {
    if ($failure->getCode() !== 1) { throw $failure; }
}
echo "report compile guards, formula ordering, sorting, budgets and reuse passed\n";
?>
--EXPECT--
report corpus:116:rows=54:runtime refusals=62
report compile guards, formula ordering, sorting, budgets and reuse passed
