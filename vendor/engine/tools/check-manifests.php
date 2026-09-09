#!/usr/bin/env php
<?php
declare(strict_types=1);

/** Validate the native ABI, corpus manifests and discovered test ownership. */
function manifestRequire(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function manifestRead(string $path): string
{
    $content = file_get_contents($path);
    manifestRequire($content !== false, 'Cannot read ' . $path);
    return $content;
}

function manifestJson(string $content): array
{
    $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    manifestRequire(is_array($data), 'Expected a JSON object or array.');
    return $data;
}

function manifestNormalize(mixed $value): mixed
{
    if (is_array($value)) {
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = manifestNormalize($item);
        }
    }
    return $value;
}

function manifestEqual(mixed $actual, mixed $expected, string $message): void
{
    manifestRequire(manifestNormalize($actual) === manifestNormalize($expected), $message);
}

function manifestMatch(mixed $value, string $pattern, string $message): void
{
    manifestRequire(is_string($value) && preg_match($pattern, $value) === 1, $message);
}

function manifestNonempty(mixed $value): bool
{
    return is_string($value) && trim($value) !== '';
}

function manifestRepositoryFile(string $root, mixed $path): void
{
    manifestRequire(is_string($path) && $path !== '' && !str_starts_with($path, '/') && !str_contains($path, '\\'),
        'Ownership requires a relative repository file.');
    $parts = explode('/', $path);
    $target = $root;
    foreach ($parts as $part) {
        manifestRequire($part !== '' && $part !== '.' && $part !== '..', 'Noncanonical repository path: ' . $path);
        $target .= DIRECTORY_SEPARATOR . $part;
        clearstatcache(true, $target);
        $stat = @lstat($target);
        manifestRequire($stat !== false && ($stat['mode'] & 0170000) !== 0120000,
            'Missing file or symlink in repository path: ' . $path);
    }
    manifestRequire(is_file($target) && realpath($target) === $root . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts),
        'Ownership must name a regular repository file: ' . $path);
}

function manifestDigest(string $path, mixed $expected, string $message): void
{
    manifestMatch($expected, '/^[a-f0-9]{64}$/D', 'Invalid expected SHA-256: ' . $path);
    manifestEqual(hash_file('sha256', $path), $expected, $message);
}

/** @param list<string> $command */
function manifestCommand(array $command): string
{
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes);
    manifestRequire(is_resource($process), 'Cannot start ' . $command[0]);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $status = proc_close($process);
    manifestRequire($output !== false && $status === 0, 'Command failed: ' . implode(' ', $command));
    return $output;
}

/** @param list<string> $abi
 *  @param array<string, bool> $names
 */
function manifestOwnership(array $data, array $abi, array $names, string $root): void
{
    manifestEqual($data['schema'] ?? null, 'kumwe-test-ownership/v1', 'Invalid test ownership schema.');
    manifestEqual($data['package'] ?? null, 'kumwe/engine', 'Invalid test ownership package.');
    manifestEqual($data['api_manifest'] ?? null, 'resources/abi-symbols.txt', 'Invalid API manifest owner.');
    $exports = $data['exports'] ?? null;
    manifestRequire(is_array($exports), 'Missing ABI export ownership.');
    $exportNames = array_keys($exports);
    sort($exportNames, SORT_STRING);
    manifestEqual($exportNames, $abi, 'Every public function must have exactly one manifest owner.');
    $tests = static function (mixed $list) use ($names): void {
        manifestRequire(is_array($list) && array_is_list($list) && $list !== [], 'Owned test lists must be nonempty arrays.');
        foreach ($list as $name) {
            manifestRequire(is_string($name) && isset($names[$name]), 'Unknown discovered CTest ' . json_encode($name));
        }
        manifestEqual(count(array_unique($list, SORT_STRING)), count($list), 'Duplicate owned test.');
    };
    foreach ($exports as $item) {
        $tests($item['behavior'] ?? null);
        $tests($item['boundary'] ?? null);
    }
    manifestEqual($data['conformance']['status'] ?? null, 'owned', 'Conformance must be owned.');
    manifestRequire(manifestNonempty($data['conformance']['rationale'] ?? null), 'Conformance requires a rationale.');
    $tests($data['conformance']['tests'] ?? null);
    $corpora = $data['conformance']['corpora'] ?? null;
    $architecture = $data['architecture'] ?? null;
    manifestRequire(is_array($corpora) && array_is_list($corpora) && $corpora !== [], 'Conformance corpora must be a nonempty array.');
    manifestRequire(is_array($architecture) && array_is_list($architecture) && $architecture !== [], 'Architecture must have owners.');
    foreach (array_merge($corpora, $architecture) as $path) {
        manifestRepositoryFile($root, $path);
    }
    manifestMatch($data['host']['baseline'] ?? null, '/^[a-f0-9]{40}$/D', 'Host baseline must be an exact commit.');
    manifestRequire(manifestNonempty($data['host']['repository'] ?? null), 'Missing host repository.');
    manifestEqual($data['host']['transfers'] ?? null, [], 'This native package has no App adoption.');
    $retained = $data['host']['retained_responsibilities'] ?? null;
    manifestRequire(is_array($retained) && array_is_list($retained) && $retained !== [], 'Missing retained host responsibilities.');
    foreach ($retained as $item) {
        manifestRequire(manifestNonempty($item), 'Host responsibilities must be nonempty strings.');
    }
}

function manifestRefuses(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (Throwable) {
        return;
    }
    throw new RuntimeException($message);
}

try {
    manifestRequire(count($argv) <= 2, 'Usage: php tools/check-manifests.php [build-directory] (from the Engine source root)');
    $root = realpath('.');
    manifestRequire($root !== false, 'Cannot resolve Engine source root.');
    $build = $argv[1] ?? 'build';
    $abi = explode("\n", trim(manifestRead('resources/abi-symbols.txt')));
    $header = manifestRead('include/kumwe/engine/engine.h');
    preg_match_all('/KUMWE_ENGINE_API (?:kumwe_engine_v1_status|void) (kumwe_engine_v1_\w+)\(/', $header, $matches);
    $declared = $matches[1];
    sort($declared, SORT_STRING);
    manifestEqual($declared, $abi, 'Every public function must have exactly one manifest owner.');

    $abiManifest = manifestJson(manifestRead('resources/abi-manifest.json'));
    manifestEqual($abiManifest['schema'] ?? null, 'kumwe-engine-abi/v1', 'Invalid ABI manifest schema.');
    manifestEqual($abiManifest['abi_major'] ?? null, 1, 'Unexpected ABI major.');
    manifestEqual($abiManifest['abi_minor'] ?? null, 0, 'Unexpected ABI minor.');
    manifestRequire(in_array($abiManifest['status'] ?? null, ['unstable-development', 'frozen'], true), 'Invalid ABI status.');
    $frozenAbi = $abiManifest['status'] === 'frozen';
    manifestEqual($abiManifest['paths_relative_to'] ?? null, 'source_archive_root', 'Invalid ABI manifest root.');
    manifestEqual(array_keys($abiManifest['files']), ['include/kumwe/engine/engine.h', 'resources/abi-symbols.txt', 'resources/abi-symbols.map', 'docs/abi.md'],
        'Unexpected ABI source closure.');
    foreach ($abiManifest['files'] as $path => $digest) {
        manifestRepositoryFile($root, $path);
        manifestDigest($path, $digest, 'Stale ABI manifest ' . $path);
    }
    $abiManifestDigest = hash_file('sha256', 'resources/abi-manifest.json');
    preg_match_all('/#define KUMWE_ENGINE_V1_(\w+) UINT32_C\((\d+)\)/', $header, $matches, PREG_SET_ORDER);
    $statuses = array_map(static fn(array $match): array => [$match[1], (int) $match[2]], $matches);
    manifestEqual($statuses, [['OK', 0], ['INVALID_INPUT', 1], ['UNSUPPORTED_VERSION', 2], ['INCOMPATIBLE_CAPABILITY', 3],
        ['INCOMPATIBLE_CORPUS', 4], ['INVALID_PROGRAM', 5], ['EXHAUSTED_LIMIT', 6], ['CANCELLED', 7], ['INTERNAL_FAILURE', 8]],
        'Frozen ABI 1 status registry must not change.');

    preg_match('/\n  public_manifests:\n([\s\S]*?)\n  intentionally_excluded:/', manifestRead('MIGRATION-HANDOFF.md'), $handoff);
    manifestRequire(isset($handoff[1]) && $handoff[1] !== '', 'Native handoff must bind the public manifests.');
    preg_match_all('/path: "([^"]+)"\n\s+sha256: "([a-f0-9]{64})"/', $handoff[1], $handoffEntries, PREG_SET_ORDER);
    manifestEqual(array_column($handoffEntries, 1), ['resources/abi-manifest.json', 'resources/abi-symbols.txt', 'resources/capabilities.json',
        'resources/contracts.json', 'tests/ownership.json', 'include/kumwe/engine/engine.h'], 'Unexpected handoff manifest inventory.');
    foreach ($handoffEntries as [, $path, $digest]) {
        manifestDigest($path, $digest, 'Stale handoff manifest ' . $path);
    }

    $inventory = manifestJson(manifestCommand(['ctest', '--test-dir', $build, '--show-only=json-v1']));
    $names = array_fill_keys(array_column($inventory['tests'], 'name'), true);
    $ownershipSource = manifestRead('tests/ownership.json');
    // Preserve JSON array/object distinctions before associative decoding.
    $ownershipShape = json_decode($ownershipSource, false, 512, JSON_THROW_ON_ERROR);
    manifestRequire(is_object($ownershipShape) && is_object($ownershipShape->exports ?? null)
        && is_object($ownershipShape->conformance ?? null) && is_object($ownershipShape->host ?? null),
        'Ownership sections must be JSON objects.');
    foreach ($ownershipShape->exports as $item) {
        manifestRequire(is_object($item) && is_array($item->behavior ?? null) && is_array($item->boundary ?? null),
            'Behavior and boundary owners must be JSON arrays.');
    }
    foreach ([$ownershipShape->conformance->tests ?? null, $ownershipShape->conformance->corpora ?? null,
        $ownershipShape->architecture ?? null, $ownershipShape->host->transfers ?? null,
        $ownershipShape->host->retained_responsibilities ?? null] as $list) {
        manifestRequire(is_array($list), 'Owned test, corpus and host lists must be JSON arrays.');
    }
    $ownership = manifestJson($ownershipSource);
    manifestOwnership($ownership, $abi, $names, $root);
    // Exercise the actual ownership validator with every existing negative fixture.
    $mutations = [
        static function (array &$data) use ($abi): void { unset($data['exports'][$abi[0]]); },
        static function (array &$data) use ($abi): void { $data['exports'][$abi[0]]['behavior'] = []; },
        static function (array &$data) use ($abi): void { $data['exports'][$abi[0]]['boundary'] = ['imaginary-test']; },
        static function (array &$data): void { $data['conformance']['status'] = 'future'; },
        static function (array &$data): void { $data['conformance']['corpora'] = ['missing-corpus.tsv']; },
        static function (array &$data): void { $data['host']['baseline'] = 'main'; },
        static function (array &$data): void { $data['conformance']['corpora'] = ['corpus']; },
        static function (array &$data): void { $data['conformance']['corpora'] = ['corpus/./decimal/decimal-v1.tsv']; },
    ];
    foreach ($mutations as $mutate) {
        $changed = $ownership;
        $mutate($changed);
        manifestRefuses(static fn() => manifestOwnership($changed, $abi, $names, $root),
            'Ownership gate must refuse negative fixture.');
    }
    manifestRequire(is_dir('artifacts') || mkdir('artifacts', 0777, true), 'Cannot create ownership artifact directory.');
    $fixture = 'artifacts/ownership-links-' . bin2hex(random_bytes(8));
    manifestRequire(mkdir($fixture, 0700), 'Cannot create ownership link fixture.');
    try {
        manifestRequire(symlink($root . '/corpus', $fixture . '/corpus'), 'Cannot create ownership symlink fixture.');
        $changed = $ownership;
        $changed['conformance']['corpora'] = [$fixture . '/corpus/decimal/decimal-v1.tsv'];
        manifestRefuses(static fn() => manifestOwnership($changed, $abi, $names, $root),
            'Intermediate symlink cannot establish repository test ownership.');
    } finally {
        if (is_link($fixture . '/corpus')) {
            unlink($fixture . '/corpus');
        }
        rmdir($fixture);
    }

    $contracts = manifestJson(manifestRead('resources/contracts.json'));
    manifestEqual($contracts['completion_claim'] ?? null, false, 'Source candidate must not claim completion.');
    manifestEqual($contracts['abi_frozen'] ?? null, $frozenAbi, 'Contract ABI status differs.');
    manifestEqual(array_column($contracts['modules'], 'module'), ['decimal', 'definition_vm', 'document_batch', 'report', 'canonical_streaming'],
        'Unexpected semantic module inventory.');
    $decimal = $contracts['modules'][0];
    manifestDigest($decimal['corpus'], $decimal['corpus_sha256'], 'Stale decimal corpus.');
    $capabilities = manifestJson(manifestRead('resources/capabilities.json'));
    manifestEqual($capabilities['corpus_sha256'] ?? null, $decimal['corpus_sha256'], 'Runtime decimal digest differs.');
    manifestEqual($capabilities['semantic_source'] ?? null, $decimal['semantic_source'], 'Runtime semantic source differs.');
    manifestEqual($capabilities['abi_status'] ?? null, $abiManifest['status'], 'Runtime ABI status differs.');
    $releaseVerified = ($contracts['computation_baseline']['state'] ?? null) === 'release-verified';
    foreach ($contracts['modules'] as $module) {
        $releaseVerified = $releaseVerified && ($module['release_verified'] ?? null) === true;
    }
    manifestEqual($capabilities['semantic_release_verified'] ?? null, $releaseVerified, 'Runtime semantic release verification differs.');
    $profiles = ['decimal-batch-draft/1'];
    foreach (array_slice($contracts['modules'], 1) as $module) {
        array_push($profiles, ...($module['profiles'] ?? [$module['profile']]));
    }
    manifestEqual($capabilities['capabilities'] ?? null, $profiles, 'Runtime capability profiles differ.');
    foreach ($contracts['modules'] as $module) {
        manifestDigest($module['corpus'], $module['corpus_sha256'], 'Stale module corpus: ' . $module['module']);
        manifestRequire(is_bool($module['release_verified'] ?? null), 'Module release verification must be boolean.');
        $release = $module['semantic_release'] ?? null;
        manifestRequire(is_array($release) && !array_is_list($release), 'Missing published semantic coordinate: ' . $module['module']);
        manifestRequire(in_array($release['repository'] ?? null, $module['owners'] ?? [$module['owner']], true), 'Semantic release repository has no owner.');
        manifestMatch($release['version'] ?? null, '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/D', 'Invalid semantic release version.');
        manifestEqual($release['tag'] ?? null, 'v' . $release['version'], 'Semantic release tag differs.');
        manifestMatch($release['commit'] ?? null, '/^[a-f0-9]{40}$/D', 'Semantic release requires an exact commit.');
        manifestMatch($release['corpus_path'] ?? null, '/^resources\/(?:conformance|corpus)\/[a-z0-9-]+\.(?:json|tsv)$/D', 'Invalid published corpus path.');
        manifestEqual($release['corpus_sha256'] ?? null, $module['corpus_sha256'], 'Published corpus digest differs.');
        manifestEqual($release['publication'] ?? null, 'published', 'Semantic source must be published.');
        if ($module['release_verified']) {
            $attestation = $release['external_attestation'] ?? null;
            manifestRequire(is_array($attestation) && !array_is_list($attestation), 'Verified release requires an attestation.');
            manifestMatch($attestation['uri'] ?? null, '/^https:\/\/[^\s\/]+\/[^\s]+$/D', 'Invalid attestation URI.');
            manifestMatch($attestation['sha256'] ?? null, '/^[a-f0-9]{64}$/D', 'Invalid attestation digest.');
        } else {
            manifestRequire(array_key_exists('external_attestation', $release) && $release['external_attestation'] === null,
                'Unverified source cannot invent release acceptance.');
        }
    }

    $corpusPaths = array_column($capabilities['corpora'], 'path');
    manifestEqual($corpusPaths, $ownership['conformance']['corpora'], 'Every advertised corpus must have exactly one conformance owner.');
    manifestEqual(count(array_unique($corpusPaths, SORT_STRING)), count($capabilities['corpora']), 'Duplicate runtime corpus owner.');
    foreach ($capabilities['corpora'] as $corpus) {
        manifestRepositoryFile($root, $corpus['path']);
        manifestDigest($corpus['path'], $corpus['sha256'], 'Stale runtime corpus ' . $corpus['path']);
    }
    $document = null;
    foreach ($contracts['modules'] as $module) {
        if ($module['module'] === 'document_batch') {
            $document = $module;
            break;
        }
    }
    manifestRequire($document !== null, 'Missing document module.');
    $bundle = manifestJson(manifestRead($document['corpus']));
    manifestEqual($bundle['schema'] ?? null, 'kumwe-document-profile-corpus/v1', 'Invalid document profile corpus schema.');
    manifestEqual($bundle['profile'] ?? null, $document['profile'], 'Document profile differs.');
    manifestRequire(is_array($bundle['corpora'] ?? null) && array_is_list($bundle['corpora']) && count($bundle['corpora']) >= 4,
        'Incomplete document profile corpus.');
    manifestEqual(count(array_unique(array_column($bundle['corpora'], 'id'), SORT_STRING)), count($bundle['corpora']), 'Duplicate document corpus ID.');
    foreach ($bundle['corpora'] as $corpus) {
        manifestMatch($corpus['id'] ?? null, '/^[a-z][a-z0-9-]*$/D', 'Invalid document corpus ID.');
        $path = 'corpus/document/' . $corpus['id'] . '.json';
        manifestRepositoryFile($root, $path);
        manifestDigest($path, $corpus['sha256'], 'Stale document corpus ' . $path);
        $found = false;
        foreach ($capabilities['corpora'] as $entry) {
            if ($entry['path'] === $path && $entry['sha256'] === $corpus['sha256']) {
                $found = true;
                break;
            }
        }
        manifestRequire($found, 'Document corpus is not advertised: ' . $path);
    }
    manifestDigest($document['preparation_corpus'], $document['preparation_corpus_sha256'], 'Stale preparation corpus.');
    foreach ($capabilities['computation']['contracts'] as $contract) {
        $owner = null;
        foreach ($contracts['modules'] as $module) {
            if (in_array($contract['profile'], $module['profiles'] ?? [$module['profile'] ?? null], true)) {
                $owner = $module;
                break;
            }
        }
        manifestRequire($owner !== null, 'Unknown runtime semantic profile ' . $contract['profile']);
        manifestEqual($contract['corpus_digest'], $contract['profile'] === 'normalized-preparation-draft/1'
            ? $owner['preparation_corpus_sha256'] : $owner['corpus_sha256'], 'Runtime contract corpus digest differs.');
    }
    $executable = realpath($build . '/kumwe-engine-conformance');
    manifestRequire($executable !== false, 'Cannot find native conformance executable.');
    $runtime = manifestJson(manifestCommand([$executable]));
    manifestMatch($runtime['computation']['build_digest'] ?? null, '/^[a-f0-9]{64}$/D', 'Invalid native build identity.');
    manifestEqual($runtime['completion_claim'] ?? null, false, 'Native runtime must not claim completion.');
    manifestEqual($runtime['semantic_release_verified'] ?? null, $capabilities['semantic_release_verified'], 'Native semantic verification differs.');
    manifestEqual($runtime['abi_major'] ?? null, $abiManifest['abi_major'], 'Native ABI major differs.');
    manifestEqual($runtime['abi_minor'] ?? null, $abiManifest['abi_minor'], 'Native ABI minor differs.');
    manifestEqual($runtime['abi_manifest_sha256'] ?? null, $abiManifestDigest, 'Native ABI manifest digest differs.');
    manifestEqual($runtime['computation']['contracts'] ?? null, $capabilities['computation']['contracts'], 'Native computation contracts differ.');
    printf("%d ABI exports own behavior/boundary tests; exact corpus and nine negative ownership fixtures passed\n", count($abi));
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
