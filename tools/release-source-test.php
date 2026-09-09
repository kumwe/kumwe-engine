#!/usr/bin/env php
<?php
declare(strict_types=1);

namespace Kumwe\ReleaseSource\Tests;

require_once __DIR__ . '/release-source.php';

use Kumwe\ReleaseSource\ReleaseError;
use function Kumwe\ReleaseSource\{archive_files, binding_sbom, computation_baseline_blockers, decode_object,
    digest, directory_names, encode, engine_materials, kind, process, read_bytes, remove_directory,
    require_stable, run, safe_path, source_facts, source_record, tar_entries, write_bytes};

function check(bool $condition, string $message = 'Assertion failed.'): void
{
    if (!$condition) { throw new \RuntimeException($message); }
}

function same(mixed $expected, mixed $actual): void
{
    check($expected === $actual, 'Values differ: ' . substr(var_export($expected, true), 0, 600)
        . ' versus ' . substr(var_export($actual, true), 0, 600));
}

function refuses(callable $operation, ?string $message = null): void
{
    try { $operation(); }
    catch (ReleaseError $error) {
        if ($message !== null) { check(str_contains($error->getMessage(), $message), $error->getMessage()); }
        return;
    }
    throw new \RuntimeException('Invalid source/evidence unexpectedly accepted.');
}

function tar_member(string $name, string $data = 'x', string $type = '0', string $link = ''): string
{
    check(strlen($name) <= 100, 'Fixture name exceeds header field.');
    $header = str_pad($name, 100, "\0") . sprintf('%07o', 0644) . "\0"
        . sprintf('%07o', 0) . "\0" . sprintf('%07o', 0) . "\0"
        . sprintf('%011o', strlen($data)) . "\0" . sprintf('%011o', 0) . "\0"
        . str_repeat(' ', 8) . $type . str_pad($link, 100, "\0") . "ustar\0" . '00'
        . str_repeat("\0", 32 + 32 + 8 + 8 + 155 + 12);
    same(512, strlen($header));
    $checksum = sprintf('%06o', array_sum(unpack('C*', $header))) . "\0 ";
    $header = substr_replace($header, $checksum, 148, 8);
    return $header . $data . str_repeat("\0", (512 - strlen($data) % 512) % 512);
}

function tar_gzip(string $members): string
{
    $encoded = gzencode($members . str_repeat("\0", 1024), 6, ZLIB_ENCODING_GZIP);
    check(is_string($encoded), 'Fixture compression failed.');
    return $encoded;
}

function pax_record(string $key, string $value): string
{
    $body = $key . '=' . $value . "\n";
    $length = strlen($body) + 2;
    while (strlen((string) $length) + 1 + strlen($body) !== $length) {
        $length = strlen((string) $length) + 1 + strlen($body);
    }
    return $length . ' ' . $body;
}

final class SourceReleaseTests
{
    public string $base;
    public string $repo;
    public string $commit;
    public string $output;
    public array $record;

    public function __construct()
    {
        $this->base = sys_get_temp_dir() . '/kumwe-source-release-tests-' . bin2hex(random_bytes(12));
        check(mkdir($this->base, 0700), 'Cannot create fixture directory.');
        $this->repo = $this->base . '/source';
        check(mkdir($this->repo), 'Cannot create fixture checkout.');
        $source = dirname(__DIR__);
        $exported = run($source, 'git', 'archive', '--format=tar', 'HEAD');
        $seen = [];
        foreach (tar_entries($exported) as $member) {
            $name = rtrim($member['name'], '/');
            check(safe_path($name) && !isset($seen[$name]), 'Unsafe fixture archive.');
            $seen[$name] = true;
            $path = $this->repo . '/' . $name;
            if ($member['type'] === '5') {
                if (!is_dir($path)) { check(mkdir($path, 0777, true), 'Cannot create fixture directory.'); }
                continue;
            }
            check(in_array($member['type'], ['0', "\0"], true), 'Fixture must contain only source files.');
            if (!is_dir(dirname($path))) { check(mkdir(dirname($path), 0777, true), 'Cannot create fixture parent.'); }
            write_bytes($path, $member['data']);
            check(chmod($path, $member['mode'] & 0777), 'Cannot restore fixture file mode.');
        }
        unset($exported, $member);
        $attributes = process($source, ['git', 'show', 'HEAD:.gitattributes']);
        if ($attributes['returncode'] === 0) { write_bytes($this->repo . '/.gitattributes', $attributes['stdout']); }
        write_bytes($this->repo . '/tools/release-source.php', read_bytes(__DIR__ . '/release-source.php'));
        foreach ([['git', 'init', '-q'], ['git', 'config', 'user.email', 'source-fixture@example.invalid'],
            ['git', 'config', 'user.name', 'Source release fixture'], ['git', 'config', 'commit.gpgsign', 'false'],
            ['git', 'add', '.'], ['git', 'commit', '-qm', 'Committed native source fixture']] as $command) {
            run($this->repo, ...$command);
        }
        $this->commit = trim(run($this->repo, 'git', 'rev-parse', 'HEAD'));
        $this->output = $this->base . '/bundle';
        $this->invoke(['prepare', $this->output]);
        $this->record = decode_object(read_bytes($this->output . '/source.json'));
    }

    public function cleanup(): void { remove_directory($this->base); }

    public function invoke(array $arguments, bool $success = true): array
    {
        $result = process($this->repo, [PHP_BINARY, $this->repo . '/tools/release-source.php', ...$arguments]);
        check(($result['returncode'] === 0) === $success,
            $success ? $result['stderr'] : 'Invalid source/evidence unexpectedly accepted: ' . $result['stdout']);
        return $result;
    }

    public function verify(bool $success = true): array
    {
        return $this->invoke(['verify', $this->output, '--expected-commit', $this->commit], $success);
    }

    public function test_reproducible_complete_bundle_and_verification(): void
    {
        $other = $this->base . '/second-bundle';
        $this->invoke(['prepare', $other]);
        same(directory_names($this->output), directory_names($other));
        foreach (directory_names($this->output) as $name) {
            same(read_bytes($this->output . '/' . $name), read_bytes($other . '/' . $name));
        }
        $this->invoke(['verify', $this->output, '--expected-commit', $this->commit,
            '--expected-sha256', $this->record['archive']['sha256']]);
        $inventory = decode_object(read_bytes($this->output . '/source.spdx.json'));
        check($inventory['files'] !== []);
        $statement = decode_object(read_bytes($this->output . '/source.provenance.json'));
        same(false, $statement['predicate']['signed']);
        same(false, $statement['predicate']['release_attestation']);
        same(false, $this->record['publication_performed']);
    }

    public function test_candidate_is_not_a_stable_release(): void
    {
        check($this->record['stable_source_blockers'] !== []);
        $failed = $this->base . '/refused-stable';
        $result = $this->invoke(['prepare', $failed, '--require-stable'], false);
        check(str_contains($result['stderr'], 'Stable source refused'), $result['stderr']);
        check(!file_exists($failed));
        $this->invoke(['verify', $this->output, '--expected-commit', $this->commit, '--require-stable'], false);
    }

    public function test_dirty_source_and_in_tree_evidence_are_refused(): void
    {
        $path = $this->repo . '/LICENSE';
        $original = read_bytes($path);
        try {
            write_bytes($path, $original . "changed\n");
            $result = $this->invoke(['prepare', $this->base . '/dirty-output'], false);
            check(str_contains($result['stderr'], 'Commit tracked source changes'), $result['stderr']);
        } finally { write_bytes($path, $original); }
        $this->invoke(['prepare', $this->repo . '/evidence'], false);
        $alias = $this->base . '/source-alias';
        check(symlink($this->repo, $alias));
        try { $this->invoke(['prepare', $alias . '/nested/evidence'], false); }
        finally { unlink($alias); }
    }

    public function test_independent_source_coordinates_are_required(): void
    {
        $this->invoke(['verify', $this->output], false);
        $this->invoke(['verify', $this->output, '--expected-commit', str_repeat('0', 40)], false);
        $this->invoke(['verify', $this->output, '--expected-commit', $this->commit,
            '--expected-sha256', str_repeat('0', 64)], false);
        $this->invoke(['prepare', $this->base . '/floating-tag', '--tag', 'main'], false);
        $this->invoke(['prepare', $this->output], false);
        $this->invoke(['prepare', $this->base . '/unknown-option', '--invent-release'], false);
    }

    public function test_same_commit_tag_must_match_declared_source_version(): void
    {
        $packageKind = kind($this->repo);
        $archive = read_bytes($this->output . '/' . $this->record['archive']['name']);
        $files = archive_files($archive, $packageKind);
        $path = $packageKind === 'binding' ? 'resources/compatibility/v1.json' : 'resources/capabilities.json';
        $declaration = decode_object($files[$path]);
        $declaration['version'] = '1.0.1';
        $files[$path] = encode($declaration);
        $sbom = read_bytes($this->output . '/source.spdx.json');
        $tags = ['v1.0.0', 'v1.0.1', '1.0.1'];
        try {
            foreach ($tags as $tag) { run($this->repo, 'git', '-c', 'tag.gpgsign=false', 'tag', $tag, $this->commit); }
            refuses(fn() => source_record($this->repo, $this->commit, $files, $archive, $sbom, 'v1.0.0'), 'tag version differs');
            foreach (array_slice($tags, 1) as $tag) {
                $record = source_record($this->repo, $this->commit, $files, $archive, $sbom, $tag);
                same('1.0.1', $record['identity']['version']);
                same($tag, $record['source']['tag']);
            }
        } finally { process($this->repo, ['git', 'tag', '-d', ...$tags]); }
    }

    public function test_each_evidence_artifact_is_bound_to_committed_input(): void
    {
        foreach (['source.json', 'source.provenance.json', 'source.spdx.json', 'SHA256SUMS',
            $this->record['archive']['name']] as $name) {
            $path = $this->output . '/' . $name;
            $original = read_bytes($path);
            try { write_bytes($path, $original . ' '); $this->verify(false); }
            finally { write_bytes($path, $original); }
        }
        $extra = $this->output . '/unrecorded.txt';
        try { write_bytes($extra, 'not in source provenance'); $this->verify(false); }
        finally { unlink($extra); }
    }

    public function test_rehashed_false_attestation_claim_is_still_refused(): void
    {
        $recordPath = $this->output . '/source.json';
        $sumsPath = $this->output . '/SHA256SUMS';
        $originalRecord = read_bytes($recordPath);
        $originalSums = read_bytes($sumsPath);
        try {
            $record = decode_object($originalRecord);
            $record['release_attestation'] = true;
            $changed = encode($record);
            write_bytes($recordPath, $changed);
            $sums = preg_replace('/^[a-f0-9]{64}  source\.json$/m', digest($changed) . '  source.json', $originalSums);
            write_bytes($sumsPath, $sums);
            $result = $this->verify(false);
            check(str_contains($result['stderr'], 'differs from reproducible committed input: source.json'), $result['stderr']);
        } finally { write_bytes($recordPath, $originalRecord); write_bytes($sumsPath, $originalSums); }
    }

    public function test_corpus_and_embedded_source_mismatches_are_refused(): void
    {
        $packageKind = kind($this->repo);
        $files = archive_files(read_bytes($this->output . '/' . $this->record['archive']['name']), $packageKind);
        $prefix = $packageKind === 'binding' ? 'vendor/engine/' : '';
        $path = $prefix . 'resources/abi-manifest.json';
        $damaged = $files;
        $record = decode_object($damaged[$path]);
        $record['files'][array_key_first($record['files'])] = str_repeat('0', 64);
        $damaged[$path] = encode($record);
        refuses(fn() => source_facts($damaged, $packageKind));
        if ($packageKind === 'binding') {
            $lock = decode_object($files['resources/engine-lock.json']);
            if (isset($lock['snapshot'])) {
                $facts = source_facts($files, $packageKind);
                check(str_contains(implode(' ', $facts['stable_source_blockers']), 'transformed embedded source snapshot'));
                same($lock['snapshot'], $facts['dependencies'][0]['snapshot']);
                $lock['snapshot']['upstream_files'] = ['../escape' => str_repeat('a', 64)];
                $files['resources/engine-lock.json'] = encode($lock);
                refuses(fn() => source_facts($files, $packageKind), 'upstream source inventory');
            }
        }
    }

    public function test_symlink_and_duplicate_checksum_evidence_is_refused(): void
    {
        $path = $this->output . '/source.json';
        $original = read_bytes($path);
        $outside = $this->base . '/source-copy.json';
        write_bytes($outside, $original);
        unlink($path);
        try { check(symlink($outside, $path)); $this->verify(false); }
        finally { unlink($path); write_bytes($path, $original); }
        $sums = $this->output . '/SHA256SUMS';
        $originalSums = read_bytes($sums);
        try {
            write_bytes($sums, $originalSums . explode("\n", $originalSums)[0] . "\n");
            $this->verify(false);
        } finally { write_bytes($sums, $originalSums); }
        $alias = $this->base . '/bundle-alias';
        check(symlink($this->output, $alias));
        try { $this->invoke(['verify', $alias, '--expected-commit', $this->commit], false); }
        finally { unlink($alias); }
    }

    public function test_unsafe_archive_paths_links_and_duplicate_members_are_refused(): void
    {
        foreach ([['kumwe-engine/../escape', '0'], ['/absolute', '0'], ['kumwe-engine/link', '2'],
            ['kumwe-engine/hardlink', '1'], ['kumwe-engine/build/cache', '0'], ['kumwe-engine/secret.key', '0'],
            ['kumwe-engine/oracle.php', '0'], ['kumwe-engine/oracle.phar', '0'], ['other/source.c', '0'],
            ['kumwe-engine/./file', '0'], ['kumwe-engine//file', '0'], ['kumwe-engine/back\\slash', '0'],
            ['kumwe-engine/pipe', '6']] as [$name, $type]) {
            refuses(fn() => archive_files(tar_gzip(tar_member($name, $type === '0' ? 'x' : '', $type, '/outside')), 'engine'));
        }
        $member = tar_member('kumwe-engine/duplicate');
        refuses(fn() => archive_files(tar_gzip($member . $member), 'engine'), 'Duplicate archive path');
        refuses(fn() => archive_files(tar_gzip(''), 'engine'), 'empty archive');
        refuses(fn() => archive_files('invalid compressed bytes', 'binding'), 'Invalid compressed');
    }

    public function test_tar_metadata_and_header_integrity_are_verified(): void
    {
        $path = 'kumwe-engine/' . str_repeat('a', 120) . '/source.cpp';
        $pax = tar_member('pax', pax_record('path', $path), 'x');
        same([substr($path, 13) => 'x'], archive_files(tar_gzip($pax . tar_member('placeholder')), 'engine'));
        $global = tar_member('global', pax_record('comment', str_repeat('a', 40)), 'g');
        same(['source.cpp' => 'x'], archive_files(tar_gzip($global . tar_member('kumwe-engine/source.cpp')), 'engine'));
        $gnu = tar_member('././@LongLink', $path . "\0", 'L');
        same([substr($path, 13) => 'x'], archive_files(tar_gzip($gnu . tar_member('placeholder')), 'engine'));
        $unsafe = tar_member('pax', pax_record('path', 'kumwe-engine/../escape'), 'x');
        refuses(fn() => archive_files(tar_gzip($unsafe . tar_member('placeholder')), 'engine'), 'Unsafe archive path');
        $paxDuplicate = tar_member('pax', pax_record('path', 'kumwe-engine/same'), 'x');
        refuses(fn() => archive_files(tar_gzip(tar_member('kumwe-engine/same') . $paxDuplicate . tar_member('placeholder')), 'engine'),
            'Duplicate archive path');
        $member = tar_member('kumwe-engine/source.cpp');
        $broken = substr_replace($member, 'z', 0, 1);
        refuses(fn() => archive_files(tar_gzip($broken), 'engine'), 'header checksum');
        refuses(fn() => tar_entries(substr($member, 0, 513)), 'Truncated TAR padding');
        refuses(fn() => tar_entries($member), 'Incomplete TAR');
        refuses(fn() => tar_entries($member . str_repeat("\0", 1024) . 'hidden'), 'trailing data');
        refuses(fn() => archive_files(tar_gzip(tar_member('pax', "999 path=bad\n", 'x') . $member), 'engine'), 'extended record');
    }

    public function test_source_json_preserves_empty_objects_and_unicode(): void
    {
        $input = "{\n  \"empty\": {},\n  \"list\": [],\n  \"text\": \"Grüße / Kumwe\",\n  \"nested\": {\n    \"value\": 1\n  }\n}\n";
        same($input, encode(decode_object($input)));
        refuses(fn() => decode_object('[]'), 'JSON must be an object');
        $numeric = "{\n  \"map\": {\n    \"0\": \"x\"\n  }\n}\n";
        same($numeric, encode(decode_object($numeric)));
        refuses(fn() => decode_object('{"0":"x"}'), 'named object fields');
        $invalid = ['resources/capabilities.json' => encode(['version' => '1.0.0', 'abi_status' => 'stable',
            'semantic_release_verified' => true, 'corpora' => []]),
            'resources/abi-manifest.json' => encode(['status' => 'stable', 'files' => []]),
            'resources/contracts.json' => encode(['state' => 'release-verified', 'abi_frozen' => true, 'modules' => []])];
        refuses(fn() => engine_materials($invalid), 'Expected a JSON object');
        refuses(fn() => require_stable([]), 'explicit blocker inventory');
    }
}

final class ComputationBaselineTests
{
    public function fixture(): array
    {
        return ['state' => 'release-verified', 'repository' => 'kumwe/computation',
            'version' => '1.0.0', 'release' => 'v1.0.0', 'commit' => str_repeat('a', 40),
            'archive_sha256' => str_repeat('b', 64), 'api_digest' => str_repeat('c', 64),
            'capability_digest' => str_repeat('d', 64), 'corpus_digests' => ['resources/corpus/v1.json' => str_repeat('e', 64)],
            'runtime_requirements' => ['php' => '^8.5'], 'native_bindings_present' => false,
            'attestation' => ['uri' => 'https://example.invalid/evidence/baseline.yaml', 'sha256' => str_repeat('f', 64)]];
    }

    public function test_verified_flags_cannot_replace_external_owner_evidence(): void
    {
        $release = ['commit' => str_repeat('a', 40), 'corpus_sha256' => str_repeat('b', 64)];
        $contracts = ['state' => 'release-verified', 'abi_frozen' => true, 'computation_baseline' => $this->fixture(),
            'modules' => [['release_verified' => true, 'corpus_sha256' => str_repeat('b', 64), 'semantic_release' => $release]]];
        $files = ['resources/capabilities.json' => encode(['version' => '1.0.0', 'abi_status' => 'frozen',
            'semantic_release_verified' => true, 'corpora' => []]),
            'resources/abi-manifest.json' => encode(['status' => 'frozen', 'files' => (object) []])];
        foreach ([null, (object) [], ['uri' => 'https://example.invalid/evidence'],
            ['uri' => 'file:///private/evidence', 'sha256' => str_repeat('c', 64)],
            ['uri' => 'https://example.invalid/evidence', 'sha256' => 'not-a-digest']] as $evidence) {
            $contracts['modules'][0]['semantic_release']['external_attestation'] = $evidence;
            $files['resources/contracts.json'] = encode($contracts);
            $blockers = engine_materials($files)[3];
            same(1, count($blockers));
            refuses(fn() => require_stable(['stable_source_blockers' => $blockers]), 'external attestation URI and SHA256');
        }
        $contracts['modules'][0]['semantic_release']['external_attestation'] =
            ['uri' => 'https://example.invalid/evidence', 'sha256' => str_repeat('c', 64)];
        $files['resources/contracts.json'] = encode($contracts);
        same([], engine_materials($files)[3]);
        foreach (['corpus', 'preparation_corpus'] as $key) {
            $invalid = $contracts;
            $invalid['modules'][0][$key] = null;
            $invalid['modules'][0][$key . '_sha256'] = str_repeat('b', 64);
            $files['resources/contracts.json'] = encode($invalid);
            refuses(fn() => engine_materials($files), 'Malformed semantic corpus path');
        }
    }

    public function test_missing_baseline_alone_refuses_otherwise_stable_engine_and_embedding(): void
    {
        foreach (['', 'vendor/engine/'] as $prefix) {
            $files = [$prefix . 'resources/capabilities.json' => encode(['version' => '1.0.0', 'abi_status' => 'stable',
                'semantic_release_verified' => true, 'corpora' => []]),
                $prefix . 'resources/abi-manifest.json' => encode(['status' => 'stable', 'files' => (object) []]),
                $prefix . 'resources/contracts.json' => encode(['state' => 'release-verified', 'abi_frozen' => true, 'modules' => []])];
            $blockers = engine_materials($files, $prefix)[3];
            same(1, count($blockers));
            refuses(fn() => require_stable(['stable_source_blockers' => $blockers]), 'portable-only Computation Phase 1A');
        }
        foreach ([null, (object) [], [], ['state' => 'unresolved'], ['state' => 'package-released']] as $value) {
            check(computation_baseline_blockers($value) !== []);
        }
    }

    public function test_baseline_requires_exact_portable_identity_and_external_evidence_reference(): void
    {
        same([], computation_baseline_blockers($this->fixture()));
        $corruptions = ['repository' => 'kumwe/engine', 'version' => '1.0.1', 'release' => 'main',
            'commit' => 'main', 'archive_sha256' => null, 'api_digest' => null, 'capability_digest' => null,
            'corpus_digests' => ['resources/../corpus.json' => str_repeat('e', 64)],
            'runtime_requirements' => ['php' => '^8.5', 'ext-kumwe_engine' => '0.0.0-dev'],
            'native_bindings_present' => true, 'attestation' => null];
        foreach ($corruptions as $field => $value) {
            $baseline = $this->fixture();
            $baseline[$field] = $value;
            check(computation_baseline_blockers($baseline) !== [], 'Accepted corrupt baseline field: ' . $field);
        }
        foreach (array_keys($this->fixture()) as $field) {
            $baseline = $this->fixture();
            unset($baseline[$field]);
            check(computation_baseline_blockers($baseline) !== [], 'Accepted absent baseline field: ' . $field);
        }
        foreach ([[], (object) [], ['resources/corpus/v1.json' => 'main']] as $corpus) {
            $baseline = $this->fixture();
            $baseline['corpus_digests'] = $corpus;
            check(computation_baseline_blockers($baseline) !== []);
        }
        foreach (['ext-kumwe_engine', 'EXT-KUMWE_ENGINE', 'kumwe/engine', 'kumwe/kumwe-engine'] as $dependency) {
            $baseline = $this->fixture();
            $baseline['runtime_requirements'][$dependency] = '1.0.0';
            check(computation_baseline_blockers($baseline) !== []);
        }
    }
}

$source = null;
try {
    $source = new SourceReleaseTests();
    $count = 0;
    foreach ([$source, new ComputationBaselineTests()] as $suite) {
        foreach (get_class_methods($suite) as $method) {
            if (!str_starts_with($method, 'test_')) { continue; }
            $suite->$method();
            ++$count;
            echo 'PASS ' . $method . "\n";
        }
    }
    echo $count . " source release tests passed.\n";
} catch (\Throwable $error) {
    fwrite(STDERR, 'Source release tests failed: ' . $error->getMessage() . "\n" . $error->getTraceAsString() . "\n");
    exit(1);
} finally { if ($source !== null) { $source->cleanup(); } }
