--TEST--
Opaque batch frames preserve public arrays, exact logical output limits and atomic refusal
--EXTENSIONS--
kumwe_engine
--FILE--
<?php
use Kumwe\Engine\Runtime;
use Kumwe\Engine\Exception\BindingFailure;
$r = new Runtime();
$p = $r->compile(['wire_version' => 1, 'profile' => 'formula-draft/1',
    'corpus_digest' => '11033679b018fdc9a192e954ef11089444a00a1d89c6279d3c192be9252cf42f',
    'program' => ['op' => 'field', 'type' => 'integer', 'field' => 'amount']]);
$limits = ['max_input_bytes' => 1048576, 'max_output_bytes' => 1048576,
    'max_documents' => 64, 'max_findings' => 64, 'max_instructions' => 100000, 'max_milliseconds' => 30000];
$direct = ['wire_version' => 1, 'documents' => [
    ['correlation' => 'one', 'fields' => ['amount' => 7], 'lines' => []],
    ['correlation' => 'two', 'fields' => ['amount' => PHP_INT_MIN], 'lines' => []]], 'limits' => $limits];
$opaque = ['wire_version' => 1, 'documents' => [], 'limits' => $limits];
foreach ($direct['documents'] as $doc) {
    $opaque['documents'][] = ['correlation' => $doc['correlation'], 'input' => json_encode(['fields' => $doc['fields'], 'lines' => (object)[]], JSON_THROW_ON_ERROR)];
}
$execute = static fn(array $batch): array => $r->execute(['plan_id' => $p['plan_id'], 'batch' => $batch]);
$expected = $execute($direct);
var_dump($execute($opaque) === $expected);
var_dump(array_keys($expected), array_keys($expected['results'][0]));
$logicalBytes = strlen(json_encode($expected, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
$opaque['limits']['max_output_bytes'] = $logicalBytes;
var_dump($execute($opaque) === $expected);
$opaque['limits']['max_output_bytes'] = $logicalBytes - 1;
try { $execute($opaque); } catch (BindingFailure $e) { echo 'short:', $e->getCode(), "\n"; }
$opaque['limits'] = $limits;
foreach (["\xff", '{broken', '{"fields":[],"lines":null}'] as $bad) {
    $malformed = $opaque; $malformed['documents'][0]['input'] = $bad;
    try { $execute($malformed); } catch (BindingFailure $e) { echo 'bad:', $e->getCode(), "\n"; }
}
$empty = ['wire_version' => 1, 'documents' => [], 'limits' => $limits];
var_dump($execute($empty));
var_dump($execute($opaque) === $expected);
$r->release($p['plan_id']);
?>
--EXPECT--
bool(true)
array(2) {
  [0]=>
  string(7) "results"
  [1]=>
  string(12) "wire_version"
}
array(4) {
  [0]=>
  string(11) "correlation"
  [1]=>
  string(8) "findings"
  [2]=>
  string(6) "result"
  [3]=>
  string(11) "result_json"
}
bool(true)
short:6
bad:1
bad:1
bad:1
array(2) {
  ["results"]=>
  array(0) {
  }
  ["wire_version"]=>
  int(1)
}
bool(true)
