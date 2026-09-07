--TEST--
Committed document, normalized-value and validator corpora execute through the actual bundled runtime
--EXTENSIONS--
kumwe_engine
--FILE--
<?php
require __DIR__ . '/common.inc';
if (same_fixture_value((object) ['x' => 1], (object) ['x' => '1'])
    || same_fixture_value(new stdClass(), []) || same_fixture_value([1, 2], [2, 1])) {
    throw new RuntimeException('Fixture comparison lost scalar, object or list identity.');
}
$directory = __DIR__ . '/../vendor/engine/corpus/document/';
$digest = hash_file('sha256', $directory . 'document-profile-v1.json');
foreach (['validation-v1.json', 'normalized-values-v1.json',
    'validator-extension-v1.json', 'validator-edges-v1.json'] as $name) {
    $corpus = json_decode(file_get_contents($directory . $name), false, 512, JSON_THROW_ON_ERROR);
    $count = 0;
    foreach ($corpus->fixtures as $fixture) {
        // Each request owns a bounded native plan table; no global table accumulates corpus plans.
        $runtime = new Kumwe\Engine\Runtime();
        $plan = $runtime->compile(['wire_version' => 1, 'profile' => 'normalized-document-draft/1',
            'corpus_digest' => $digest,
            'program' => json_encode(document_fixture_program($fixture), JSON_THROW_ON_ERROR)]);
        $lines = $fixture->owned_lines === [] ? new stdClass() : $fixture->owned_lines;
        $input = json_encode(['fields' => $fixture->normalized_values, 'lines' => $lines], JSON_THROW_ON_ERROR);
        $output = execute_formats($runtime, ['plan_id' => $plan['plan_id'], 'batch' => batch_envelope([
            ['correlation' => $fixture->id, 'input' => $input],
        ])]);
        $row = $output['results'][0];
        $result = json_decode($row['result_json'], false, 512, JSON_THROW_ON_ERROR);
        if ($row['correlation'] !== $fixture->id || !same_fixture_value($result, $fixture->expected)) {
            throw new RuntimeException('Validator parity failed for ' . $fixture->id . ': ' . $row['result_json']);
        }
        ++$count;
    }
    echo $name, ':', $count, "\n";
}
?>
--EXPECT--
validation-v1.json:10
normalized-values-v1.json:30
validator-extension-v1.json:26
validator-edges-v1.json:128
