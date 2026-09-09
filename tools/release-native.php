<?php
declare(strict_types=1);

namespace Kumwe\ReleaseNative;

/** Publish verified stable native sources from successful default-branch CI. */
class ReleaseError extends \RuntimeException
{
}

function read_bytes(string $path): string
{
    $value = @file_get_contents($path);
    if ($value === false) {
        throw new ReleaseError('Cannot read file: ' . $path);
    }
    return $value;
}

function write_bytes(string $path, string $value): void
{
    if (@file_put_contents($path, $value) !== strlen($value)) {
        throw new ReleaseError('Cannot write file: ' . $path);
    }
}

function ensure_directory(string $path): void
{
    if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) {
        throw new ReleaseError('Cannot create directory: ' . $path);
    }
}

function temporary_directory(string $prefix): string
{
    for ($attempt = 0; $attempt < 10; ++$attempt) {
        $path = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(12));
        if (@mkdir($path, 0700)) {
            return $path;
        }
    }
    throw new ReleaseError('Cannot create temporary directory.');
}

function remove_directory(string $path): void
{
    if (is_link($path) || !is_dir($path)) {
        if (file_exists($path) || is_link($path)) {
            unlink($path);
        }
        return;
    }
    foreach (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS) as $entry) {
        remove_directory($entry->getPathname());
    }
    rmdir($path);
}

function json_object(string $raw): array
{
    try {
        $object = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        if (!$object instanceof \stdClass) {
            throw new ReleaseError('JSON response must be an object.');
        }
        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException $error) {
        throw new ReleaseError('Malformed response JSON.', 0, $error);
    }
}

/** Only an actual HTTP 404 authorizes creation; error-message text cannot. */
function decode_http(string $raw, int $returncode, bool $allow_missing = false): ?array
{
    $first = explode("\n", $raw, 2)[0];
    if (!preg_match('/\AHTTP\/[0-9.]+ ([0-9]{3})(?: .*?)?\r?\z/D', $first, $match)) {
        throw new ReleaseError('GitHub response has no verifiable HTTP status.');
    }
    $status = (int) $match[1];
    if ($status === 404 && $returncode !== 0 && $allow_missing) {
        return null;
    }
    if ($returncode !== 0 || !in_array($status, [200, 201], true)) {
        throw new ReleaseError("GitHub request failed with HTTP {$status}.");
    }
    $marker = str_contains($raw, "\r\n\r\n") ? "\r\n\r\n" : "\n\n";
    $parts = explode($marker, $raw, 2);
    if (count($parts) !== 2) {
        throw new ReleaseError('GitHub returned malformed response JSON.');
    }
    return json_object($parts[1]);
}

/** Existing source gates own source validation. Tags, releases and assets are immutable. */
class Publisher
{
    public string $root;
    public array $env;
    public string $repo;
    public string $sha;
    public string $ref;
    public string $branch;
    public string $run_id;
    public string $archive;
    public array $files;
    public string $signature = 'build-provenance.sigstore.json';

    public function __construct(string $root, ?array $environment = null)
    {
        $resolved = realpath($root);
        if ($resolved === false || !is_dir($resolved)) {
            throw new ReleaseError('Source root does not exist.');
        }
        $this->root = $resolved;
        $this->env = $environment ?? getenv();
        $this->repo = $this->env['GITHUB_REPOSITORY'] ?? '';
        $this->sha = $this->env['GITHUB_SHA'] ?? '';
        $this->ref = $this->env['GITHUB_REF'] ?? '';
        $this->branch = $this->env['DEFAULT_BRANCH'] ?? '';
        $this->run_id = $this->env['QUALITY_RUN_ID'] ?? '';
        if ($this->repo !== 'kumwe/kumwe-engine') {
            throw new ReleaseError('Only the declared Kumwe native PHP binding repository may publish.');
        }
        if (!preg_match('/\A[a-f0-9]{40}\z/D', $this->sha)) {
            throw new ReleaseError('The workflow source must be an exact commit.');
        }
        if (!preg_match('/\A[1-9][0-9]*\z/D', $this->run_id)) {
            throw new ReleaseError('An actual successful quality workflow run is required.');
        }
        if ($this->branch === '' || $this->ref !== 'refs/heads/' . $this->branch) {
            throw new ReleaseError('Only the repository default branch may publish.');
        }
        $this->archive = 'kumwe-engine-php-source.tar.gz';
        $this->files = [$this->archive, 'source.spdx.json', 'source.json', 'source.provenance.json', 'SHA256SUMS'];
    }

    public function command(array $args, ?string $cwd = null, ?string $data = null, bool $check = true): array
    {
        // File-backed streams avoid pipe deadlocks for large provenance responses.
        $input = tmpfile();
        $output = tmpfile();
        $errors = tmpfile();
        if ($input === false || $output === false || $errors === false) {
            foreach ([$input, $output, $errors] as $stream) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            throw new ReleaseError('Cannot allocate command streams.');
        }
        try {
            if ($data !== null && fwrite($input, $data) !== strlen($data)) {
                throw new ReleaseError('Cannot write command input.');
            }
            rewind($input);
            $process = proc_open($args, [0 => $input, 1 => $output, 2 => $errors], $pipes, $cwd ?? $this->root, $this->env);
            if (!is_resource($process)) {
                throw new ReleaseError('Cannot start command: ' . $args[0]);
            }
            $returncode = proc_close($process);
            rewind($output);
            rewind($errors);
            $stdout = stream_get_contents($output);
            $stderr = stream_get_contents($errors);
            if ($stdout === false || $stderr === false) {
                throw new ReleaseError('Cannot read command output.');
            }
            if ($check && $returncode !== 0) {
                throw new ReleaseError('Command failed (' . $args[0] . '): ' . trim($stderr));
            }
            return compact('returncode', 'stdout', 'stderr');
        } finally {
            fclose($input);
            fclose($output);
            fclose($errors);
        }
    }

    public function api(string $endpoint, bool $missing = false, string $method = 'GET', ?array $payload = null): ?array
    {
        $args = ['gh', 'api', '--include', '--method', $method, "repos/{$this->repo}/{$endpoint}"];
        $data = null;
        if ($payload !== null) {
            array_push($args, '--input', '-');
            $data = json_encode($payload, JSON_THROW_ON_ERROR);
        }
        $response = $this->command($args, data: $data, check: false);
        return decode_http($response['stdout'], $response['returncode'], $missing);
    }

    public function check_context(): void
    {
        $this->command(['git', 'check-ref-format', $this->ref]);
        if (trim($this->command(['git', 'rev-parse', 'HEAD'])['stdout']) !== $this->sha) {
            throw new ReleaseError('Checkout does not equal the exact workflow commit.');
        }
        $this->command(['git', 'diff', '--quiet', 'HEAD', '--']);
        $branch = $this->api('branches/' . rawurlencode($this->branch));
        if (($branch['name'] ?? null) !== $this->branch || ($branch['commit']['sha'] ?? null) !== $this->sha) {
            throw new ReleaseError('The default branch advanced; qualify its new exact commit first.');
        }
        $observed = $this->api('actions/runs/' . $this->run_id);
        $expected_name = 'Native binding candidate';
        if (($observed['head_sha'] ?? null) !== $this->sha
            || ($observed['head_branch'] ?? null) !== $this->branch
            || ($observed['head_repository']['full_name'] ?? null) !== $this->repo
            || !in_array($observed['event'] ?? null, ['push', 'workflow_dispatch'], true)
            || ($observed['path'] ?? null) !== '.github/workflows/ci.yml'
            || ($observed['name'] ?? null) !== $expected_name
            || ($observed['status'] ?? null) !== 'completed'
            || ($observed['conclusion'] ?? null) !== 'success') {
            throw new ReleaseError('Quality evidence is not a successful exact-source default-branch native run.');
        }
        $jobs = [];
        $complete = false;
        for ($page = 1; $page <= 100; ++$page) {
            $response = $this->api("actions/runs/{$this->run_id}/jobs?per_page=100&page={$page}");
            $batch = $response['jobs'] ?? null;
            if (!is_array($batch) || !array_is_list($batch) || !is_int($response['total_count'] ?? null)) {
                throw new ReleaseError('Quality job inventory is malformed.');
            }
            array_push($jobs, ...$batch);
            if (count($jobs) >= $response['total_count']) {
                if (count($jobs) !== $response['total_count']) {
                    throw new ReleaseError('Quality job inventory exceeds its declared count.');
                }
                $complete = true;
                break;
            }
            if ($batch === []) {
                throw new ReleaseError('Quality job inventory ended before the declared count.');
            }
        }
        if (!$complete) {
            throw new ReleaseError('Quality job inventory exceeds its bounded verification limit.');
        }
        if ($jobs === []) {
            throw new ReleaseError('Every native quality job must pass; skipped jobs do not qualify.');
        }
        $ids = [];
        $names = [];
        foreach ($jobs as $job) {
            if (!is_array($job) || ($job['status'] ?? null) !== 'completed'
                || ($job['conclusion'] ?? null) !== 'success' || !is_int($job['id'] ?? null)
                || !is_string($job['name'] ?? null)) {
                throw new ReleaseError('Every native quality job must pass; skipped jobs do not qualify.');
            }
            if (isset($ids[$job['id']])) {
                throw new ReleaseError('Quality job inventory repeats an identity.');
            }
            $ids[$job['id']] = true;
            $names[] = $job['name'];
        }
        $required = ['source-release-preparation', 'binding', 'address-undefined-sanitizers',
            'clean-pie', 'whole-boundary-benchmarks'];
        if (array_diff($required, $names) !== []) {
            throw new ReleaseError('A required native quality lane is missing.');
        }
    }

    public function source_bundle(string $action, string $destination, ?string $root = null, ?string $sha = null): array
    {
        $selected_root = $root ?? $this->root;
        $selected_sha = $sha ?? $this->sha;
        $this->command([PHP_BINARY, $selected_root . '/tools/release-source.php', $action,
            $destination, '--expected-commit', $selected_sha, '--require-stable'], cwd: $selected_root);
        $record = json_object(read_bytes($destination . '/source.json'));
        if (($record['package'] ?? null) !== $this->repo
            || ($record['source']['commit'] ?? null) !== $selected_sha
            || ($record['archive']['name'] ?? null) !== $this->archive
            || ($record['stable_source_blockers'] ?? null) !== []) {
            throw new ReleaseError('Stable source bundle differs from the expected repository/source.');
        }
        $version = $record['identity']['version'] ?? '';
        if (!is_string($version) || !preg_match('/\A(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\z/D', $version)
            || $version === '0.0.0') {
            throw new ReleaseError('Only a recorded stable native version may publish.');
        }
        return $record;
    }

    public function release_notes(array $record): string
    {
        $source_url = "https://github.com/{$this->repo}/blob/{$this->sha}";
        $tick = chr(96);
        $lines = ["Source release {$record['identity']['version']} from {$tick}{$this->sha}{$tick}.", '',
            'The attached source archive, complete SPDX inventory and checksums have verified '
                . 'GitHub OIDC source provenance. Independent published-release verification remains separate.', '',
            "[Release procedure]({$source_url}/docs/releasing.md) · "
                . "[Security policy]({$source_url}/SECURITY.md) · [Source changes]({$source_url}/CHANGELOG.md)", '',
            '### Exact dependencies and semantic contracts', ''];
        foreach ($record['dependencies'] ?? [] as $dependency) {
            $version = ($dependency['version'] ?? null) ?: (($dependency['release'] ?? null) ?: 'recorded source');
            $repository = $dependency['repository'] ?? 'unknown';
            $lines[] = "- {$tick}{$repository}{$tick} {$version}, source {$tick}{$dependency['commit']}{$tick}.";
            foreach (['archive_sha256', 'api_digest', 'capability_digest', 'service_map_digest'] as $field) {
                if (!empty($dependency[$field])) {
                    $lines[] = "  - {$field}: {$tick}{$dependency[$field]}{$tick}.";
                }
            }
            $corpora = $dependency['corpus_digests'] ?? [];
            if (!empty($dependency['corpus_path'])) {
                $corpora[$dependency['corpus_path']] = $dependency['corpus_sha256'];
            }
            ksort($corpora, SORT_STRING);
            foreach ($corpora as $corpus => $digest) {
                $lines[] = "  - Corpus {$tick}{$corpus}{$tick}: {$tick}{$digest}{$tick}.";
            }
        }
        $baseline = $record['computation_baseline'] ?? null;
        if ($baseline) {
            array_push($lines, '', "Portable-only prerequisite: {$tick}{$baseline['repository']}{$tick} {$baseline['version']} "
                . "at {$tick}{$baseline['commit']}{$tick}; its exact manifests/corpora and independent evidence are retained in {$tick}source.json{$tick}.");
        }
        array_push($lines, '', '### Supported binding and limits', '',
                'PHP 8.5 NTS, Linux x86_64/glibc, exact PHP patch and configured build tuple. '
                . 'A Runtime owns at most 64 plans and 16 MiB encoded source; released and foreign IDs refuse. '
                . 'Other PHP versions, ZTS and platforms are unsupported.', '',
                "[Complete native PHP API]({$source_url}/resources/api/v1.json) · "
                . "[Handle ownership and transport limits]({$source_url}/docs/memory.md)");
        array_push($lines, '', 'Whole-boundary measurements retain slower native workloads as well as speedups. '
            . 'Source qualification does not claim universal acceleration, App integration or production capacity.', '',
            '### Source changes and security', '');
        if (is_file($this->root . '/CHANGELOG.md')
            && preg_match('/^## Unreleased\s*\n(.*?)(?=^## |\z)/ms', read_bytes($this->root . '/CHANGELOG.md'), $section)) {
            $lines[] = trim($section[1]);
        }
        return rtrim(implode("\n", $lines)) . "\n";
    }

    public function tag_commit(string $tag): ?string
    {
        $observed = $this->api('git/ref/tags/' . $tag, missing: true);
        if ($observed === null) {
            return null;
        }
        for ($depth = 0; $depth < 16; ++$depth) {
            $object = $observed['object'] ?? [];
            $sha = $object['sha'] ?? '';
            if (!is_string($sha) || !preg_match('/\A[a-f0-9]{40}\z/D', $sha)) {
                throw new ReleaseError('Version tag has an invalid object identity.');
            }
            if (($object['type'] ?? null) === 'commit') {
                return $sha;
            }
            if (($object['type'] ?? null) !== 'tag') {
                throw new ReleaseError('Version tag does not identify a commit.');
            }
            $observed = $this->api('git/tags/' . $sha);
        }
        throw new ReleaseError('Annotated tag nesting exceeds the verification limit.');
    }

    public function verify_signatures(string $bundle, string $signature, string $sha): void
    {
        if (!is_file($signature) || is_link($signature)) {
            throw new ReleaseError('The OIDC-signed provenance bundle is missing or unsafe.');
        }
        foreach ($this->files as $name) {
            $this->command(['gh', 'attestation', 'verify', $bundle . '/' . $name, '--repo', $this->repo,
                '--bundle', $signature, '--source-digest', $sha, '--source-ref', $this->ref,
                '--cert-identity', "https://github.com/{$this->repo}/.github/workflows/release.yml@{$this->ref}",
                '--deny-self-hosted-runners']);
        }
    }

    public function download(string $tag, string $name, string $destination): string
    {
        ensure_directory($destination);
        $this->command(['gh', 'release', 'download', $tag, '--repo', $this->repo, '--dir', $destination, '--pattern', $name]);
        $path = $destination . '/' . $name;
        if (!is_file($path) || is_link($path)) {
            throw new ReleaseError('Expected release asset is missing or unsafe: ' . $name);
        }
        return $path;
    }

    public function require_draft(array $release, string $tag, ?int $release_id = null): void
    {
        if (($release['tag_name'] ?? null) !== $tag || ($release['draft'] ?? null) !== true
            || ($release['prerelease'] ?? null) !== false || !is_int($release['id'] ?? null)
            || $release['id'] <= 0 || ($release_id !== null && $release['id'] !== $release_id)) {
            throw new ReleaseError('Only the same matching unpublished stable draft may receive assets.');
        }
    }

    public function asset_names(array $release): array
    {
        $assets = $release['assets'] ?? [];
        if (!is_array($assets) || !array_is_list($assets)) {
            throw new ReleaseError('Release asset inventory is malformed.');
        }
        $names = [];
        foreach ($assets as $entry) {
            if (!is_array($entry) || !is_string($entry['name'] ?? null)) {
                throw new ReleaseError('Release asset inventory is malformed.');
            }
            $names[] = $entry['name'];
        }
        sort($names, SORT_STRING);
        return $names;
    }

    public function expected_assets(): array
    {
        $names = [...$this->files, $this->signature];
        sort($names, SORT_STRING);
        return $names;
    }

    public function verify_draft_assets(string $tag, array $release, string $bundle): void
    {
        if ($this->asset_names($release) !== $this->expected_assets()) {
            throw new ReleaseError('Draft must contain exactly the source bundle and signed provenance before publication.');
        }
        $scratch = temporary_directory('kumwe-native-draft-verify-');
        try {
            foreach ($this->files as $name) {
                $actual = $this->download($tag, $name, $scratch);
                if (read_bytes($actual) !== read_bytes($bundle . '/' . $name)) {
                    throw new ReleaseError('Uploaded draft source asset differs from the verified source: ' . $name);
                }
            }
            $signature = $this->download($tag, $this->signature, $scratch);
            $this->verify_signatures($bundle, $signature, $this->sha);
        } finally {
            remove_directory($scratch);
        }
    }

    public function verify_published(string $tag, array $release, string $expected_version): void
    {
        if (($release['tag_name'] ?? null) !== $tag || ($release['draft'] ?? null) !== false
            || ($release['prerelease'] ?? null) !== false || empty($release['published_at'])) {
            throw new ReleaseError('Existing release is not a published stable record.');
        }
        $tag_sha = $this->tag_commit($tag);
        if ($tag_sha === null) {
            throw new ReleaseError('Published release has no version tag.');
        }
        $this->command(['git', 'merge-base', '--is-ancestor', $tag_sha, $this->sha]);
        if ($this->asset_names($release) !== $this->expected_assets()) {
            throw new ReleaseError('Published release must retain the exact source and signed-provenance assets.');
        }
        $scratch = temporary_directory('kumwe-native-release-verify-');
        try {
            $bundle = $scratch . '/bundle';
            foreach ($this->files as $name) {
                $this->download($tag, $name, $bundle);
            }
            $signature = $this->download($tag, $this->signature, $scratch);
            $source = $scratch . '/source';
            $this->command(['git', 'clone', '--quiet', '--shared', '--no-checkout', $this->root, $source]);
            $this->command(['git', 'checkout', '--quiet', '--detach', $tag_sha], cwd: $source);
            $record = $this->source_bundle('verify', $bundle, root: $source, sha: $tag_sha);
            if ($record['identity']['version'] !== $expected_version) {
                throw new ReleaseError('Published source version does not match its tag.');
            }
            $this->verify_signatures($bundle, $signature, $tag_sha);
        } finally {
            remove_directory($scratch);
        }
        if ($this->tag_commit($tag) !== $tag_sha) {
            throw new ReleaseError('Published tag changed during verification.');
        }
    }

    public function prepare(string $destination): array
    {
        $this->check_context();
        $record = $this->source_bundle('prepare', $destination);
        $version = $record['identity']['version'];
        $tag = 'v' . $version;
        $existing = $this->api('releases/tags/' . $tag, missing: true);
        $already_published = $existing !== null && ($existing['draft'] ?? null) === false;
        if ($already_published) {
            $this->verify_published($tag, $existing, expected_version: $version);
        }
        return ['version' => $version, 'tag' => $tag, 'already_published' => $already_published ? 'true' : 'false'];
    }

    public function publish(string $destination, string $signature): void
    {
        $this->check_context();
        $record = $this->source_bundle('verify', $destination);
        $version = $record['identity']['version'];
        $tag = 'v' . $version;
        $existing = $this->api('releases/tags/' . $tag, missing: true);
        if ($existing !== null && ($existing['draft'] ?? null) === false) {
            $this->verify_published($tag, $existing, expected_version: $version);
            return;
        }
        $this->verify_signatures($destination, $signature, $this->sha);
        $target = $this->tag_commit($tag);
        if ($target !== null && $target !== $this->sha) {
            throw new ReleaseError('An unpublished version tag must identify this exact tested commit.');
        }
        if ($target === null) {
            if ($existing !== null) {
                throw new ReleaseError('A draft release without its expected version tag is inconsistent.');
            }
            $created = $this->api('git/refs', method: 'POST', payload: ['ref' => 'refs/tags/' . $tag, 'sha' => $this->sha]);
            if (($created['ref'] ?? null) !== 'refs/tags/' . $tag
                || ($created['object']['type'] ?? null) !== 'commit'
                || ($created['object']['sha'] ?? null) !== $this->sha) {
                throw new ReleaseError('Created tag differs from the verified commit.');
            }
        }
        if ($existing === null) {
            $existing = $this->api('releases', method: 'POST', payload: [
                'tag_name' => $tag, 'target_commitish' => $this->sha, 'name' => $tag,
                'draft' => true, 'prerelease' => false, 'body' => $this->release_notes($record),
            ]);
        }
        $this->require_draft($existing, $tag);
        $release_id = $existing['id'];
        $scratch = temporary_directory('kumwe-native-release-assets-');
        try {
            foreach ([...$this->files, $this->signature] as $name) {
                $current = $this->api("releases/{$release_id}");
                $this->require_draft($current, $tag, $release_id);
                $this->asset_names($current);
                $matches = array_values(array_filter($current['assets'] ?? [], static fn(array $entry): bool => $entry['name'] === $name));
                if (count($matches) > 1) {
                    throw new ReleaseError('Duplicate release asset identity: ' . $name);
                }
                $expected = $name === $this->signature ? $signature : $destination . '/' . $name;
                if ($matches !== []) {
                    $downloaded = $this->download($tag, $name, $scratch . '/' . str_replace('.', '-', $name));
                    if ($name === $this->signature) {
                        $this->verify_signatures($destination, $downloaded, $this->sha);
                    } elseif (hash('sha256', read_bytes($downloaded), true) !== hash('sha256', read_bytes($expected), true)) {
                        throw new ReleaseError('Existing draft asset differs; it cannot be overwritten: ' . $name);
                    }
                } else {
                    $upload = $scratch . '/' . $name;
                    write_bytes($upload, read_bytes($expected));
                    $this->command(['gh', 'release', 'upload', $tag, $upload, '--repo', $this->repo]);
                }
            }
        } finally {
            remove_directory($scratch);
        }
        $current = $this->api("releases/{$release_id}");
        $this->require_draft($current, $tag, $release_id);
        $this->verify_draft_assets($tag, $current, $destination);
        $this->check_context();
        if ($this->tag_commit($tag) !== $this->sha) {
            throw new ReleaseError('Version tag changed before publication.');
        }
        $this->api("releases/{$release_id}", method: 'PATCH', payload: ['draft' => false]);
        $observed = $this->api('releases/tags/' . $tag);
        $this->verify_published($tag, $observed, expected_version: $version);
    }
}

function absolute_path(string $path): string
{
    if ($path === '') {
        throw new ReleaseError('A nonempty filesystem path is required.');
    }
    return str_starts_with($path, '/') ? $path : getcwd() . '/' . $path;
}

function main(array $arguments): void
{
    $usage = 'Usage: php tools/release-native.php prepare|publish DIRECTORY [--provenance FILE]';
    if ($arguments === ['--help'] || $arguments === ['-h']) {
        echo $usage, "\n";
        return;
    }
    if (count($arguments) < 2 || !in_array($arguments[0], ['prepare', 'publish'], true)) {
        throw new ReleaseError($usage);
    }
    [$action, $directory] = $arguments;
    $provenance = null;
    for ($index = 2; $index < count($arguments); ++$index) {
        if ($arguments[$index] === '--provenance' && isset($arguments[$index + 1]) && $provenance === null) {
            $provenance = $arguments[++$index];
        } else {
            throw new ReleaseError($usage);
        }
    }
    if ($action === 'publish' && $provenance === null) {
        throw new ReleaseError('publish requires --provenance');
    }
    $publisher = new Publisher(dirname(__DIR__));
    if ($action === 'prepare') {
        $outputs = $publisher->prepare(absolute_path($directory));
        $output_file = getenv('GITHUB_OUTPUT');
        if ($output_file !== false && $output_file !== '') {
            $lines = '';
            foreach ($outputs as $key => $value) {
                $lines .= "{$key}={$value}\n";
            }
            if (@file_put_contents($output_file, $lines, FILE_APPEND) !== strlen($lines)) {
                throw new ReleaseError('Cannot write workflow outputs.');
            }
        }
        ksort($outputs, SORT_STRING);
        echo json_encode($outputs, JSON_THROW_ON_ERROR), "\n";
    } else {
        $publisher->publish(absolute_path($directory), absolute_path($provenance));
        echo "Published native source and verified signed provenance; no release attestation was invented.\n";
    }
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    try {
        main(array_slice($argv, 1));
    } catch (\Throwable $error) {
        fwrite(STDERR, 'Native release refused: ' . $error->getMessage() . "\n");
        exit(1);
    }
}
