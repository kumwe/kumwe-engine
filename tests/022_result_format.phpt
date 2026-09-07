--TEST--
Explicit opaque compiled results preserve defaults, exact bytes, logical budgets and refusal recovery
--EXTENSIONS--
kumwe_engine
--FILE--
<?php
require __DIR__ . '/common.inc';
use Kumwe\Engine\Runtime;
use Kumwe\Engine\Exception\BindingFailure;
$runtime = new Runtime();
if (($runtime->capabilities()['binding_features'] ?? []) !== ['opaque-compiled-results/1']) {
    throw new RuntimeException('Missing binding-owned opaque capability.');
}
$plan = literal_plan($runtime, "a\0\"\\é\xe2\x80\xa8");
$request = ['plan_id' => $plan['plan_id'], 'batch' => batch_envelope([
    ['correlation' => 'one', 'input' => '{"fields":{},"lines":null}'],
    ['correlation' => 'two', 'input' => '{"fields":{},"lines":{}}'],
])];
$both = execute_formats($runtime, $request);
if ($runtime->execute($request + ['result_format' => 'both']) !== $both) {
    throw new RuntimeException('Explicit both changed the default.');
}
$opaque = $runtime->execute($request + ['result_format' => 'opaque']);
if (array_keys($opaque['results'][0]) !== ['correlation', 'findings', 'result_json']) {
    throw new RuntimeException('Opaque row shape changed.');
}
$direct = $request;
$direct['batch']['documents'] = [document('one'), document('two', [], [])];
if (execute_formats($runtime, $direct) !== $both) {
    throw new RuntimeException('Direct/framed result modes differ.');
}
$logical = strlen(json_encode($both, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
foreach ([$request, $direct] as $input) {
    foreach (['both', 'opaque'] as $format) {
        $input['result_format'] = $format;
        $input['batch']['limits']['max_output_bytes'] = $logical;
        if ($runtime->execute($input) !== ($format === 'both' ? $both : $opaque)) {
            throw new RuntimeException('Exact logical output boundary changed.');
        }
        --$input['batch']['limits']['max_output_bytes'];
        try { $runtime->execute($input); throw new RuntimeException('Short budget passed.'); }
        catch (BindingFailure $failure) { if ($failure->getCode() !== 6) { throw $failure; } }
    }
}
foreach ([null, true, false, 0, 1, [], new stdClass(), '', 'Opaque', 'BOTH', 'raw', "opaque\0"] as $format) {
    foreach ([false, true] as $cancelled) {
        try {
            $runtime->execute($request + ['result_format' => $format, 'cancelled' => $cancelled]);
            throw new RuntimeException('Invalid result format accepted.');
        } catch (BindingFailure $failure) { if ($failure->getCode() !== 1) { throw $failure; } }
    }
}
$format = 'opaque';
$reference = $request;
$reference['result_format'] = &$format;
try { $runtime->execute($reference); throw new RuntimeException('Referenced format accepted.'); }
catch (BindingFailure $failure) { if ($failure->getCode() !== 1) { throw $failure; } }
foreach (['both', 'opaque'] as $format) {
    try { $runtime->execute($request + ['result_format' => $format, 'cancelled' => true]); throw new RuntimeException('Cancellation ignored.'); }
    catch (BindingFailure $failure) { if ($failure->getCode() !== 7) { throw $failure; } }
    $foreign = new Runtime();
    try { $foreign->execute($request + ['result_format' => $format]); throw new RuntimeException('Foreign plan accepted.'); }
    catch (BindingFailure $failure) { if ($failure->getCode() !== 1) { throw $failure; } }
}
$empty = $request; $empty['batch']['documents'] = [];
if (execute_formats($runtime, $empty) !== ['results' => [], 'wire_version' => 1]) {
    throw new RuntimeException('Empty opaque batch changed.');
}
$canonical = ['wire_version' => 1, 'profile' => 'kumwe-canonical-json/generic-v1',
    'corpus_digest' => hash_file('sha256', __DIR__ . '/../vendor/engine/corpus/canonical/generic-v1.json'),
    'operation' => 'encode', 'input' => [], 'result_format' => 'opaque'];
try { $runtime->execute($canonical); throw new RuntimeException('Canonical accepted compiled-only option.'); }
catch (BindingFailure $failure) { if ($failure->getCode() !== 1) { throw $failure; } }
$decimal = ['wire_version' => 1, 'profile' => 'decimal-batch-draft/1',
    'corpus_digest' => hash_file('sha256', __DIR__ . '/../vendor/engine/corpus/decimal/decimal-v1.tsv'),
    'input' => 'KED1' . pack('VVP', 1, 1048576, 1000000) . pack('C6v2', 0, 1, 0, 0, 0, 0, 1, 0) . '0'];
if ($runtime->execute($decimal)['result'] !== 'KER1' . pack('VV', 1, 1) . '0') {
    throw new RuntimeException('Decimal control request failed.');
}
try { $runtime->execute($decimal + ['result_format' => 'opaque']); throw new RuntimeException('Decimal accepted compiled-only option.'); }
catch (BindingFailure $failure) { if ($failure->getCode() !== 1) { throw $failure; } }
if (execute_formats($runtime, $request) !== $both) { throw new RuntimeException('Refusal poisoned plan.'); }
$runtime->release($plan['plan_id']);
echo "Default/both/opaque shapes, exact bytes, logical budgets and strict refusals passed.\n";
?>
--EXPECT--
Default/both/opaque shapes, exact bytes, logical budgets and strict refusals passed.
