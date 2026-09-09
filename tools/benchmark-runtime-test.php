<?php
/** Deterministic admission and real-process concurrency tests; no performance claims. */
declare(strict_types=1);

require __DIR__ . '/benchmark-runtime.php';

use Kumwe\Engine\Benchmark\Worker;
use function Kumwe\Engine\Benchmark\{close_workers, concurrent, distribution, job, matrix_jobs,
    parse_arguments, regression, require_comparable_metadata, require_parity, worker_process, command, sha};

function check(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function remove_test_tree(string $path): void
{
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) as $name) {
            if ($name !== '.' && $name !== '..') { remove_test_tree($path . '/' . $name); }
        }
        rmdir($path);
    } else { unlink($path); }
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
        if ($mode === 'eof' || $mode === 'eof-error') {
            // Expose EOF while the process is still alive, deterministically
            // exercising shutdown's closed-pipe / not-yet-reaped race.
            fclose(STDIN); fclose(STDOUT); usleep(50000);
            exit($mode === 'eof' ? 0 : 7);
        }
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
    $worker = new Worker($args, 'php', 'eof-error', [], [PHP_BINARY, __FILE__, '--fixture-worker', 'eof-error']);
    $workers[] = $worker;
    refused(static fn () => $worker->request(job('decimal', 1)), 'worker EOF with failing exit');
    try {
        $worker->close();
        throw new LogicException('Nonzero worker exit was hidden by cleanup');
    } catch (RuntimeException $failure) {
        check($failure->getMessage() === 'eof-error: exit 7', 'cleanup preserves actual nonzero worker status');
    }
    $workers = [];
    // Exercise the actual captured-loader route: ordinary wrappers remove LD_PRELOAD,
    // so probes must be explicit loader arguments and must not contaminate child environments.
    $fixtureRoot = $temp . '/diagnostic';
    foreach (['lib', 'runtime', 'empty-ini'] as $directory) { mkdir($fixtureRoot . '/' . $directory, 0700, true); }
    $elf = command(['readelf', '-l', PHP_BINARY]);
    check($elf['exit_code'] === 0
        && preg_match('/\[Requesting program interpreter: ([^\]]+)\]/', $elf['stdout'], $interpreter) === 1,
        'test PHP identifies a dynamic loader');
    symlink($interpreter[1], $fixtureRoot . '/lib/loader');
    symlink(PHP_BINARY, $fixtureRoot . '/runtime/php');
    file_put_contents($fixtureRoot . '/php.ini', "date.timezone=UTC\n");
    $probeSource = $temp . '/preload.c';
    file_put_contents($probeSource, <<<'C'
#include <stdio.h>
#include <stdlib.h>
__attribute__((constructor)) static void loaded(void) {
    const char *path = getenv("KUMWE_BENCH_ALLOCATION_FILE");
    if (path == NULL) return;
    FILE *output = fopen(path, "a");
    if (output != NULL) { fputs("loaded\n", output); fclose(output); }
}
C
    );
    $preloads = [];
    foreach (['deepbind_probe.so', 'allocation_probe.so'] as $name) {
        $path = $temp . '/' . $name;
        $compiled = command(['cc', '-std=c11', '-Wall', '-Wextra', '-Werror', '-shared', '-fPIC', $probeSource, '-o', $path]);
        check($compiled['exit_code'] === 0, 'test preload compiled: ' . $compiled['stderr']);
        $preloads[$path] = sha($path);
    }
    $probeArgs = $args + ['diagnostic_runtime' => $fixtureRoot,
        '_diagnostic_loader' => 'lib/loader', '_allocation_preloads' => $preloads];
    $probeEnv = ['LD_PRELOAD' => implode(':', array_keys($preloads)), 'LD_AUDIT' => 'must-not-load',
        'LD_LIBRARY_PATH' => '/must-not-use', 'USE_ZEND_ALLOC' => '0',
        'KUMWE_BENCH_ALLOCATION_FILE' => $temp . '/preload-observed',
        'KUMWE_BENCH_DISABLE_DEEPBIND' => '1'];
    [$instrumented, $environment] = worker_process($probeArgs, 'php', $temp . '/unused.json', $probeEnv);
    check(array_slice($instrumented, 0, 7) === [$fixtureRoot . '/lib/loader', '--inhibit-cache',
        '--library-path', $fixtureRoot . '/lib', '--preload', $probeEnv['LD_PRELOAD'], $fixtureRoot . '/runtime/php'],
        'diagnostic probe uses explicit captured loader preload');
    check(!isset($environment['LD_PRELOAD']) && !isset($environment['LD_AUDIT']) && !isset($environment['LD_LIBRARY_PATH'])
        && $environment['PHPRC'] === $fixtureRoot . '/php.ini'
        && $environment['PHP_INI_SCAN_DIR'] === $fixtureRoot . '/empty-ini'
        && $environment['USE_ZEND_ALLOC'] === '0', 'diagnostic process environment is isolated');
    $instrumented = array_slice($instrumented, 0, -2);
    array_push($instrumented, '-r',
        'echo json_encode(["preload" => getenv("LD_PRELOAD"), "memory" => ini_get("memory_limit"), "allocator" => getenv("USE_ZEND_ALLOC")]);');
    $probeOut = tmpfile(); $probeErr = tmpfile();
    $process = proc_open($instrumented, [0 => ['file', '/dev/null', 'r'], 1 => $probeOut, 2 => $probeErr],
        $pipes, null, $environment);
    check(is_resource($process), 'instrumented PHP process starts');
    $status = proc_close($process);
    rewind($probeOut); rewind($probeErr);
    $observed = stream_get_contents($probeOut); $errors = stream_get_contents($probeErr);
    fclose($probeOut); fclose($probeErr);
    check($status === 0, 'instrumented PHP process succeeds: ' . $errors);
    check(json_decode($observed, true, 512, JSON_THROW_ON_ERROR) === ['preload' => false, 'memory' => '1G', 'allocator' => '0'],
        'instrumented PHP preserves worker budget and allocator configuration');
    check(file_get_contents($temp . '/preload-observed') === "loaded\nloaded\n",
        'both probes actually load despite cleared LD_PRELOAD');
    $changed = $probeArgs; $changed['_allocation_preloads'][$temp . '/allocation_probe.so'] = str_repeat('0', 64);
    refused(static fn () => worker_process($changed, 'php', $temp . '/unused.json', $probeEnv), 'changed probe digest');
    $changed = $probeArgs; unset($changed['_diagnostic_loader']);
    refused(static fn () => worker_process($changed, 'php', $temp . '/unused.json', $probeEnv), 'unverified diagnostic loader');
    echo "Benchmark admission, coverage, concurrent workers and explicit diagnostic preload tests passed.\n";
} finally {
    close_workers($workers);
    remove_test_tree($temp);
}
