--TEST--
Committed create/update preparation vectors execute through the actual native PHP binding
--EXTENSIONS--
kumwe_engine
--FILE--
<?php
require __DIR__ . '/common.inc';
$path = __DIR__ . '/../vendor/engine/corpus/document/preparation-v1.json';
$corpus = json_decode(file_get_contents($path), false, 512, JSON_THROW_ON_ERROR);
$runtime = new Kumwe\Engine\Runtime();
$count = 0;
foreach ($corpus->fixtures as $fixture) {
    $plan = $runtime->compile([
        'wire_version' => 1,
        'profile' => 'normalized-preparation-draft/1',
        'corpus_digest' => hash_file('sha256', $path),
        'program' => json_encode($fixture->program, JSON_THROW_ON_ERROR),
    ]);
    // Opaque input preserves the owner's empty object/list distinctions.
    $input = json_encode(['fields' => $fixture->input, 'lines' => $fixture->lines], JSON_THROW_ON_ERROR);
    $output = $runtime->execute(['plan_id' => $plan['plan_id'], 'batch' => batch_envelope([
        ['correlation' => $fixture->id, 'input' => $input],
    ])]);
    $row = $output['results'][0];
    $result = json_decode($row['result_json'], false, 512, JSON_THROW_ON_ERROR);
    if ($row['correlation'] !== $fixture->id || !same_fixture_value($result->findings, $fixture->expected->findings)
        || ($fixture->expected->returns_values && !same_fixture_value($result->values, $fixture->expected->values))) {
        throw new RuntimeException('Preparation parity failed for ' . $fixture->id);
    }
    if ($fixture->id === 'create_defaults_and_codec') {
        echo 'create:', $result->values->name, ':', $result->values->server, "\n";
    }
    if ($fixture->id === 'update_condition_uses_prior_not_patch') {
        echo 'update:', $result->values->name, ':', $result->values->enabled ? 'enabled' : 'disabled', "\n";
    }
    ++$count;
}
echo 'preparation vectors:', $count, "\n";
?>
--EXPECT--
create:default:system
update:after:disabled
preparation vectors:43
