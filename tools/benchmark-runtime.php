<?php
/**
 * Matched unchanged-App / actual PHP extension benchmark.
 * Linux orchestration uses PHP streams; workload and allocation probes are PHP/C.
 */
declare(strict_types=1);

namespace Kumwe\Engine\Benchmark;

const PROFILES = ['decimal', 'formula', 'document', 'preparation', 'report', 'canonical'];

function sha(string $path): string
{
    $digest = hash_file('sha256', $path);
    if ($digest === false) { throw new \RuntimeException("Cannot hash {$path}"); }
    return $digest;
}

function write(string $path, mixed $value): void
{
    $temporary = $path . '.tmp';
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    if (file_put_contents($temporary, $json) !== strlen($json) || !rename($temporary, $path)) {
        throw new \RuntimeException("Cannot write {$path}");
    }
}

function read_json(string $path): array
{
    $text = file_get_contents($path);
    if ($text === false) { throw new \RuntimeException("Cannot read {$path}"); }
    $value = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($value)) { throw new \RuntimeException("Expected JSON object in {$path}"); }
    return $value;
}

function command(array $arguments): array
{
    $out = tmpfile(); $err = tmpfile();
    if ($out === false || $err === false) { throw new \RuntimeException('Cannot allocate command logs'); }
    try {
        $process = proc_open($arguments, [0 => ['file', '/dev/null', 'r'], 1 => $out, 2 => $err], $pipes);
        if (!is_resource($process)) { throw new \RuntimeException('Cannot start command'); }
        $code = proc_close($process);
        rewind($out); rewind($err);
        return ['exit_code' => $code, 'stdout' => trim(stream_get_contents($out)),
            'stderr' => trim(stream_get_contents($err))];
    } finally { fclose($out); fclose($err); }
}

function revision(string $path): array
{
    return ['path' => $path, 'head' => command(['git', '-C', $path, 'rev-parse', 'HEAD']),
        'status' => command(['git', '-C', $path, 'status', '--porcelain'])];
}

function distribution(array $values): ?array
{
    if ($values === []) { return null; }
    sort($values, SORT_NUMERIC); $n = count($values);
    $result = ['samples' => $n, 'mean' => array_sum($values) / $n];
    foreach ([50, 95, 99] as $percentile) {
        $result['p' . $percentile] = $values[max(0, (int) ceil($percentile * $n / 100) - 1)];
    }
    return $result;
}

function median(array $values): float
{
    sort($values, SORT_NUMERIC); $n = count($values); $middle = intdiv($n, 2);
    return $n % 2 ? (float) $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
}

function require_parity(array $result, ?string $expected, string $label): void
{
    if (($result['ok'] ?? false) !== true || !isset($result['digest']) || !is_string($result['digest'])) {
        throw new \RuntimeException($label . ': worker failed: ' . json_encode($result, JSON_THROW_ON_ERROR));
    }
    if ($expected !== null && $result['digest'] !== $expected) {
        throw new \RuntimeException("{$label}: CORRECTNESS MISMATCH {$result['digest']} != {$expected}");
    }
}

/** Fixed-seed MT19937 with array initialization and 53-bit uniform samples. */
final class BootstrapRandom
{
    private array $state = [];
    private int $index = 624;

    public function __construct(int $seed = 1729)
    {
        $this->state[0] = 19650218;
        for ($i = 1; $i < 624; ++$i) {
            $last = $this->state[$i - 1];
            $this->state[$i] = (1812433253 * ($last ^ ($last >> 30)) + $i) & 0xffffffff;
        }
        $i = 1;
        for ($k = 624; $k > 0; --$k) {
            $last = $this->state[$i - 1];
            $this->state[$i] = (($this->state[$i] ^ (($last ^ ($last >> 30)) * 1664525)) + $seed) & 0xffffffff;
            if (++$i >= 624) { $this->state[0] = $this->state[623]; $i = 1; }
        }
        for ($k = 623; $k > 0; --$k) {
            $last = $this->state[$i - 1];
            $this->state[$i] = (($this->state[$i] ^ (($last ^ ($last >> 30)) * 1566083941)) - $i) & 0xffffffff;
            if (++$i >= 624) { $this->state[0] = $this->state[623]; $i = 1; }
        }
        $this->state[0] = 0x80000000;
    }

    private function next(): int
    {
        if ($this->index >= 624) {
            for ($i = 0; $i < 624; ++$i) {
                $y = ($this->state[$i] & 0x80000000) | ($this->state[($i + 1) % 624] & 0x7fffffff);
                $this->state[$i] = $this->state[($i + 397) % 624] ^ ($y >> 1) ^ (($y & 1) ? 0x9908b0df : 0);
            }
            $this->index = 0;
        }
        $y = $this->state[$this->index++];
        $y ^= $y >> 11;
        $y ^= ($y << 7) & 0x9d2c5680;
        $y ^= ($y << 15) & 0xefc60000;
        $y ^= $y >> 18;
        return $y & 0xffffffff;
    }

    public function uniform(): float
    {
        return (($this->next() >> 5) * 67108864.0 + ($this->next() >> 6)) / 9007199254740992.0;
    }

    public function choices(array $values): array
    {
        $out = []; $n = count($values);
        for ($i = 0; $i < $n; ++$i) { $out[] = $values[(int) floor($this->uniform() * $n)]; }
        return $out;
    }
}

function regression(array $previous, array $current, float $threshold): array
{
    if (min(count($previous), count($current)) < 20) {
        return ['evaluated' => false, 'reason' => 'fewer than 20 samples'];
    }
    foreach (array_merge($previous, $current) as $sample) {
        if (!is_numeric($sample) || !is_finite((float) $sample) || $sample <= 0) {
            throw new \RuntimeException('Regression samples must be finite positive durations');
        }
    }
    $rng = new BootstrapRandom(); $ratios = [];
    for ($i = 0; $i < 2000; ++$i) {
        $ratios[] = median($rng->choices($current)) / median($rng->choices($previous));
    }
    sort($ratios, SORT_NUMERIC);
    return ['evaluated' => true, 'median_ratio' => median($current) / median($previous),
        'bootstrap_95_interval' => [$ratios[49], $ratios[1949]], 'regression' => $ratios[49] > 1 + $threshold];
}

/**
 * Invoke a verified diagnostic loader explicitly for allocation instrumentation.
 * Its ordinary wrapper continues clearing all dynamic-loader environment variables.
 */
function worker_process(array $args, string $backend, string $configPath, array $extraEnv = [],
    ?array $invocation = null): array
{
    $env = getenv(); unset($env['USE_ZEND_ALLOC']);
    $env = array_merge($env, $extraEnv);
    if ($invocation === null) {
        if (($args['diagnostic_runtime'] ?? null) !== null && isset($extraEnv['LD_PRELOAD'])) {
            $root = $args['diagnostic_runtime'];
            $loader = $args['_diagnostic_loader'] ?? null;
            if (!is_string($loader) || !str_starts_with($loader, 'lib/')
                || in_array('..', explode('/', $loader), true)) {
                throw new \RuntimeException('Allocation workers require an independently verified diagnostic loader');
            }
            $expected = $args['_allocation_preloads'] ?? [];
            $paths = [$args['output'] . '/deepbind_probe.so', $args['output'] . '/allocation_probe.so'];
            if ($extraEnv['LD_PRELOAD'] !== implode(':', $paths) || count($expected) !== count($paths)) {
                throw new \RuntimeException('Allocation preload list differs from the compiled probes');
            }
            foreach ($paths as $path) {
                if (preg_match('/[:\s]/', $path) || !isset($expected[$path])
                    || !is_file($path) || is_link($path) || sha($path) !== $expected[$path]) {
                    throw new \RuntimeException('Allocation preload path or digest differs from the compiled probe: ' . $path);
                }
            }
            if (!is_executable($root . '/' . $loader) || !is_executable($root . '/runtime/php')) {
                throw new \RuntimeException('Diagnostic runtime must be verified and activated before benchmarking');
            }
            unset($env['LD_PRELOAD'], $env['LD_AUDIT'], $env['LD_LIBRARY_PATH']);
            $env['KUMWE_DIAGNOSTIC_ROOT'] = $root;
            $env['PHPRC'] = $root . '/php.ini';
            $env['PHP_INI_SCAN_DIR'] = $root . '/empty-ini';
            $invocation = [$root . '/' . $loader, '--inhibit-cache', '--library-path', $root . '/lib',
                '--preload', implode(':', $paths), $root . '/runtime/php'];
        } else {
            $invocation = [$args['php']];
        }
        array_push($invocation, '-d', 'memory_limit=1G', '-d', 'display_errors=stderr');
        if ($backend === 'native') { array_push($invocation, '-d', 'extension=' . $args['extension']); }
        array_push($invocation, $args['engine'] . '/benchmarks/e2e/worker.php', $configPath);
    }
    return [$invocation, $env];
}

final class Worker
{
    public array $ready;
    private mixed $process = null;
    private array $pipes = [];
    private string $buffer = '';
    private bool $closed = false;
    private bool $pending = false;
    private int $deadline = 0;

    public function __construct(array $args, string $backend, public readonly string $label,
        array $extraEnv = [], ?array $invocation = null)
    {
        $config = [];
        foreach (['app', 'sdk', 'autoload', 'engine'] as $key) { $config[$key] = $args[$key]; }
        $config['backend'] = $backend;
        $configPath = $args['output'] . '/' . $label . '.config.json'; write($configPath, $config);
        [$invocation, $env] = worker_process($args, $backend, $configPath, $extraEnv, $invocation);
        $this->process = proc_open($invocation, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'],
            2 => ['file', $args['output'] . '/' . $label . '.stderr.log', 'w']], $this->pipes, null, $env);
        if (!is_resource($this->process)) { throw new \RuntimeException("{$label}: cannot start worker"); }
        stream_set_blocking($this->pipes[1], false);
        $this->deadline = hrtime(true) + 180_000_000_000;
        try {
            $this->ready = $this->read();
            if (($this->ready['ready'] ?? false) !== true) {
                throw new \RuntimeException("{$label}: failed startup: " . json_encode($this->ready, JSON_THROW_ON_ERROR));
            }
        } catch (\Throwable $failure) { $this->abort(); throw $failure; }
    }

    public function stream(): mixed { return $this->pipes[1]; }
    public function buffered(): bool { return str_contains($this->buffer, "\n"); }
    public function expired(): bool { return hrtime(true) >= $this->deadline; }

    public function send(array $job): void
    {
        if ($this->closed || $this->pending) { throw new \RuntimeException("{$this->label}: worker busy or closed"); }
        $line = json_encode($job, JSON_THROW_ON_ERROR) . "\n";
        $offset = 0;
        while ($offset < strlen($line)) {
            $n = @fwrite($this->pipes[0], substr($line, $offset));
            if ($n === false || $n === 0) { throw new \RuntimeException("{$this->label}: IPC write failed"); }
            $offset += $n;
        }
        fflush($this->pipes[0]); $this->pending = true; $this->deadline = hrtime(true) + 180_000_000_000;
    }

    public function receive(): ?array
    {
        if (!$this->buffered()) {
            $chunk = fread($this->pipes[1], 1048576);
            if ($chunk === false) { throw new \RuntimeException("{$this->label}: IPC read failed"); }
            $this->buffer .= $chunk;
        }
        $end = strpos($this->buffer, "\n");
        if ($end !== false) {
            $line = substr($this->buffer, 0, $end); $this->buffer = substr($this->buffer, $end + 1);
            $value = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($value)) { throw new \RuntimeException("{$this->label}: invalid worker response"); }
            $this->pending = false;
            return $value;
        }
        if (feof($this->pipes[1])) { throw new \RuntimeException("{$this->label}: worker exited; inspect stderr log"); }
        if ($this->expired()) { $this->abort(); throw new \RuntimeException("{$this->label}: worker timed out"); }
        return null;
    }

    public function read(): array
    {
        while (true) {
            $value = $this->receive();
            if ($value !== null) { return $value; }
            $read = [$this->pipes[1]]; $write = null; $except = null;
            $selected = @stream_select($read, $write, $except, 1);
            if ($selected === false) { throw new \RuntimeException("{$this->label}: IPC select failed"); }
        }
    }

    public function request(array $job): array { $this->send($job); return $this->read(); }

    public function abort(): void
    {
        if ($this->closed) { return; }
        $this->closed = true;
        if (is_resource($this->process)) { proc_terminate($this->process, 9); }
        foreach ($this->pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
        if (is_resource($this->process)) { proc_close($this->process); }
    }

    public function close(): void
    {
        if ($this->closed) { return; }
        $status = proc_get_status($this->process);
        if ($status['running']) {
            // The child may close stdin before its process is reaped. A failed
            // stop write is therefore not an exit failure; EOF also requests
            // shutdown. Preserve the actual process status and bounded wait.
            @fwrite($this->pipes[0], "{\"command\":\"stop\"}\n");
            @fflush($this->pipes[0]);
            fclose($this->pipes[0]);
            unset($this->pipes[0]);
            $deadline = hrtime(true) + 20_000_000_000;
            do {
                $status = proc_get_status($this->process);
                if (!$status['running']) { break; }
                if (hrtime(true) >= $deadline) {
                    $this->abort(); throw new \RuntimeException("{$this->label}: worker failed to stop");
                }
                usleep(10000);
            } while (true);
        }
        foreach ($this->pipes as $pipe) { fclose($pipe); }
        $code = proc_close($this->process);
        if ($code === -1 && $status['exitcode'] >= 0) { $code = $status['exitcode']; }
        $this->closed = true;
        if ($code !== 0) { throw new \RuntimeException("{$this->label}: exit {$code}"); }
    }

    public function __destruct() { $this->abort(); }
}

function close_workers(array $workers): void
{
    $failure = null;
    foreach ($workers as $worker) {
        try { $worker->close(); } catch (\Throwable $error) { $failure ??= $error; }
    }
    if ($failure !== null) { throw $failure; }
}

function job(string $profile, int $size, string $shape = 'valid', string $phase = 'warm', int $iterations = 1): array
{
    return compact('profile', 'size', 'shape', 'phase', 'iterations');
}

function matrix_jobs(array $args): \Generator
{
    foreach ($args['profiles'] as $profile) {
        $phases = $profile === 'canonical' ? ['encode', 'digest'] : ($profile === 'decimal' ? ['warm'] : ['warm', 'cold']);
        foreach ($args['sizes'] as $size) {
            foreach (['valid', 'hostile'] as $shape) {
                foreach ($phases as $phase) { yield job($profile, $size, $shape, $phase); }
            }
        }
    }
}

function matrix(array $args, array $workers, array &$results): void
{
    $golden = [];
    foreach (matrix_jobs($args) as $request) {
        ['profile' => $profile, 'size' => $size, 'shape' => $shape, 'phase' => $phase] = $request;
        $key = "{$profile}/{$size}/{$shape}/{$phase}";
        foreach ($workers as $backend => $worker) {
            $warm = $worker->request(array_replace($request, ['iterations' => $args['warmup']]));
            require_parity($warm, $golden[$key] ?? null, $key . '/warmup/' . $backend);
            $result = $worker->request(array_replace($request, ['iterations' => $args['samples']]));
            require_parity($result, $golden[$key] ?? $warm['digest'], $key . '/' . $backend);
            $golden[$key] = $result['digest'];
            $result = array_merge($result, compact('key', 'backend', 'profile', 'size', 'shape', 'phase'),
                ['latency_ns' => distribution($result['durations_ns']),
                'compile_ns' => distribution($result['compile_durations_ns']),
                'work_units_per_second' => $size * count($result['durations_ns']) * 1e9 / array_sum($result['durations_ns'])]);
            $results['matrix'][] = $result; write($args['output'] . '/results.json', $results);
            printf("%s %s: p50=%.3fms parity=ok\n", $key, $backend, $result['latency_ns']['p50'] / 1e6);
        }
    }
}

/** Drive independent PHP processes concurrently; each worker has at most one request in flight. */
function concurrent(array $workers, array $request, string $expected, ?int $jobs, ?float $seconds): array
{
    $started = hrtime(true); $deadline = $started + (int) (($seconds ?? 0) * 1e9);
    $active = []; $next = 0; $records = []; $parts = []; $finished = [];
    $dispatch = static function (int $index) use (&$active, &$next, $workers, $request): void {
        $entered = hrtime(true); $service = hrtime(true); $id = $next++;
        $workers[$index]->send($request);
        $active[$index] = ['entered' => $entered, 'service' => $service, 'id' => $id];
    };
    foreach ($workers as $index => $worker) {
        $parts[$index] = [];
        if ($jobs === null || $next < $jobs) { $dispatch($index); }
    }
    while ($active !== []) {
        $read = []; $buffered = false;
        foreach ($active as $index => $_) {
            $read[] = $workers[$index]->stream(); $buffered = $buffered || $workers[$index]->buffered();
            if ($workers[$index]->expired()) { throw new \RuntimeException($workers[$index]->label . ': worker timed out'); }
        }
        if (!$buffered) {
            $write = null; $except = null;
            if (@stream_select($read, $write, $except, 1) === false) { throw new \RuntimeException('Capacity IPC select failed'); }
        }
        foreach (array_keys($active) as $index) {
            $worker = $workers[$index];
            if (!$worker->buffered() && !in_array($worker->stream(), $read, true)) { continue; }
            $response = $worker->receive();
            if ($response === null) { continue; }
            $done = hrtime(true); $pending = $active[$index]; unset($active[$index]);
            require_parity($response, $expected, $jobs === null ? 'saturation' : 'burst');
            $observation = ['boundary_ns' => $done - $pending['service'],
                'service_ns' => $response['durations_ns'][0], 'peak_rss_bytes' => $response['peak_rss_bytes']];
            if ($jobs !== null) {
                $records[$pending['id']] = ['queue_ns' => $pending['service'] - $started,
                    'dispatch_wait_ns' => $pending['service'] - $pending['entered'],
                    'completion_ns' => $done - $started] + $observation;
            } else { $parts[$index][] = $observation; }
            if (($jobs !== null && $next < $jobs) || ($jobs === null && hrtime(true) < $deadline)) {
                $dispatch($index);
            } else { $finished[$index] = hrtime(true) - $started; }
        }
    }
    if ($jobs !== null) {
        ksort($records, SORT_NUMERIC);
        return ['wall_ns' => hrtime(true) - $started, 'raw' => array_values($records)];
    }
    return ['wall_ns' => max($finished), 'raw' => array_merge(...array_values($parts))];
}

function capacity(array $args, array &$results): void
{
    foreach ($args['profiles'] as $profile) {
        $request = job($profile, $args['capacity_size'], phase: $profile === 'canonical' ? 'encode' : 'warm');
        $expected = null;
        foreach (['php', 'native'] as $backend) {
            foreach ($args['workers'] as $count) {
                $workers = [];
                try {
                    for ($i = 0; $i < $count; ++$i) {
                        $workers[] = new Worker($args, $backend, "capacity-{$profile}-{$backend}-{$count}-{$i}");
                    }
                    foreach ($workers as $worker) {
                        $warm = $worker->request(array_replace($request, ['iterations' => $args['warmup']]));
                        require_parity($warm, $expected, 'capacity warmup'); $expected = $warm['digest'];
                    }
                    $burst = concurrent($workers, $request, $expected, $args['burst_jobs'], null);
                    $saturated = concurrent($workers, $request, $expected, null, $args['capacity_seconds']);
                    $record = ['profile' => $profile, 'backend' => $backend, 'workers' => $count,
                        'size' => $args['capacity_size'], 'digest' => $expected, 'burst' => [
                            'jobs' => $args['burst_jobs'], 'wall_ns' => $burst['wall_ns'],
                            'work_units_per_second' => $args['burst_jobs'] * $args['capacity_size'] * 1e9 / $burst['wall_ns'],
                            'queue_ns' => distribution(array_column($burst['raw'], 'queue_ns')),
                            'completion_ns' => distribution(array_column($burst['raw'], 'completion_ns')), 'raw' => $burst['raw']],
                        'saturation' => ['jobs' => count($saturated['raw']), 'wall_ns' => $saturated['wall_ns'],
                            'work_units_per_second' => count($saturated['raw']) * $args['capacity_size'] * 1e9 / $saturated['wall_ns'],
                            'boundary_ns' => distribution(array_column($saturated['raw'], 'boundary_ns')),
                            'service_ns' => distribution(array_column($saturated['raw'], 'service_ns')), 'raw' => $saturated['raw']],
                        'worker_identity' => array_map(static fn (Worker $w): array => $w->ready, $workers)];
                    $results['capacity'][] = $record; write($args['output'] . '/results.json', $results);
                    printf("capacity %s %s workers=%d: %.1f units/s\n", $profile, $backend, $count,
                        $record['saturation']['work_units_per_second']);
                } finally { close_workers($workers); }
            }
        }
    }
}

function allocations(array $args, array &$results): void
{
    $probe = $args['output'] . '/allocation_probe.so'; $deepbind = $args['output'] . '/deepbind_probe.so';
    $compiled = command([$args['cc'], '-std=c11', '-O2', '-fPIC', '-shared', '-Wall', '-Wextra', '-Werror',
        $args['engine'] . '/benchmarks/e2e/allocation_probe.c', '-o', $probe]);
    if ($compiled['exit_code'] !== 0) { throw new \RuntimeException('Allocation probe build failed: ' . json_encode($compiled)); }
    $deepbindBuild = command([$args['cc'], '-std=c11', '-O2', '-fPIC', '-shared', '-Wall', '-Wextra', '-Werror', '-pthread',
        $args['engine'] . '/benchmarks/e2e/deepbind_probe.c', '-ldl', '-o', $deepbind]);
    if ($deepbindBuild['exit_code'] !== 0) { throw new \RuntimeException('Deepbind diagnostic build failed: ' . json_encode($deepbindBuild)); }
    $results['allocation_probe'] = ['build' => $compiled, 'sha256' => sha($probe),
        'deepbind_build' => $deepbindBuild, 'deepbind_sha256' => sha($deepbind),
        'scope' => 'Fresh whole process, successful glibc-interposed requested bytes/calls, USE_ZEND_ALLOC=0 and diagnostic RTLD_DEEPBIND removal; includes startup, compilation, warmup and measured jobs. Not live heap and not primary timing.'];
    $args['_allocation_preloads'] = [$deepbind => $results['allocation_probe']['deepbind_sha256'],
        $probe => $results['allocation_probe']['sha256']];
    foreach ($args['profiles'] as $profile) {
        $expected = null;
        foreach (['php', 'native'] as $backend) {
            $output = $args['output'] . "/alloc-{$profile}-{$backend}.json";
            $deepbindOutput = $args['output'] . "/deepbind-{$profile}-{$backend}.json";
            $worker = new Worker($args, $backend, "alloc-{$profile}-{$backend}", [
                'USE_ZEND_ALLOC' => '0', 'LD_PRELOAD' => $deepbind . ':' . $probe,
                'KUMWE_BENCH_DISABLE_DEEPBIND' => '1', 'KUMWE_BENCH_DEEPBIND_FILE' => $deepbindOutput,
                'KUMWE_BENCH_ALLOCATION_FILE' => $output]);
            try {
                $request = job($profile, $args['capacity_size'], phase: $profile === 'canonical' ? 'encode' : 'warm');
                $warm = $worker->request(array_replace($request, ['iterations' => $args['warmup']]));
                require_parity($warm, $expected, 'allocation warmup');
                $measured = $worker->request(array_replace($request, ['iterations' => $args['samples']]));
                require_parity($measured, $expected ?? $warm['digest'], 'allocation measurement'); $expected = $measured['digest'];
            } finally { $worker->close(); }
            $counters = read_json($output); $deepbindCounts = read_json($deepbindOutput);
            if (!$deepbindCounts['disable_deepbind_enabled'] || $deepbindCounts['counters_overflowed']) {
                throw new \RuntimeException('Deepbind diagnostic missing or overflowed');
            }
            if ($deepbindCounts['requested_deepbind_calls'] !== $deepbindCounts['stripped_deepbind_calls']) {
                throw new \RuntimeException('Deepbind bypassed allocation instrumentation');
            }
            if ($counters['counters_overflowed']) { throw new \RuntimeException('Allocation diagnostic counter overflow'); }
            $results['allocations'][] = ['profile' => $profile, 'backend' => $backend, 'size' => $args['capacity_size'],
                'warmup' => $args['warmup'], 'repetitions' => $args['samples'], 'counters' => $counters,
                'deepbind' => $deepbindCounts, 'worker_identity' => $worker->ready, 'measurement' => $measured];
            write($args['output'] . '/results.json', $results);
        }
    }
}

function same_value(mixed $left, mixed $right): bool
{
    if (!is_array($left) || !is_array($right)) { return $left === $right; }
    if (count($left) !== count($right)) { return false; }
    foreach ($left as $key => $value) {
        if (!array_key_exists($key, $right) || !same_value($value, $right[$key])) { return false; }
    }
    return true;
}

function require_comparable_metadata(array $old, array $current): void
{
    foreach (['machine', 'php_sha256', 'corpus_hashes'] as $key) {
        if (!isset($old[$key], $current[$key]) || !same_value($old[$key], $current[$key])) {
            throw new \RuntimeException("Regression comparison requires matching {$key}");
        }
    }
    if (!isset($old['harness_hashes']['worker.php'], $current['harness_hashes']['worker.php'])
        || $old['harness_hashes']['worker.php'] !== $current['harness_hashes']['worker.php']) {
        throw new \RuntimeException('Regression comparison requires identical workload implementation');
    }
    foreach (['php', 'php_binary_sha256', 'icu', 'sources', 'conversion_reference', 'memory_limit'] as $key) {
        if (!array_key_exists($key, $old['worker_identity']['php'] ?? [])
            || !array_key_exists($key, $current['worker_identity']['php'] ?? [])
            || !same_value($old['worker_identity']['php'][$key], $current['worker_identity']['php'][$key])) {
            throw new \RuntimeException("Regression comparison requires matching PHP semantic baseline {$key}");
        }
    }
    $prior = $old['worker_identity']['native']['capabilities']['binding_build'] ?? [];
    $now = $current['worker_identity']['native']['capabilities']['binding_build'] ?? [];
    foreach (['architecture', 'compiler', 'engine_flags', 'extension_flags', 'libc', 'sanitizers',
        'thread_model', 'zend_module_api', 'debug'] as $key) {
        if (!array_key_exists($key, $prior) || !array_key_exists($key, $now) || !same_value($prior[$key], $now[$key])) {
            throw new \RuntimeException("Regression comparison requires matching native build configuration {$key}");
        }
    }
}

function compare(array $args, array $results): array
{
    if ($args['baseline'] === null) { return []; }
    $old = read_json($args['baseline']); require_comparable_metadata($old['metadata'], $results['metadata']);
    $previous = []; $checks = [];
    foreach ($old['matrix'] as $row) {
        $key = $row['key'] . "\0" . $row['backend'];
        if (isset($previous[$key])) { throw new \RuntimeException('Duplicate baseline workload'); }
        $previous[$key] = $row;
    }
    foreach ($results['matrix'] as $row) {
        $key = $row['key'] . "\0" . $row['backend'];
        if (!isset($previous[$key])) { throw new \RuntimeException("Missing baseline workload {$row['key']}/{$row['backend']}"); }
        if ($row['dataset_descriptor_sha256'] !== $previous[$key]['dataset_descriptor_sha256']) {
            throw new \RuntimeException("Changed baseline dataset {$row['key']}/{$row['backend']}");
        }
        require_parity($row, $previous[$key]['digest'], 'regression baseline');
        $checks[] = ['key' => [$row['key'], $row['backend']]] +
            regression($previous[$key]['durations_ns'], $row['durations_ns'], $args['regression_threshold']);
    }
    return $checks;
}

function parse_arguments(array $argv): array
{
    $args = ['php' => null, 'extension' => null, 'app' => null, 'sdk' => null, 'autoload' => null,
        'engine' => dirname(__DIR__) . '/benchmark-sources/engine', 'output' => null, 'build_dir' => null,
        'profiles' => PROFILES, 'sizes' => [1, 32, 256, 4096], 'samples' => 30, 'warmup' => 10,
        'workers' => [1, 2, 4, 8], 'capacity_seconds' => 3.0, 'capacity_size' => 32, 'burst_jobs' => 64,
        'cc' => 'cc', 'baseline' => null, 'diagnostic_runtime' => null, 'demand' => [], 'regression_threshold' => 0.10, 'smoke' => false];
    for ($i = 1; $i < count($argv); ++$i) {
        if ($argv[$i] === '--help' || $argv[$i] === '-h') {
            echo "Usage: php tools/benchmark-runtime.php --php PATH --extension PATH --app PATH --sdk PATH --autoload PATH --output NEW_DIRECTORY [options]\n";
            echo "Options: --engine PATH --build-dir PATH --profiles NAME... --sizes N... --workers N... --samples N --warmup N\n";
            echo "         --capacity-seconds N --capacity-size N --burst-jobs N --cc COMMAND --baseline FILE\n";
            echo "         --demand PROFILE=UNITS_PER_SECOND --regression-threshold N --diagnostic-runtime DIRECTORY --smoke\n";
            exit(0);
        }
        $parts = explode('=', $argv[$i], 2); $name = str_replace('-', '_', substr($parts[0], 2));
        if (!str_starts_with($parts[0], '--') || !array_key_exists($name, $args)) {
            throw new \InvalidArgumentException('Unknown option: ' . $argv[$i]);
        }
        if ($name === 'smoke') {
            if (count($parts) !== 1) { throw new \InvalidArgumentException('--smoke takes no value'); }
            $args[$name] = true; continue;
        }
        $values = count($parts) === 2 ? [$parts[1]] : [];
        $multiple = in_array($name, ['profiles', 'sizes', 'workers'], true);
        while ($i + 1 < count($argv) && !str_starts_with($argv[$i + 1], '--') && ($multiple || $values === [])) {
            $values[] = $argv[++$i];
        }
        if ($values === []) { throw new \InvalidArgumentException("Missing value for {$parts[0]}"); }
        if ($name === 'demand') { $args[$name][] = $values[0]; }
        else { $args[$name] = $multiple ? $values : $values[0]; }
    }
    foreach (['sizes', 'workers'] as $key) {
        $args[$key] = array_map(static function ($value): int {
            if (filter_var($value, FILTER_VALIDATE_INT) === false) { throw new \InvalidArgumentException('Expected integer list'); }
            return (int) $value;
        }, $args[$key]);
    }
    foreach (['samples', 'warmup', 'capacity_size', 'burst_jobs'] as $key) {
        if (filter_var($args[$key], FILTER_VALIDATE_INT) === false) { throw new \InvalidArgumentException("Invalid integer {$key}"); }
        $args[$key] = (int) $args[$key];
    }
    foreach (['capacity_seconds', 'regression_threshold'] as $key) {
        if (!is_numeric($args[$key]) || !is_finite((float) $args[$key])) { throw new \InvalidArgumentException("Invalid number {$key}"); }
        $args[$key] = (float) $args[$key];
    }
    if ($args['smoke']) {
        $args['sizes'] = [1, 32]; $args['samples'] = 3; $args['warmup'] = 1;
        $args['workers'] = [1, 2]; $args['capacity_seconds'] = .15; $args['burst_jobs'] = 4;
    }
    if (PHP_OS_FAMILY !== 'Linux' || PHP_INT_SIZE !== 8 || min([...$args['sizes'], $args['capacity_size']]) < 1
        || max([...$args['sizes'], $args['capacity_size']]) > 4096 || min($args['workers']) < 1 || max($args['workers']) > 128
        || $args['samples'] < 1 || $args['samples'] > 10000 || $args['warmup'] < 1 || $args['warmup'] > 10000
        || $args['capacity_seconds'] <= 0 || $args['capacity_seconds'] > 3600 || $args['burst_jobs'] < 1
        || $args['burst_jobs'] > 100000 || $args['regression_threshold'] < 0
        || array_diff($args['profiles'], PROFILES) !== [] || count(array_unique($args['profiles'])) !== count($args['profiles'])
        || count(array_unique($args['sizes'])) !== count($args['sizes']) || count(array_unique($args['workers'])) !== count($args['workers'])) {
        throw new \InvalidArgumentException('Invalid bounds or unsupported non-Linux/32-bit host');
    }
    foreach (['php', 'extension', 'app', 'sdk', 'autoload', 'engine'] as $key) {
        $path = $args[$key] === null ? false : realpath($args[$key]);
        if ($path === false) { throw new \InvalidArgumentException("{$key} does not exist"); }
        $args[$key] = $path;
    }
    foreach (['baseline', 'build_dir'] as $key) {
        if ($args[$key] !== null) {
            $path = realpath($args[$key]);
            if ($path === false) { throw new \InvalidArgumentException("{$key} does not exist"); }
            $args[$key] = $path;
        }
    }
    if ($args['output'] === null || $args['output'] === '') { throw new \InvalidArgumentException('--output is required'); }
    foreach ($args['demand'] as $demand) {
        $pair = explode('=', $demand, 2);
        if (count($pair) !== 2 || !in_array($pair[0], $args['profiles'], true) || !is_numeric($pair[1])
            || !is_finite((float) $pair[1]) || (float) $pair[1] <= 0) {
            throw new \InvalidArgumentException('Each demand must be a selected PROFILE=positive-units-per-second');
        }
    }
    return $args;
}

function file_hashes(string $root, bool $recursive): array
{
    $iterator = $recursive
        ? new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS))
        : new \FilesystemIterator($root, \FilesystemIterator::SKIP_DOTS);
    $hashes = [];
    foreach ($iterator as $file) {
        if ($file->isFile()) { $hashes[substr($file->getPathname(), strlen($root) + 1)] = sha($file->getPathname()); }
    }
    ksort($hashes, SORT_STRING); return $hashes;
}

function main(array $argv): int
{
    $args = parse_arguments($argv);
    $diagnosticLoader = null;
    if ($args['diagnostic_runtime'] !== null) {
        require_once __DIR__ . '/diagnostic-runtime.php';
        $head = command(['git', '-C', dirname(__DIR__), 'rev-parse', 'HEAD']);
        if ($head['exit_code'] !== 0 || !preg_match('/^[a-f0-9]{40}$/D', $head['stdout'])) {
            throw new \RuntimeException('Cannot independently identify the diagnostic source commit');
        }
        $fixture = \Kumwe\Tools\DiagnosticRuntime\verify($args['diagnostic_runtime'], $head['stdout']);
        $args['diagnostic_runtime'] = realpath($args['diagnostic_runtime']);
        $diagnosticLoader = $fixture['loader'];
        foreach ([$diagnosticLoader, 'runtime/php'] as $path) {
            if (!is_executable($args['diagnostic_runtime'] . '/' . $path)) {
                throw new \RuntimeException('Diagnostic runtime must be verified and activated before benchmarking');
            }
        }
    }
    if (file_exists($args['output']) || is_link($args['output']) || !mkdir($args['output'], 0777, true)) {
        throw new \RuntimeException('Output directory must not already exist');
    }
    $args['output'] = realpath($args['output']);
    $demands = [];
    foreach ($args['demand'] as $demand) { [$name, $rate] = explode('=', $demand, 2); $demands[$name] = (float) $rate; }
    $lscpu = command(['lscpu'])['stdout'];
    $stableCpu = implode("\n", array_filter(explode("\n", $lscpu),
        static fn (string $line): bool => !preg_match('/MHz|BogoMIPS|scaling/', $line)));
    $corpusHashes = [];
    foreach (file_hashes($args['engine'] . '/corpus', true) as $path => $digest) { $corpusHashes['corpus/' . $path] = $digest; }
    $harnessHashes = [];
    foreach (['worker.php', 'allocation_probe.c', 'deepbind_probe.c'] as $name) {
        $harnessHashes[$name] = sha($args['engine'] . '/benchmarks/e2e/' . $name);
    }
    $harnessHashes['benchmark-runtime.php'] = sha(__FILE__);
    $metadata = ['format' => 'kumwe-app-native-performance/1', 'started_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'machine' => ['platform' => php_uname('s') . '-' . php_uname('r') . '-' . php_uname('m') . '-with-'
                . str_replace(' ', '', command(['getconf', 'GNU_LIBC_VERSION'])['stdout']),
            'cpu' => $stableCpu, 'cpu_count' => (int) command(['getconf', '_NPROCESSORS_ONLN'])['stdout']],
        'lscpu' => $lscpu, 'php_sha256' => sha($args['php']), 'extension_sha256' => sha($args['extension']),
        'compiler' => command([$args['cc'], '--version']), 'arguments' => $args,
        'sources' => ['engine' => revision($args['engine']), 'app' => revision($args['app']), 'sdk' => revision($args['sdk'])],
        'harness_hashes' => $harnessHashes, 'corpus_hashes' => $corpusHashes, 'load_average_at_start' => sys_getloadavg(),
        'scope' => 'Whole unchanged App pure semantic methods versus actual Zend extension boundary, including host preparation codec, JSON/KED serialization, copies, decoding and final output encoding. SQL, HTTP, authorization, persistence and allocation services are outside both paths.',
        'timing_scope' => 'hrtime inside worker excludes correctness digest; capacity boundary includes process IPC and correctness verification; cold includes host plan construction, native compile and destruction.',
        'capacity_scope' => 'Backlogged burst and closed-loop saturation, synthetic deterministic owner-derived workload. No production arrival-rate or HTTP capacity claim.',
        'smoke' => $args['smoke']];
    if ($args['build_dir'] !== null) {
        $cache = $args['build_dir'] . '/CMakeCache.txt';
        $metadata['cmake_cache'] = is_file($cache) ? file_get_contents($cache) : null;
    }
    $args['_diagnostic_loader'] = $diagnosticLoader;
    $results = ['metadata' => $metadata, 'matrix' => [], 'capacity' => [], 'allocations' => [], 'complete' => false];
    $workers = [];
    try {
        foreach (['php', 'native'] as $backend) { $workers[$backend] = new Worker($args, $backend, 'matrix-' . $backend); }
        $results['metadata']['worker_identity'] = array_map(static fn (Worker $w): array => $w->ready, $workers);
        if (!same_value($workers['php']->ready['sources'], $workers['native']->ready['sources'])) {
            throw new \RuntimeException('App source mismatch between backends');
        }
        matrix($args, $workers, $results); close_workers($workers); $workers = [];
        capacity($args, $results); allocations($args, $results);
        $results['capacity_summary'] = [];
        foreach ($args['profiles'] as $profile) {
            foreach (['php', 'native'] as $backend) {
                $points = array_values(array_filter($results['capacity'],
                    static fn (array $row): bool => $row['profile'] === $profile && $row['backend'] === $backend));
                usort($points, static fn (array $a, array $b): int =>
                    $b['saturation']['work_units_per_second'] <=> $a['saturation']['work_units_per_second']);
                $peak = $points[0]; $achieved = $peak['saturation']['work_units_per_second'];
                $results['capacity_summary'][] = ['profile' => $profile, 'backend' => $backend,
                    'maximum_observed_units_per_second' => $achieved, 'workers_at_maximum' => $peak['workers'],
                    'declared_demand_units_per_second' => $demands[$profile] ?? null,
                    'observed_headroom_fraction' => isset($demands[$profile]) ? $achieved / $demands[$profile] - 1 : null];
            }
        }
        $results['regression_checks'] = compare($args, $results);
        $results['complete'] = true; $results['correctness_passed'] = true;
        $results['statistical_sample_floor_met'] = !$args['smoke'] && $args['samples'] >= 20;
        $failed = count(array_filter($results['regression_checks'], static fn (array $r): bool => $r['regression'] ?? false)) > 0;
        $evaluated = $results['regression_checks'] !== []
            && !array_filter($results['regression_checks'], static fn (array $r): bool => !$r['evaluated']);
        $results['regression_gate'] = $failed ? 'failed' : ($evaluated ? 'passed' : 'not_evaluated');
        $pairs = [];
        foreach ($results['matrix'] as $row) { $pairs[$row['key']][$row['backend']] = $row; }
        $results['comparisons'] = [];
        foreach ($pairs as $key => $pair) {
            $results['comparisons'][] = ['key' => $key,
                'php_over_native_p50' => $pair['php']['latency_ns']['p50'] / $pair['native']['latency_ns']['p50'],
                'native_over_php_median_bootstrap' => regression($pair['php']['durations_ns'], $pair['native']['durations_ns'],
                    $args['regression_threshold'])];
        }
        $results['metadata']['load_average_at_end'] = sys_getloadavg(); write($args['output'] . '/results.json', $results);
        return $failed ? 1 : 0;
    } catch (\Throwable $failure) {
        $results['failure'] = $failure->getMessage(); write($args['output'] . '/results.json', $results); throw $failure;
    } finally { close_workers($workers); }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try { exit(main($argv)); }
    catch (\Throwable $failure) { fwrite(STDERR, $failure::class . ': ' . $failure->getMessage() . "\n"); exit(1); }
}
