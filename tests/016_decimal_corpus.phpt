--TEST--
Public native decimal dispatch replays every owner vector and preserves binary framing, atomic refusal and budgets
--EXTENSIONS--
kumwe_engine
--FILE--
<?php
$runtime = new Kumwe\Engine\Runtime();
$corpusPath = __DIR__ . '/../vendor/engine/corpus/decimal/decimal-v1.tsv';
$base = ['wire_version' => 1, 'profile' => 'decimal-batch-draft/1',
    'corpus_digest' => hash_file('sha256', $corpusPath)];
function decimal_request(array $rows, int $outputLimit = 1048576, int $budget = 1000000000): string
{
    return 'KED1' . pack('VVP', count($rows), $outputLimit, $budget) . implode('', $rows);
}
function decimal_result(array $rows): string
{
    $result = 'KER1' . pack('V', count($rows));
    foreach ($rows as $row) { $result .= pack('V', strlen($row)) . $row; }
    return $result;
}
function decimal_metadata(string $literal): array
{
    // Corpus transport metadata, identical to the installed native corpus runner.
    // No expected decimal value or arithmetic is computed by this PHP test.
    if (str_starts_with($literal, '-')) { $literal = substr($literal, 1); }
    $parts = explode('.', $literal, 2);
    $scale = isset($parts[1]) ? strlen($parts[1]) : 0;
    return [max(1, ($parts[0] === '0' ? 0 : strlen($parts[0])) + $scale), $scale];
}
function decimal_row(array $columns): string
{
    $operations = ['parse' => 0, 'compare' => 1, 'multiply' => 2, 'round' => 3];
    $rounding = ['half_up' => 0, 'half_down' => 1, 'half_even' => 2,
        'ceiling' => 3, 'floor' => 4, 'truncate' => 5];
    $left = $columns[2] === '-' ? '' : hex2bin($columns[2]);
    $right = $columns[3] === '-' ? '' : hex2bin($columns[3]);
    if (!is_string($left) || !is_string($right) || !isset($operations[$columns[1]])) {
        throw new RuntimeException('Invalid frozen decimal fixture');
    }
    $operation = $operations[$columns[1]];
    [$precision, $scale] = decimal_metadata($left);
    [$targetPrecision, $targetScale] = decimal_metadata($right);
    $mode = 0;
    $number = static fn(string $value): int => $value === '-' ? 0 : ((int) $value < 0 ? 255 : (int) $value);
    if ($operation === 0) {
        $precision = $number($columns[4]); $scale = $number($columns[5]);
        $targetPrecision = 0; $targetScale = 0;
    }
    if ($operation === 3) {
        $targetPrecision = $number($columns[4]); $targetScale = $number($columns[5]);
        $mode = $rounding[$columns[6]];
    }
    return pack('C6v2', $operation, $precision, $scale, $targetPrecision, $targetScale,
        $mode, strlen($left), strlen($right)) . $left . $right;
}
function decimal_success(Kumwe\Engine\Runtime $runtime, array $envelope, string $expected): void
{
    $actual = $runtime->execute($envelope);
    if ($actual !== ['wire_version' => 1, 'profile' => 'decimal-batch-draft/1', 'result' => $expected]) {
        throw new RuntimeException('Native decimal bytes or result envelope differ');
    }
}
function decimal_refusal(Kumwe\Engine\Runtime $runtime, array $envelope, int $status): void
{
    try { $runtime->execute($envelope); }
    catch (Kumwe\Engine\Exception\BindingFailure $failure) {
        if ($failure->getCode() !== $status) {
            throw new RuntimeException('Decimal refusal status ' . $failure->getCode() . ' differs from ' . $status);
        }
        return;
    }
    throw new RuntimeException('Invalid decimal input returned a partial result');
}
$rows = file($corpusPath, FILE_IGNORE_NEW_LINES);
if (array_shift($rows) !== "id\toperation\tleft_hex\tright_hex\tprecision\tscale\trounding\toutcome\texpected_hex") {
    throw new RuntimeException('Unexpected decimal corpus schema');
}
$count = 0; $successfulRows = []; $expectedRows = []; $covered = [];
foreach ($rows as $line) {
    if ($line === '') { continue; }
    $columns = explode("\t", $line);
    if (count($columns) !== 9) { throw new RuntimeException('Unexpected decimal corpus row'); }
    $row = decimal_row($columns);
    $request = $base + ['input' => decimal_request([$row])];
    if ($columns[7] === 'invalid_argument') {
        decimal_refusal($runtime, $request, 1);
    } elseif ($columns[7] === 'value') {
        $expected = hex2bin($columns[8]);
        if (!is_string($expected)) { throw new RuntimeException('Invalid expected corpus bytes'); }
        decimal_success($runtime, $request, decimal_result([$expected]));
        $successfulRows[] = $row; $expectedRows[] = $expected;
    } else { throw new RuntimeException('Unexpected decimal corpus outcome'); }
    $covered[$columns[1]] = true;
    ++$count;
}
if ($count !== 108 || count($covered) !== 4) { throw new RuntimeException('Incomplete decimal corpus replay'); }
decimal_success($runtime, $base + ['input' => decimal_request($successfulRows)], decimal_result($expectedRows));
echo 'decimal corpus parity:', $count, ":four operations\n";
echo "ordered successful corpus batch passed\n";

$validRow = pack('C6v2', 0, 3, 2, 0, 0, 0, 3, 0) . '1.2';
$valid = $base + ['input' => decimal_request([$validRow])];
$invalidRow = pack('C6v2', 0, 2, 0, 0, 0, 0, 2, 0) . '+1';
$cases = [
    [array_replace($valid, ['wire_version' => 2]), 2],
    [array_replace($valid, ['corpus_digest' => str_repeat('0', 64)]), 4],
    [$valid + ['unexpected' => true], 1],
    [array_replace($valid, ['input' => []]), 1],
    [array_replace($valid, ['input' => 'KED1']), 1],
    [array_replace($valid, ['input' => 'KED2' . substr($valid['input'], 4)]), 2],
    [array_replace($valid, ['input' => str_repeat('x', 1048577)]), 6],
    [array_replace($valid, ['input' => decimal_request([])]), 6],
    [array_replace($valid, ['input' => 'KED1' . pack('VVP', 4097, 1048576, 1000000000)]), 6],
    [array_replace($valid, ['input' => decimal_request([$validRow], 7)]), 6],
    [array_replace($valid, ['input' => decimal_request([$validRow], 1048576, 511)]), 6],
    [array_replace($valid, ['input' => $valid['input'] . 'trailing']), 1],
    [array_replace($valid, ['input' => decimal_request([$validRow, $invalidRow])]), 1],
];
foreach ($cases as [$envelope, $status]) { decimal_refusal($runtime, $envelope, $status); }
$inputReference = $valid['input']; $referenced = $base; $referenced['input'] = &$inputReference;
decimal_refusal($runtime, $referenced, 1);
$callbackRan = false;
$hostile = new class($callbackRan) implements JsonSerializable {
    public function __construct(private bool &$called) {}
    public function jsonSerialize(): mixed { $this->called = true; return 'KED1'; }
};
decimal_refusal($runtime, array_replace($valid, ['input' => $hostile]), 1);
if ($callbackRan) { throw new RuntimeException('Decimal boundary executed a caller callback'); }
decimal_success($runtime, $valid, decimal_result(['1.20']));
echo "metadata, binary framing, budgets, atomic refusal and callback-free reuse passed\n";
?>
--EXPECT--
decimal corpus parity:108:four operations
ordered successful corpus batch passed
metadata, binary framing, budgets, atomic refusal and callback-free reuse passed
