--TEST--
Native document validators accept Unicode patterns and tagged exact decimals without host callbacks
--EXTENSIONS--
kumwe_engine
--FILE--
<?php
require __DIR__ . '/common.inc';
$runtime = new Kumwe\Engine\Runtime();
$definitions = [
    ['handle' => 'email', 'validators' => [['rule' => 'email']]],
    ['handle' => 'url', 'validators' => [['rule' => 'url']]],
    ['handle' => 'uuid', 'validators' => [['rule' => 'uuid']]],
    ['handle' => 'label', 'validators' => [
        ['rule' => 'pattern', 'value' => '^[\p{L}]+-[0-9]{2}$'],
        ['rule' => 'min_length', 'value' => 5], ['rule' => 'max_length', 'value' => 20],
    ]],
    ['handle' => 'amount', 'type' => 'core.decimal', 'precision' => 6, 'scale' => 2,
        'validators' => [['rule' => 'decimal'], ['rule' => 'min', 'value' => '10.00'],
            ['rule' => 'max', 'value' => '15.00']]],
];
$plan = $runtime->compile(['wire_version' => 1, 'profile' => 'normalized-document-draft/1',
    'corpus_digest' => hash_file('sha256', __DIR__ . '/../vendor/engine/corpus/document/document-profile-v1.json'),
    'program' => ['fields' => $definitions, 'invariants' => []]]);
$valid = ['email' => 'reader@example.com', 'url' => 'https://example.com/report?a=1',
    'uuid' => '018f4f24-98d8-7ad4-8f3f-38c909178b6b', 'label' => 'Ångström-42',
    'amount' => ['type' => 'exact-decimal', 'value' => '12.30']];
$invalid = ['email' => 'reader@', 'url' => '/relative', 'uuid' => 'not-a-uuid',
    'label' => 'lower 42', 'amount' => '12.30'];
$output = $runtime->execute(['plan_id' => $plan['plan_id'], 'batch' => batch_envelope([
    document('valid', $valid), document('invalid', $invalid), document('reused', $valid),
])]);
echo 'valid:', count($output['results'][0]['findings']), ':', $output['results'][0]['result']['values']['amount'], "\n";
echo implode(',', array_column($output['results'][1]['result']['findings'], 'code')), "\n";
echo 'reused:', count($output['results'][2]['findings']), "\n";
?>
--EXPECT--
valid:0:12.30
email,url,uuid,pattern,decimal
reused:0
