--TEST--
A normalized document executes through the same owned ABI plan
--EXTENSIONS--
kumwe_engine
--FILE--
<?php
require __DIR__ . '/common.inc';
$r = new Kumwe\Engine\Runtime();
$p = $r->compile(['wire_version' => 1, 'profile' => 'normalized-document-draft/1',
    'corpus_digest' => hash_file('sha256', __DIR__ . '/../vendor/engine/corpus/document/document-profile-v1.json'),
    'program' => ['fields' => [['handle' => 'amount', 'required' => true, 'nullable' => false]], 'invariants' => []]]);
$out = $r->execute(['plan_id' => $p['plan_id'], 'batch' => batch_envelope([document(fields: ['amount' => '0.00'])])]);
var_dump($out['results'][0]['result']['values']['amount'], $out['results'][0]['result']['findings']);
?>
--EXPECT--
string(4) "0.00"
array(0) {
}
