<?php
/** Deterministic admission and real-process concurrency tests; no performance claims. */
declare(strict_types=1);

require __DIR__ . '/benchmark-runtime.php';

use Kumwe\Engine\Benchmark\Worker;
use function Kumwe\Engine\Benchmark\{close_workers, concurrent, distribution, job, matrix_jobs,
    parse_arguments, regression, require_comparable_metadata, require_parity};

function check(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function refused(callable $action, string $label): void
{
    try { $action(); } catch (RuntimeException | InvalidArgumentException $expected) { return; }
    throw new RuntimeException('Expected refusal: ' . $label);
}

if (($argv[1] ?? null) === '--fixture-worker') {
    $mode = $argv[2] ?? 'normal';
    echo "{\"ready\":true,\"sources\":[],\"backend\":\"php\"}\n";
    while (($line = fgets(STDIN)) !== false) {
        $request = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        if (($request['command'] ?? null) === 'stop') { break; }
        if ($mode === 'eof') { exit(0); }
        if ($mode === 'malformed') { echo "invalid-json\n"; continue; }
        if (($request['phase'] ?? '') === 'barrier') {
            $directory = $argv[3]; $marker = $argv[4];
            file_put_contents($directory . '/' . $marker, 'ready');
            $limit = hrtime(true) + 2_000_000_000;
            while (count(glob($directory . '/ready-*')) < 2) {
                if (hrtime(true) >= $limit) { echo "{\"ok\":false,\"error\":\"requests were serialized\"}\n"; continue 2; }
                usleep(1000);
            }
        }
        $iterations = $request['iterations'] ?? 1;
        $digest = $mode === 'wrong' ? 'changed' : 'same';
        echo json_encode(['ok' => true, 'digest' => $digest,
            'durations_ns' => array_fill(0, $iterations, 100), 'compile_durations_ns' => [],
            'peak_rss_bytes' => 4096, 'dataset_descriptor_sha256' => 'fixed'], JSON_THROW_ON_ERROR) . "\n";
    }
    exit(0);
}

require_parity(['ok' => true, 'digest' => 'same'], 'same', 'same');
foreach ([['ok' => true, 'digest' => 'changed'], ['ok' => false, 'error' => 'refused'], ['ok' => true]] as $value) {
    refused(static fn () => require_parity($value, 'same', 'deliberate fault'), 'incorrect worker result');
}
check(regression(array_fill(0, 19, 100), array_fill(0, 30, 200), .1)['evaluated'] === false, 'sample admission');
check(regression(array_fill(0, 30, 100), array_fill(0, 30, 200), .1)['regression'] === true, 'regression detection');
check(regression(array_fill(0, 30, 100), array_fill(0, 30, 100), .1)['regression'] === false, 'equal samples');
check(regression(array_fill(0, 30, 100), array_fill(0, 30, 50), .1)['regression'] === false, 'improvement');
check(regression(range(1, 100), range(2, 101), .1)['regression'] === false, 'overlapping samples');
check(regression(range(1, 30), range(2, 31), .1) === regression(range(1, 30), range(2, 31), .1), 'deterministic bootstrap');
$distribution = distribution(range(1, 100));
check([$distribution['p50'], $distribution['p95'], $distribution['p99']] === [50, 95, 99], 'tail percentile ranks');
check(distribution([]) === null, 'empty distribution');
$metadata = ['machine' => [], 'php_sha256' => 'php', 'corpus_hashes' => ['corpus' => 'fixed'],
    'harness_hashes' => ['worker.php' => 'workload'], 'worker_identity' => [
        'php' => array_fill_keys(['php', 'php_binary_sha256', 'icu', 'sources', 'conversion_reference', 'memory_limit'], 'fixed'),
        'native' => ['capabilities' => ['binding_build' => array_fill_keys(['architecture', 'compiler', 'engine_flags',
            'extension_flags', 'libc', 'sanitizers', 'thread_model', 'zend_module_api', 'debug'], 'fixed')]]]];
require_comparable_metadata($metadata, $metadata);
$altered = $metadata; $altered['harness_hashes']['worker.php'] = 'changed';
refused(static fn () => require_comparable_metadata($metadata, $altered), 'changed workload');
$altered = $metadata; $altered['worker_identity']['php']['memory_limit'] = 'changed';
refused(static fn () => require_comparable_metadata($metadata, $altered), 'changed semantic baseline');
foreach (['engine_flags', 'compiler', 'sanitizers'] as $key) {
    $altered = $metadata; $altered['worker_identity']['native']['capabilities']['binding_build'][$key] = 'changed';
    refused(static fn () => require_comparable_metadata($metadata, $altered), 'changed native build');
}
$defaults = ['profiles' => Kumwe\Engine\Benchmark\PROFILES, 'sizes' => [1, 32, 256, 4096]];
$jobs = iterator_to_array(matrix_jobs($defaults));
check(count($jobs) * 2 === 176, 'full default matrix case count');
$keys = [];
foreach ($jobs as $request) {
    $keys[] = implode('/', [$request['profile'], $request['size'], $request['shape'], $request['phase']]);
}
check(count(array_unique($keys)) === 88, 'unique matrix workloads');
check(in_array('canonical/4096/hostile/digest', $keys, true), 'wide hostile digest workload');
check(in_array('preparation/4096/valid/cold', $keys, true), 'wide cold preparation workload');
check(count(Kumwe\Engine\Benchmark\PROFILES) * 2 * 4 === 48, 'capacity case count');
check(count(Kumwe\Engine\Benchmark\PROFILES) * 2 === 12, 'allocation case count');
$temp = sys_get_temp_dir() . '/kumwe-benchmark-test-' . bin2hex(random_bytes(8));
if (!mkdir($temp, 0700)) { throw new RuntimeException('Cannot create test directory'); }
$workers = [];
try {
    $arguments = ['benchmark-runtime.php', '--php', PHP_BINARY, '--extension', __FILE__, '--app', __DIR__,
        '--sdk', __DIR__, '--autoload', __FILE__, '--engine', __DIR__, '--output', $temp . '/out'];
    $parsed = parse_arguments($arguments);
    check($parsed['sizes'] === [1, 32, 256, 4096] && $parsed['workers'] === [1, 2, 4, 8], 'default full coverage');
    $smoke = parse_arguments([...$arguments, '--smoke']);
    check($smoke['samples'] === 3 && $smoke['sizes'] === [1, 32] && $smoke['workers'] === [1, 2], 'smoke bounds');
    refused(static fn () => parse_arguments([...$arguments, '--samples', '0']), 'zero samples');
    refused(static fn () => parse_arguments([...$arguments, '--sizes', '1', '4097']), 'oversized workload');
    refused(static fn () => parse_arguments([...$arguments, '--demand', 'unknown=10']), 'unknown demand');
    refused(static fn () => parse_arguments([...$arguments, '--workers', '1', '1']), 'duplicate worker counts');
    $args = ['output' => $temp, 'php' => PHP_BINARY, 'app' => __DIR__, 'sdk' => __DIR__,
        'autoload' => __FILE__, 'engine' => __DIR__, 'extension' => __FILE__];
    for ($i = 0; $i < 2; ++$i) {
        $workers[] = new Worker($args, 'php', 'fixture-' . $i, [],
            [PHP_BINARY, __FILE__, '--fixture-worker', 'normal', $temp, 'ready-' . $i]);
    }
    $burst = concurrent($workers, job('decimal', 1, phase: 'barrier'), 'same', 4, null);
    check(count($burst['raw']) === 4 && $burst['wall_ns'] > 0, 'concurrent burst processes complete');
    foreach ($burst['raw'] as $observation) {
        check($observation['completion_ns'] >= $observation['queue_ns'] && $observation['service_ns'] === 100,
            'burst preserves queue, completion and worker service measurements');
    }
    $saturated = concurrent($workers, job('decimal', 1), 'same', null, .02);
    check(count($saturated['raw']) >= 2 && $saturated['wall_ns'] > 0, 'closed-loop workers complete');
    close_workers($workers); $workers = [];
    $worker = new Worker($args, 'php', 'wrong', [], [PHP_BINARY, __FILE__, '--fixture-worker', 'wrong']);
    $workers[] = $worker;
    refused(static fn () => concurrent([$worker], job('decimal', 1), 'same', 1, null), 'capacity parity mismatch');
    close_workers($workers); $workers = [];
    $worker = new Worker($args, 'php', 'eof', [], [PHP_BINARY, __FILE__, '--fixture-worker', 'eof']);
    $workers[] = $worker;
    refused(static fn () => $worker->request(job('decimal', 1)), 'worker EOF');
    close_workers($workers); $workers = [];
    echo "Benchmark admission, coverage and concurrent PHP worker tests passed.\n";
} finally {
    close_workers($workers);
    foreach (glob($temp . '/*') as $path) { unlink($path); }
    rmdir($temp);
}
