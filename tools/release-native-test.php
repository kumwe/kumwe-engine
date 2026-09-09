<?php
declare(strict_types=1);

namespace Kumwe\ReleaseNative\Tests;

require_once __DIR__ . '/release-native.php';

use Kumwe\ReleaseNative\Publisher;
use Kumwe\ReleaseNative\ReleaseError;
use function Kumwe\ReleaseNative\decode_http;
use function Kumwe\ReleaseNative\ensure_directory;
use function Kumwe\ReleaseNative\read_bytes;
use function Kumwe\ReleaseNative\remove_directory;
use function Kumwe\ReleaseNative\temporary_directory;
use function Kumwe\ReleaseNative\write_bytes;

const SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const OTHER = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
const BINDING_JOBS = [
    'source-release-preparation', 'binding', 'address-undefined-sanitizers',
    'clean-pie', 'whole-boundary-benchmarks',
];

function environment(array $replace = []): array
{
    return array_replace([
        'GITHUB_REPOSITORY' => 'kumwe/kumwe-engine', 'GITHUB_SHA' => SHA,
        'GITHUB_REF' => 'refs/heads/main', 'DEFAULT_BRANCH' => 'main', 'QUALITY_RUN_ID' => '123',
    ], $replace);
}

/** Fake process/API/source-tool boundaries; exercise the real publication orchestration. */
class Fixture extends Publisher
{
    public array $calls = [];
    public array $assets = [];
    public ?string $tag = null;
    public ?array $release = null;
    public string $head;
    public string $branch_head;
    public bool $signature_valid = true;
    public bool $stable = true;
    public bool $ancestor = true;
    public ?string $fail_upload = null;
    public bool $extra_before_finalize = false;
    public bool $advance_before_finalize = false;
    public int $context_calls = 0;
    public array $quality;
    public array $jobs;

    public function __construct(string $root, ?array $environment = null)
    {
        parent::__construct($root, $environment ?? environment());
        $this->head = $this->branch_head = $this->sha;
        $this->quality = [
            'head_sha' => $this->sha, 'head_branch' => $this->branch,
            'head_repository' => ['full_name' => $this->repo], 'event' => 'push',
            'path' => '.github/workflows/ci.yml', 'name' => 'Native binding candidate',
            'status' => 'completed', 'conclusion' => 'success',
        ];
        $this->jobs = [];
        foreach (BINDING_JOBS as $index => $name) {
            $this->jobs[] = ['id' => $index + 1, 'name' => $name, 'status' => 'completed', 'conclusion' => 'success'];
        }
    }

    public function command(array $args, ?string $cwd = null, ?string $data = null, bool $check = true): array
    {
        $this->calls[] = ['command', $args];
        $output = '';
        if (array_slice($args, 0, 3) === ['git', 'rev-parse', 'HEAD']) {
            $output = $this->head;
        }
        if (array_slice($args, 0, 3) === ['git', 'merge-base', '--is-ancestor'] && !$this->ancestor) {
            throw new ReleaseError('Unrelated published commit');
        }
        if (array_slice($args, 0, 3) === ['git', 'clone', '--quiet']) {
            ensure_directory($args[count($args) - 1]);
        }
        if (array_slice($args, 0, 3) === ['gh', 'attestation', 'verify']) {
            $signature = $args[array_search('--bundle', $args, true) + 1];
            $digest = $args[array_search('--source-digest', $args, true) + 1];
            if (!$this->signature_valid || read_bytes($signature) !== 'signed:' . $digest) {
                throw new ReleaseError('Invalid signature/source identity');
            }
        }
        if (array_slice($args, 0, 3) === ['gh', 'release', 'upload']) {
            $path = $args[4];
            $name = basename($path);
            if ($name === $this->fail_upload) {
                throw new ReleaseError('Interrupted asset transfer');
            }
            if (array_key_exists($name, $this->assets)) {
                throw new ReleaseError('Immutable upload collision');
            }
            $this->assets[$name] = read_bytes($path);
            if ($this->extra_before_finalize && $name === $this->signature) {
                $this->assets['unexpected.bin'] = 'unreviewed';
            }
            if ($this->advance_before_finalize && $name === $this->signature) {
                $this->branch_head = OTHER;
            }
        }
        return ['returncode' => 0, 'stdout' => $output, 'stderr' => ''];
    }

    public function api(string $endpoint, bool $missing = false, string $method = 'GET', ?array $payload = null): ?array
    {
        $this->calls[] = ['api', $method, $endpoint, $payload];
        if (str_starts_with($endpoint, 'branches/')) {
            ++$this->context_calls;
            return ['name' => $this->branch, 'commit' => ['sha' => $this->branch_head]];
        }
        if ($endpoint === 'actions/runs/' . $this->run_id) {
            return $this->quality;
        }
        if (str_starts_with($endpoint, 'actions/runs/' . $this->run_id . '/jobs?')) {
            return ['total_count' => count($this->jobs), 'jobs' => $this->jobs];
        }
        if (str_starts_with($endpoint, 'git/ref/tags/')) {
            return $this->tag === null ? null : ['object' => ['type' => 'commit', 'sha' => $this->tag]];
        }
        if ($endpoint === 'git/refs' && $method === 'POST') {
            if ($this->tag !== null) {
                throw new ReleaseError('Tag creation collision');
            }
            $this->tag = $payload['sha'];
            return ['ref' => $payload['ref'], 'object' => ['type' => 'commit', 'sha' => $this->tag]];
        }
        if ($endpoint === 'releases' && $method === 'POST') {
            $this->release = array_replace($payload, ['id' => 42]);
        } elseif ($endpoint === 'releases/42' && $method === 'PATCH') {
            $this->release = array_replace($this->release, $payload);
            $this->release['published_at'] = '2026-09-08T00:00:00Z';
        } elseif (!str_starts_with($endpoint, 'releases/tags/') && $endpoint !== 'releases/42') {
            throw new \RuntimeException('Unexpected API request: ' . $endpoint);
        }
        if ($this->release === null) {
            if ($missing) {
                return null;
            }
            throw new ReleaseError('Missing release');
        }
        return array_replace($this->release, [
            'assets' => array_map(static fn(string $name): array => ['name' => $name], array_keys($this->assets)),
        ]);
    }

    public function source_bundle(string $action, string $destination, ?string $root = null, ?string $sha = null): array
    {
        if (!$this->stable) {
            throw new ReleaseError('Stable source prerequisite refused');
        }
        if ($action === 'prepare') {
            if (file_exists($destination)) {
                throw new ReleaseError('Fixture bundle already exists');
            }
            ensure_directory($destination);
            foreach ($this->files as $name) {
                write_bytes($destination . '/' . $name, 'source:' . $name);
            }
            $record = ['identity' => ['version' => '1.0.0'], 'source' => ['commit' => $sha ?? $this->sha]];
            write_bytes($destination . '/source.json', json_encode($record, JSON_THROW_ON_ERROR));
        }
        $record = json_decode(read_bytes($destination . '/source.json'), true, 512, JSON_THROW_ON_ERROR);
        if ($record['source']['commit'] !== ($sha ?? $this->sha)) {
            throw new ReleaseError('Source helper rejected commit mismatch');
        }
        return $record;
    }

    public function download(string $tag, string $name, string $destination): string
    {
        $this->calls[] = ['download', $tag, $name];
        ensure_directory($destination);
        $path = $destination . '/' . $name;
        if (!array_key_exists($name, $this->assets)) {
            throw new ReleaseError('Missing asset');
        }
        write_bytes($path, $this->assets[$name]);
        return $path;
    }

    public function mutations(): array
    {
        return array_values(array_filter($this->calls, static fn(array $call): bool =>
            ($call[0] === 'api' && $call[1] !== 'GET')
            || ($call[0] === 'command' && array_slice($call[1], 0, 3) === ['gh', 'release', 'upload'])));
    }
}

class Context
{
    public string $root;
    public Fixture $publisher;
    public string $bundle;
    public string $signature;

    public function __construct()
    {
        $this->root = temporary_directory('kumwe-native-test-');
        $this->publisher = new Fixture($this->root);
        $this->bundle = $this->root . '/bundle';
        $this->signature = $this->root . '/signed.json';
        write_bytes($this->signature, 'signed:' . SHA);
    }

    public function prepare(): array
    {
        return $this->publisher->prepare($this->bundle);
    }

    public function publish(): void
    {
        $this->publisher->publish($this->bundle, $this->signature);
    }
}

function same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException(($message === '' ? 'Values differ' : $message)
            . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function check(bool $value, string $message): void
{
    if (!$value) {
        throw new \RuntimeException($message);
    }
}

function refuses(callable $operation, ?string $message = null): void
{
    try {
        $operation();
    } catch (ReleaseError $error) {
        if ($message !== null && !str_contains($error->getMessage(), $message)) {
            throw new \RuntimeException('Wrong refusal: ' . $error->getMessage(), 0, $error);
        }
        return;
    }
    throw new \RuntimeException('Expected release refusal');
}

$tests = [];

$tests['release_notes_bind_actual_source_and_semantic_materials'] = static function (Context $t): void {
    write_bytes($t->root . '/CHANGELOG.md', "# Changes\n\n## Unreleased\n\n- Security fixture change.\n");
    $record = [
        'identity' => ['version' => '1.0.0'], 'source' => ['commit' => SHA],
        'dependencies' => [[
            'repository' => 'kumwe/conversion', 'version' => '0.1.5', 'commit' => OTHER,
            'api_digest' => str_repeat('c', 64), 'corpus_path' => 'resources/corpus/v1.json',
            'corpus_sha256' => str_repeat('d', 64),
        ]],
    ];
    $notes = $t->publisher->release_notes($record);
    foreach ([SHA, OTHER, 'kumwe/conversion', '0.1.5', str_repeat('c', 64), str_repeat('d', 64),
        'Security fixture change.', 'Independent published-release verification remains separate',
        'PHP 8.5 NTS, Linux x86_64/glibc'] as $expected) {
        check(str_contains($notes, $expected), 'Missing release note material: ' . $expected);
    }
    check(!str_contains($notes, '/blob/main/'), 'Release notes must pin source links');
};

$tests['actual_http_status_required'] = static function (Context $t): void {
    same(null, decode_http("HTTP/2.0 404 Not Found\r\n\r\n{\"message\":\"missing\"}", 1, true));
    foreach ([
        ["HTTP/2.0 403 Forbidden\n\n{\"message\":\"404\"}", 1],
        ["HTTP/2.0 500 Error\n\n{}", 1], ['404 Not Found', 1],
        ["HTTP/2.0 404 Not Found\n\n{}", 0], ["HTTP/2.0 200 OK\n\n[]", 0],
        ["HTTP/2.0 200 OK\n\nnot-json", 0], ["HTTP/2.0 200 OK\n\nnull", 0],
        ["HTTP/2.0 200 OK", 0],
    ] as [$raw, $code]) {
        refuses(static fn() => decode_http($raw, $code, true));
    }
    same([], decode_http("HTTP/2.0 201 Created\n\n{}", 0));
    same(['message' => 'ready'], decode_http("HTTP/2 200 OK\r\n\r\n{\"message\":\"ready\"}", 0));
};

$tests['context_refuses_untrusted_or_inexact_inputs'] = static function (Context $t): void {
    foreach ([
        ['GITHUB_REPOSITORY', 'fork/engine'], ['GITHUB_REPOSITORY', 'kumwe/engine'],
        ['GITHUB_SHA', 'main'], ['QUALITY_RUN_ID', '0'],
        ['GITHUB_REF', 'refs/heads/feature'], ['DEFAULT_BRANCH', ''],
    ] as [$key, $value]) {
        refuses(static fn() => new Fixture($t->root, environment([$key => $value])));
    }
};

$tests['dynamic_default_branch_is_supported'] = static function (Context $t): void {
    $publisher = new Fixture($t->root, environment([
        'DEFAULT_BRANCH' => 'stable/source', 'GITHUB_REF' => 'refs/heads/stable/source',
    ]));
    $publisher->check_context();
    check(count(array_filter($publisher->calls, static fn(array $call): bool =>
        array_slice($call, 0, 3) === ['api', 'GET', 'branches/stable%2Fsource'])) === 1,
        'Default branch must be URL encoded');
};

$tests['full_binding_quality_inventory_passes'] = static function (Context $t): void {
    $t->publisher->check_context();
    same([], $t->publisher->mutations());
};

$tests['wrong_quality_evidence_never_mutates'] = static function (Context $t): void {
    foreach ([
        ['head_sha', OTHER], ['head_branch', 'feature'], ['event', 'pull_request'],
        ['head_repository', ['full_name' => 'fork/engine']], ['conclusion', 'failure'],
        ['status', 'in_progress'], ['path', '.github/workflows/other.yml'], ['name', 'Other'],
    ] as [$key, $value]) {
        $publisher = new Fixture($t->root);
        $publisher->quality[$key] = $value;
        refuses(static fn() => $publisher->prepare($t->root . '/missing-' . $key));
        same([], $publisher->mutations());
    }
};

$tests['checkout_and_current_default_branch_must_match'] = static function (Context $t): void {
    foreach (['head', 'branch_head'] as $attribute) {
        $publisher = new Fixture($t->root);
        $publisher->$attribute = OTHER;
        refuses(static fn() => $publisher->check_context());
    }
};

$tests['skipped_missing_duplicate_substituted_or_malformed_lane_refused'] = static function (Context $t): void {
    foreach (['skip', 'missing', 'duplicate', 'substitute', 'boolean-id', 'name-type'] as $mode) {
        $publisher = new Fixture($t->root);
        if ($mode === 'skip') {
            $publisher->jobs[0]['conclusion'] = 'skipped';
        } elseif ($mode === 'missing') {
            array_pop($publisher->jobs);
        } elseif ($mode === 'duplicate') {
            $publisher->jobs[] = $publisher->jobs[0];
        } elseif ($mode === 'boolean-id') {
            $publisher->jobs[0]['id'] = true;
        } elseif ($mode === 'name-type') {
            $publisher->jobs[0]['name'] = 42;
        } else {
            $publisher->jobs[2]['name'] = 'other-sanitizers';
        }
        refuses(static fn() => $publisher->check_context());
    }
};

$tests['stable_source_refusal_precedes_publication'] = static function (Context $t): void {
    $t->publisher->stable = false;
    refuses(static fn() => $t->prepare());
    same([], $t->publisher->mutations());
};

$tests['prepare_has_no_mutations'] = static function (Context $t): void {
    same(['version' => '1.0.0', 'tag' => 'v1.0.0', 'already_published' => 'false'], $t->prepare());
    same([], $t->publisher->mutations());
};

$tests['new_release_verifies_every_signed_source_before_finalizing'] = static function (Context $t): void {
    $t->prepare();
    $t->publish();
    same(false, $t->publisher->release['draft']);
    same(SHA, $t->publisher->tag);
    $assets = array_keys($t->publisher->assets);
    sort($assets, SORT_STRING);
    same($t->publisher->expected_assets(), $assets);
    $signed = [];
    foreach ($t->publisher->calls as $call) {
        if (in_array($call, $t->publisher->mutations(), true)) {
            break;
        }
        if ($call[0] === 'command' && array_slice($call[1], 0, 3) === ['gh', 'attestation', 'verify']) {
            $signed[] = $call;
        }
    }
    same(count($t->publisher->files), count($signed));
    foreach ($signed as $call) {
        foreach (['--deny-self-hosted-runners', '--cert-identity', '--source-digest', '--source-ref'] as $flag) {
            check(in_array($flag, $call[1], true), 'Missing signature verification flag: ' . $flag);
        }
    }
    same(3, $t->publisher->context_calls);
};

$tests['invalid_signature_cannot_create_tag'] = static function (Context $t): void {
    $t->prepare();
    $t->publisher->signature_valid = false;
    refuses(static fn() => $t->publish());
    same([], $t->publisher->mutations());
};

$tests['existing_unpublished_tag_cannot_move'] = static function (Context $t): void {
    $t->prepare();
    $t->publisher->tag = OTHER;
    refuses(static fn() => $t->publish());
    same([], $t->publisher->mutations());
    same(OTHER, $t->publisher->tag);
};

$tests['interrupted_draft_retries_missing_assets_without_replacement'] = static function (Context $t): void {
    $t->prepare();
    $t->publisher->fail_upload = 'source.json';
    refuses(static fn() => $t->publish());
    same(true, $t->publisher->release['draft']);
    $assets_before = $t->publisher->assets;
    $t->publisher->fail_upload = null;
    $t->publisher->calls = [];
    $t->publish();
    $uploads = [];
    foreach ($t->publisher->mutations() as $call) {
        if ($call[0] === 'command') {
            $uploads[] = basename($call[1][4]);
        }
    }
    foreach ($assets_before as $name => $value) {
        same($value, $t->publisher->assets[$name]);
        check(!in_array($name, $uploads, true), 'An existing draft asset was uploaded again');
    }
    same(false, $t->publisher->release['draft']);
};

$tests['existing_draft_asset_mismatch_is_never_overwritten'] = static function (Context $t): void {
    $t->prepare();
    $t->publisher->tag = SHA;
    $t->publisher->release = ['id' => 42, 'tag_name' => 'v1.0.0', 'draft' => true, 'prerelease' => false];
    $t->publisher->assets[$t->publisher->archive] = 'changed';
    refuses(static fn() => $t->publish());
    same([], $t->publisher->mutations());
    same('changed', $t->publisher->assets[$t->publisher->archive]);
};

$tests['unexpected_asset_prevents_final_publication'] = static function (Context $t): void {
    $t->prepare();
    $t->publisher->extra_before_finalize = true;
    refuses(static fn() => $t->publish());
    same(true, $t->publisher->release['draft']);
    same([], array_values(array_filter($t->publisher->calls, static fn(array $call): bool =>
        array_slice($call, 0, 2) === ['api', 'PATCH'])));
};

$tests['default_branch_advance_prevents_final_publication'] = static function (Context $t): void {
    $t->prepare();
    $t->publisher->advance_before_finalize = true;
    refuses(static fn() => $t->publish());
    same(true, $t->publisher->release['draft']);
};

$tests['published_release_recheck_performs_no_mutations'] = static function (Context $t): void {
    $t->prepare();
    $t->publish();
    $t->publisher->calls = [];
    $t->publish();
    same([], $t->publisher->mutations());
};

$tests['published_signature_corruption_cannot_be_repaired_in_place'] = static function (Context $t): void {
    $t->prepare();
    $t->publish();
    $t->publisher->assets[$t->publisher->signature] = 'unsigned';
    $t->publisher->calls = [];
    refuses(static fn() => $t->publish());
    same([], $t->publisher->mutations());
};

$tests['published_source_identity_corruption_cannot_be_repaired_in_place'] = static function (Context $t): void {
    $t->prepare();
    $t->publish();
    $record = json_decode($t->publisher->assets['source.json'], true, 512, JSON_THROW_ON_ERROR);
    $record['source']['commit'] = OTHER;
    $t->publisher->assets['source.json'] = json_encode($record, JSON_THROW_ON_ERROR);
    $t->publisher->calls = [];
    refuses(static fn() => $t->publish());
    same([], $t->publisher->mutations());
};

$tests['published_unrelated_tag_refused'] = static function (Context $t): void {
    $t->prepare();
    $t->publish();
    $t->publisher->ancestor = false;
    $t->publisher->calls = [];
    refuses(static fn() => $t->publish());
    same([], $t->publisher->mutations());
};

$tests['published_missing_asset_refused_without_recreation'] = static function (Context $t): void {
    $t->prepare();
    $t->publish();
    unset($t->publisher->assets['SHA256SUMS']);
    $t->publisher->calls = [];
    refuses(static fn() => $t->publish());
    same([], $t->publisher->mutations());
};

$tests['draft_identity_state_cannot_change'] = static function (Context $t): void {
    foreach ([
        ['id', 0], ['id', true], ['id', 99], ['tag_name', 'v2.0.0'], ['draft', false], ['prerelease', true],
    ] as [$field, $value]) {
        $release = array_replace(['id' => 42, 'tag_name' => 'v1.0.0', 'draft' => true, 'prerelease' => false], [$field => $value]);
        refuses(static fn() => $t->publisher->require_draft($release, 'v1.0.0', 42));
    }
};

$tests['binding_uses_its_own_archive_and_repo_identity'] = static function (Context $t): void {
    $t->prepare();
    $t->publish();
    check(isset($t->publisher->assets['kumwe-engine-php-source.tar.gz']), 'Binding archive missing');
    check(!isset($t->publisher->assets['kumwe-engine-source.tar.gz']), 'Unexpected standalone Engine archive');
};

$tests['command_preserves_binary_input_output_and_exit_status'] = static function (Context $t): void {
    $publisher = new Publisher($t->root, environment());
    $payload = str_repeat("binary\0payload\n", 10000);
    $result = $publisher->command([PHP_BINARY, '-r',
        'fwrite(STDOUT, stream_get_contents(STDIN)); fwrite(STDERR, "diagnostic"); exit(7);'],
        data: $payload, check: false);
    same($payload, $result['stdout']);
    same('diagnostic', $result['stderr']);
    same(7, $result['returncode']);
    refuses(static fn() => $publisher->command([PHP_BINARY, '-r', 'fwrite(STDERR, "refused"); exit(9);']), 'refused');
};

$tests['signature_links_are_refused_before_process_execution'] = static function (Context $t): void {
    $link = $t->root . '/signature-link.json';
    if (!symlink($t->signature, $link)) {
        throw new \RuntimeException('Cannot create signature-link fixture');
    }
    refuses(static fn() => $t->publisher->verify_signatures($t->bundle, $link, SHA));
    same([], $t->publisher->calls);
};

$failed = 0;
foreach ($tests as $name => $test) {
    $context = new Context();
    try {
        $test($context);
        echo "PASS {$name}\n";
    } catch (\Throwable $error) {
        ++$failed;
        fwrite(STDERR, "FAIL {$name}: {$error->getMessage()}\n");
    } finally {
        remove_directory($context->root);
    }
}
echo count($tests) . " native publication regressions, {$failed} failures.\n";
exit($failed === 0 ? 0 : 1);
