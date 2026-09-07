--TEST--
Public formula compilation and execution replay every frozen valid and invalid owner vector
--EXTENSIONS--
kumwe_engine
--FILE--
<?php
require __DIR__ . '/common.inc';
$path = __DIR__ . '/../vendor/engine/corpus/definition/formula-v1.json';
$corpus = json_decode(file_get_contents($path), false, 512, JSON_THROW_ON_ERROR);
$digest = hash_file('sha256', $path);
$counts = ['value' => 0, 'parse' => 0, 'evaluate' => 0];
foreach ($corpus->vectors as $fixture) {
    // One owner per fixture keeps the public 64-plan lifetime bound meaningful.
    $runtime = new Kumwe\Engine\Runtime();
    $expectedPhase = $fixture->expected->phase ?? null;
    $envelope = ['wire_version' => 1, 'profile' => 'formula-draft/1', 'corpus_digest' => $digest,
        // The opaque path preserves {} and float kind, including integral floats.
        'program' => json_encode($fixture->expression, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)];
    try {
        $plan = $runtime->compile($envelope);
    } catch (Kumwe\Engine\Exception\BindingFailure $failure) {
        // The public ABI maps parser errors to INVALID_PROGRAM, without exposing owner messages.
        if ($expectedPhase !== 'parse' || $fixture->expected->refusal !== 'invalid_ast' || $failure->getCode() !== 5) {
            throw new RuntimeException($fixture->id . ': unexpected compile refusal ' . $failure->getCode(), 0, $failure);
        }
        ++$counts['parse'];
        continue;
    }
    if ($expectedPhase === 'parse') { throw new RuntimeException($fixture->id . ': invalid AST compiled'); }
    $descriptor = $plan['descriptor'];
    $canonical = json_decode(json_encode($descriptor['program'], JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    if ($descriptor['wire_version'] !== 1 || $descriptor['profile'] !== 'formula-draft/1'
        || $descriptor['corpus_digest'] !== $digest || !same_fixture_value($canonical, $fixture->canonical)) {
        throw new RuntimeException($fixture->id . ': canonical AST or descriptor mismatch');
    }
    $input = json_encode(['fields' => $fixture->fields, 'lines' => $fixture->lines],
        JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    $request = ['plan_id' => $plan['plan_id'], 'batch' => batch_envelope([
        ['correlation' => $fixture->id, 'input' => $input],
    ])];
    try {
        $output = execute_formats($runtime, $request);
    } catch (Kumwe\Engine\Exception\BindingFailure $failure) {
        // Execution errors cross the same ABI as INVALID_INPUT; phase is checked separately.
        if ($expectedPhase !== 'evaluate' || $fixture->expected->refusal !== 'evaluation_refused' || $failure->getCode() !== 1) {
            throw new RuntimeException($fixture->id . ': unexpected execution refusal ' . $failure->getCode(), 0, $failure);
        }
        ++$counts['evaluate'];
        continue;
    }
    if ($expectedPhase !== null) { throw new RuntimeException($fixture->id . ': refused formula executed'); }
    if ($output['wire_version'] !== 1 || count($output['results']) !== 1) {
        throw new RuntimeException($fixture->id . ': batch framing mismatch');
    }
    $row = $output['results'][0];
    $result = json_decode($row['result_json'], false, 512, JSON_THROW_ON_ERROR);
    if ($row['correlation'] !== $fixture->id || $row['findings'] !== [] || $result->findings !== []
        || !same_fixture_value($result->value, $fixture->expected->value)) {
        throw new RuntimeException($fixture->id . ': formula result mismatch: ' . $row['result_json']);
    }
    ++$counts['value'];
}
if ($counts !== ['value' => 59, 'parse' => 17, 'evaluate' => 25]) {
    throw new RuntimeException('The complete 101-vector formula corpus was not replayed.');
}
echo 'formula corpus:101:values=', $counts['value'], ':compile refusals=', $counts['parse'],
    ':runtime refusals=', $counts['evaluate'], "\n";

$runtime = new Kumwe\Engine\Runtime();
$plan = $runtime->compile(formula_envelope(['op' => 'multiply', 'type' => 'integer', 'args' => [
    ['op' => 'literal', 'type' => 'integer', 'value' => 2],
    ['op' => 'literal', 'type' => 'integer', 'value' => 3],
]]));
$request = ['plan_id' => $plan['plan_id'], 'batch' => batch_envelope([document()])];
$limited = $request;
$limited['batch']['limits']['max_instructions'] = 1;
try {
    execute_formats($runtime, $limited);
    throw new RuntimeException('Formula work budget was ignored.');
} catch (Kumwe\Engine\Exception\BindingFailure $failure) {
    if ($failure->getCode() !== 6) { throw $failure; }
}
if (execute_formats($runtime, $request)['results'][0]['result']['value'] !== 6) {
    throw new RuntimeException('Formula plan changed after budget refusal.');
}
echo "formula work budget and plan reuse passed\n";
?>
--EXPECT--
formula corpus:101:values=59:compile refusals=17:runtime refusals=25
formula work budget and plan reuse passed
