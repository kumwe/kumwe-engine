--TEST--
Every frozen GenericV1 corpus vector crosses the actual Zend boundary
--EXTENSIONS--
kumwe_engine
--INI--
memory_limit=512M
--FILE--
<?php
// Fixture reconstruction only; canonical algorithms and expected bytes come from Engine's corpus.
function fixture(array $tag): mixed {
    switch ($tag['type']) {
        case 'null': return null;
        case 'bool': return $tag['value'];
        case 'int': return (int) $tag['decimal'];
        case 'float': return unpack('Evalue', hex2bin($tag['hex']))['value'];
        case 'string': return base64_decode($tag['base64'], true);
        case 'unsupported': return new stdClass();
        case 'nested-list':
            $value = fixture($tag['leaf']);
            for ($i = 0; $i < $tag['depth']; ++$i) { $value = [$value]; }
            return $value;
        case 'repeat-list': return array_fill(0, $tag['count'], fixture($tag['value']));
        case 'repeat-string': return str_repeat(base64_decode($tag['base64'], true), $tag['count']);
        case 'array':
            $value = [];
            foreach ($tag['entries'] as $entry) {
                $key = $entry['key']['type'] === 'int' ? (int) $entry['key']['decimal'] : base64_decode($entry['key']['base64'], true);
                $value[$key] = fixture($entry['value']);
            }
            return $value;
    }
    throw new RuntimeException('Unknown fixture type.');
}
$path = __DIR__ . '/../vendor/engine/corpus/canonical/generic-v1.json';
$corpus = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$runtime = new Kumwe\Engine\Runtime(); $operations = 0;
foreach ($corpus['cases'] as $case) {
    foreach (['encode' => 'output', 'digest' => 'sha256'] as $operation => $key) {
        $request = ['wire_version' => 1, 'profile' => $corpus['profile'], 'corpus_digest' => hash_file('sha256', $path),
            'operation' => $operation, 'input' => fixture($case['input'])];
        if (isset($case['limits'])) { $request['limits'] = $case['limits']; }
        $expected = isset($case['expected']['finding']) ? ['finding' => $case['expected']['finding']] : [$key => $case['expected'][$key]];
        $actual = $runtime->execute($request);
        if ($actual !== $expected) { throw new RuntimeException($case['id'] . '/' . $operation . ': ' . json_encode($actual)); }
        ++$operations;
    }
}
echo count($corpus['cases']), " corpus cases; ", $operations, " native operations match\n";
?>
--EXPECT--
79 corpus cases; 158 native operations match
