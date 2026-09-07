--TEST--
Computed field normalization preserves all committed owner outputs including null and unknown-normalizer behavior
--EXTENSIONS--
kumwe_engine
--FILE--
<?php
require __DIR__ . '/common.inc';
$directory = __DIR__ . '/../vendor/engine/corpus/document/';
$corpus = json_decode(file_get_contents($directory . 'computed-normalization-v1.json'), false, 512, JSON_THROW_ON_ERROR);
$digest = hash_file('sha256', $directory . 'document-profile-v1.json');
$executed = 0;
foreach ($corpus->fixtures as $fixture) {
    $runtime = new Kumwe\Engine\Runtime();
    $plan = $runtime->compile(['wire_version' => 1, 'profile' => 'normalized-document-draft/1',
        'corpus_digest' => $digest, 'program' => json_encode(document_fixture_program($fixture), JSON_THROW_ON_ERROR)]);
    $lines = $fixture->owned_lines === [] ? new stdClass() : $fixture->owned_lines;
    $input = json_encode(['fields' => $fixture->normalized_values, 'lines' => $lines], JSON_THROW_ON_ERROR);
    $output = execute_formats($runtime, ['plan_id' => $plan['plan_id'], 'batch' => batch_envelope([
        ['correlation' => $fixture->id, 'input' => $input],
    ])]);
    $row = $output['results'][0];
    $result = json_decode($row['result_json'], false, 512, JSON_THROW_ON_ERROR);
    if ($row['correlation'] !== $fixture->id || !same_fixture_value($result, $fixture->expected)) {
        throw new RuntimeException('Computed normalization parity failed for ' . $fixture->id . ': ' . $row['result_json']);
    }
    ++$executed;
}
echo 'computed normalization parity:', $executed, "\n";
?>
--EXPECT--
computed normalization parity:86
