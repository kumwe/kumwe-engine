#!/usr/bin/env php
<?php
declare(strict_types=1);

namespace Kumwe\ReleaseSource;

/** Prepare and verify committed native source bundles; never publish or attest. */
class ReleaseError extends \RuntimeException {}

function process(string $root, array $command, ?string $input = null): array
{
    $streams = [tmpfile(), tmpfile(), tmpfile()];
    if (in_array(false, $streams, true)) {
        foreach ($streams as $stream) { if (is_resource($stream)) { fclose($stream); } }
        throw new ReleaseError('Cannot allocate command streams.');
    }
    try {
        if ($input !== null && fwrite($streams[0], $input) !== strlen($input)) {
            throw new ReleaseError('Cannot write command input.');
        }
        rewind($streams[0]);
        $pipes = [];
        $handle = proc_open($command, $streams, $pipes, $root, null, ['bypass_shell' => true]);
        if (!is_resource($handle)) { throw new ReleaseError('Cannot start source tool.'); }
        $status = proc_close($handle);
        rewind($streams[1]);
        rewind($streams[2]);
        return ['returncode' => $status, 'stdout' => stream_get_contents($streams[1]),
                'stderr' => stream_get_contents($streams[2])];
    } finally {
        foreach ($streams as $stream) { fclose($stream); }
    }
}

function run(string $root, string ...$command): string
{
    $result = process($root, $command);
    if ($result['returncode'] !== 0) {
        throw new ReleaseError(trim($result['stderr']) ?: 'Source command failed: ' . $command[0]);
    }
    return $result['stdout'];
}

function digest(string $data, string $algorithm = 'sha256'): string { return hash($algorithm, $data); }

/** Two-space JSON, UTF-8 and insertion ordering form the source evidence wire format. */
function encode(mixed $value): string
{
    $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_PRESERVE_ZERO_FRACTION);
    return preg_replace_callback('/^( +)/m', static fn(array $m): string =>
        str_repeat(' ', intdiv(strlen($m[1]), 2)), $json) . "\n";
}

/** Keep empty JSON objects distinguishable from arrays while exposing object fields as maps. */
function json_value(mixed $value): mixed
{
    if ($value instanceof \stdClass) {
        $members = get_object_vars($value);
        $converted = array_map(__NAMESPACE__ . '\\json_value', $members);
        if ($members === [] || array_is_list($members)) {
            $object = new \stdClass();
            foreach ($converted as $key => $member) { $object->{(string) $key} = $member; }
            return $object;
        }
        return $converted;
    }
    return is_array($value) ? array_map(__NAMESPACE__ . '\\json_value', $value) : $value;
}

function decode_object(string $data): array
{
    $value = json_decode($data, false, 512, JSON_THROW_ON_ERROR);
    if (!$value instanceof \stdClass) { throw new ReleaseError('JSON must be an object.'); }
    $members = get_object_vars($value);
    if ($members !== [] && array_is_list($members)) {
        throw new ReleaseError('Source JSON requires named object fields.');
    }
    return array_map(__NAMESPACE__ . '\\json_value', $members);
}

function is_map(mixed $value): bool
{
    return $value instanceof \stdClass || (is_array($value) && !array_is_list($value));
}

function fields(mixed $value): array
{
    if (!is_map($value)) { throw new ReleaseError('Expected a JSON object.'); }
    return (array) $value;
}

function matches(string $expression, mixed $value): bool
{
    return is_string($value) && preg_match($expression, $value) === 1;
}

function read_bytes(string $path): string
{
    $data = file_get_contents($path);
    if ($data === false) { throw new ReleaseError('Cannot read ' . $path); }
    return $data;
}

function write_bytes(string $path, string $data): void
{
    if (file_put_contents($path, $data) !== strlen($data)) {
        throw new ReleaseError('Cannot write ' . $path);
    }
}

function clean_source(string $root): string
{
    $commit = trim(run($root, 'git', 'rev-parse', 'HEAD'));
    run($root, 'git', 'ls-files', '--error-unmatch', 'tools/release-source.php');
    if (trim(run($root, 'git', 'status', '--porcelain', '--untracked-files=no')) !== '') {
        throw new ReleaseError('Commit tracked source changes before preparing or verifying a bundle.');
    }
    return $commit;
}

function kind(string $root): string
{
    return is_file($root . '/resources/engine-lock.json') ? 'binding' : 'engine';
}

function archive_name(string $root): string
{
    return kind($root) === 'binding' ? 'kumwe-engine-php-source.tar.gz' : 'kumwe-engine-source.tar.gz';
}

function archive_bytes(string $root): string
{
    $prefix = kind($root) === 'binding' ? 'kumwe-engine-php/' : 'kumwe-engine/';
    $tar = run($root, 'git', 'archive', '--format=tar', '--prefix=' . $prefix, 'HEAD');
    $gzip = process($root, ['gzip', '-n'], $tar);
    if ($gzip['returncode'] !== 0) { throw new ReleaseError('Source compression failed: ' . $gzip['stderr']); }
    return $gzip['stdout'];
}

function tar_number(string $field): int
{
    $value = trim($field, "\0 ");
    if ($value === '') { return 0; }
    if (!matches('/\A[0-7]+\z/D', $value)) { throw new ReleaseError('Invalid TAR numeric field.'); }
    $number = 0;
    foreach (str_split($value) as $digit) {
        if ($number > intdiv(PHP_INT_MAX - 7, 8)) { throw new ReleaseError('Oversized TAR numeric field.'); }
        $number = $number * 8 + (int) $digit;
    }
    return $number;
}

function pax_fields(string $data): array
{
    $fields = [];
    for ($offset = 0, $total = strlen($data); $offset < $total;) {
        $space = strpos($data, ' ', $offset);
        if ($space === false) { throw new ReleaseError('Malformed TAR extended record.'); }
        $number = substr($data, $offset, $space - $offset);
        if (!matches('/\A[1-9][0-9]{0,9}\z/D', $number)) {
            throw new ReleaseError('Malformed TAR extended length.');
        }
        $length = (int) $number;
        if ($length <= $space - $offset + 2 || $length > $total - $offset
                || $data[$offset + $length - 1] !== "\n") {
            throw new ReleaseError('Truncated TAR extended record.');
        }
        $record = substr($data, $space + 1, $offset + $length - $space - 2);
        $equals = strpos($record, '=');
        if ($equals === false || $equals === 0) { throw new ReleaseError('Malformed TAR extended field.'); }
        $key = substr($record, 0, $equals);
        if (array_key_exists($key, $fields)) { throw new ReleaseError('Duplicate TAR extended field.'); }
        if (str_starts_with($key, 'GNU.sparse') || $key === 'SCHILY.filetype') {
            throw new ReleaseError('Sparse or special source archive members are refused.');
        }
        $fields[$key] = substr($record, $equals + 1);
        $offset += $length;
    }
    return $fields;
}

/** Parse sequentially, retaining every member so duplicate paths cannot be hidden by an archive index. */
function tar_entries(string $tar): array
{
    $entries = [];
    $global = [];
    $local = [];
    $longName = null;
    $offset = 0;
    $total = strlen($tar);
    $terminated = false;
    while ($offset + 512 <= $total) {
        $header = substr($tar, $offset, 512);
        $offset += 512;
        if ($header === str_repeat("\0", 512)) {
            if ($offset + 512 > $total || substr($tar, $offset, 512) !== str_repeat("\0", 512)
                    || trim(substr($tar, $offset), "\0") !== '') {
                throw new ReleaseError('Invalid TAR end marker or trailing data.');
            }
            $terminated = true;
            break;
        }
        $expected = tar_number(substr($header, 148, 8));
        $check = substr_replace($header, str_repeat(' ', 8), 148, 8);
        $actual = array_sum(unpack('C*', $check));
        if ($actual !== $expected) { throw new ReleaseError('Invalid TAR header checksum.'); }
        $size = tar_number(substr($header, 124, 12));
        $type = $header[156];
        $name = rtrim(substr($header, 0, 100), "\0");
        $prefix = rtrim(substr($header, 345, 155), "\0");
        if (str_starts_with(substr($header, 257, 6), 'ustar') && $prefix !== '') {
            $name = $prefix . '/' . $name;
        }
        if (!in_array($type, ['x', 'g', 'L', 'K'], true)) {
            $pax = array_replace($global, $local);
            if (isset($pax['size'])) {
                if (!matches('/\A(0|[1-9][0-9]{0,17})\z/D', $pax['size'])) {
                    throw new ReleaseError('Invalid TAR extended size.');
                }
                $size = (int) $pax['size'];
            }
        }
        if ($size > $total - $offset) { throw new ReleaseError('Truncated TAR member.'); }
        $body = substr($tar, $offset, $size);
        $padding = (512 - ($size % 512)) % 512;
        if ($padding > $total - $offset - $size) { throw new ReleaseError('Truncated TAR padding.'); }
        $offset += $size + $padding;
        if ($type === 'g') { $global = array_replace($global, pax_fields($body)); continue; }
        if ($type === 'x') { $local = array_replace($local, pax_fields($body)); continue; }
        if ($type === 'L') { $longName = rtrim($body, "\0"); continue; }
        if ($type === 'K') { throw new ReleaseError('Source archive links are refused.'); }
        $name = $pax['path'] ?? $longName ?? $name;
        $entries[] = ['name' => $name, 'type' => $type, 'data' => $body,
                      'mode' => tar_number(substr($header, 100, 8))];
        $local = [];
        $longName = null;
    }
    if (!$terminated || $local !== [] || $longName !== null) {
        throw new ReleaseError('Incomplete TAR archive.');
    }
    return $entries;
}

function safe_path(string $path): bool
{
    if ($path === '' || str_starts_with($path, '/') || str_contains($path, '\\')
            || str_contains($path, "\0")) { return false; }
    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.' || $part === '..') { return false; }
    }
    return true;
}

function archive_files(string $data, string $package_kind): array
{
    $prefix = $package_kind === 'binding' ? 'kumwe-engine-php/' : 'kumwe-engine/';
    $tar = @gzdecode($data);
    if ($tar === false) { throw new ReleaseError('Invalid compressed source archive.'); }
    $files = [];
    $names = [];
    $forbidden = ['.git', 'node_modules', 'artifacts', 'build', 'engine-build', 'modules', '.libs', 'autom4te.cache'];
    foreach (tar_entries($tar) as $member) {
        $name = rtrim($member['name'], '/');
        if (!safe_path($name)) { throw new ReleaseError('Unsafe archive path: ' . $name); }
        if (isset($names[$name])) { throw new ReleaseError('Duplicate archive path: ' . $name); }
        $names[$name] = true;
        $directory = $member['type'] === '5';
        if ($name === rtrim($prefix, '/') && $directory) { continue; }
        if (!str_starts_with($name, $prefix)) {
            throw new ReleaseError('Archive does not have its declared package root: ' . $name);
        }
        $relative = substr($name, strlen($prefix));
        if (array_intersect(explode('/', $relative), $forbidden) !== []) {
            throw new ReleaseError('Build/cache content is not source distribution material: ' . $relative);
        }
        $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        if (in_array($extension, ['pem', 'key'], true)) {
            throw new ReleaseError('Credential-like file is not source distribution material: ' . $relative);
        }
        if ($directory) {
            if ($member['data'] !== '') { throw new ReleaseError('Source directory has a payload.'); }
            continue;
        }
        if (!in_array($member['type'], ["\0", '0'], true)) {
            throw new ReleaseError('Source archives may contain only regular files and directories: ' . $relative);
        }
        if ($package_kind === 'engine' && in_array($extension, ['php', 'phar'], true)) {
            throw new ReleaseError('PHP oracle/runtime content must not ship in Engine: ' . $relative);
        }
        $files[$relative] = $member['data'];
    }
    if ($files === []) { throw new ReleaseError('An empty archive is not a source package.'); }
    ksort($files, SORT_STRING);
    return $files;
}

function read_json(array $files, string $path): array
{
    try { return decode_object($files[$path] ?? throw new ReleaseError('Missing JSON.')); }
    catch (\Throwable $error) { throw new ReleaseError('Missing or invalid source JSON: ' . $path, 0, $error); }
}

function exact_hash(array $files, string $path, mixed $expected): void
{
    if (!matches('/\A[a-f0-9]{64}\z/D', $expected)) {
        throw new ReleaseError('Malformed source digest: ' . $path);
    }
    if (!isset($files[$path]) || digest($files[$path]) !== $expected) {
        throw new ReleaseError('Source material digest mismatch: ' . $path);
    }
}

function external_attestation_present(mixed $value): bool
{
    if (!is_map($value)) { return false; }
    $value = (array) $value;
    return matches('~\Ahttps://[^\s/]+/[^\s]+\z~D', $value['uri'] ?? null)
        && matches('/\A[a-f0-9]{64}\z/D', $value['sha256'] ?? null);
}

function computation_baseline_blockers(mixed $baseline): array
{
    if (!is_map($baseline) || (((array) $baseline)['state'] ?? null) !== 'release-verified') {
        return ['The independently verified portable-only Computation Phase 1A baseline is unresolved.'];
    }
    $baseline = fields($baseline);
    $blockers = [];
    $version = $baseline['version'] ?? null;
    if (($baseline['repository'] ?? null) !== 'kumwe/computation'
            || !matches('/\A(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\z/D', $version)
            || $version === '0.0.0' || ($baseline['release'] ?? null) !== 'v' . $version) {
        $blockers[] = 'Computation baseline requires an exact published package version and matching tag.';
    }
    foreach (['commit' => 40, 'archive_sha256' => 64, 'api_digest' => 64, 'capability_digest' => 64] as $field => $length) {
        if (!matches('/\A[a-f0-9]{' . $length . '}\z/D', $baseline[$field] ?? null)) {
            $blockers[] = 'Computation baseline requires an immutable ' . $field . '.';
        }
    }
    $corpora = $baseline['corpus_digests'] ?? null;
    $validCorpora = is_map($corpora) && (array) $corpora !== [];
    foreach ((array) $corpora as $path => $value) {
        if (!is_string($path) || !str_starts_with($path, 'resources/') || !safe_path($path)
                || !matches('/\A[a-f0-9]{64}\z/D', $value)) { $validCorpora = false; }
    }
    if (!$validCorpora) { $blockers[] = 'Computation baseline requires exact portable corpus paths and SHA256 identities.'; }
    $requirements = $baseline['runtime_requirements'] ?? null;
    $validRequirements = is_map($requirements) && !empty(((array) $requirements)['php']);
    foreach ((array) $requirements as $name => $value) {
        if (!is_string($name) || !is_string($value) || $value === ''
                || in_array(strtolower((string) $name), ['ext-kumwe_engine', 'kumwe/engine', 'kumwe/kumwe-engine'], true)) {
            $validRequirements = false;
        }
    }
    if (!$validRequirements) {
        $blockers[] = 'Computation baseline must record released runtime requirements without a native dependency.';
    }
    if (($baseline['native_bindings_present'] ?? null) !== false) {
        $blockers[] = 'Computation baseline must independently establish the absence of native classes and DI bindings.';
    }
    if (!external_attestation_present($baseline['attestation'] ?? null)) {
        $blockers[] = 'Computation baseline requires an external release-attestation reference and SHA256 identity.';
    }
    return $blockers;
}

function engine_materials(array $files, string $prefix = ''): array
{
    $caps = read_json($files, $prefix . 'resources/capabilities.json');
    $abi = read_json($files, $prefix . 'resources/abi-manifest.json');
    $contracts = read_json($files, $prefix . 'resources/contracts.json');
    foreach (fields($abi['files'] ?? null) as $path => $expected) { exact_hash($files, $prefix . $path, $expected); }
    if (!is_array($caps['corpora'] ?? null) || !array_is_list($caps['corpora'])
            || !is_array($contracts['modules'] ?? null) || !array_is_list($contracts['modules'])) {
        throw new ReleaseError('Engine corpora and modules must be arrays.');
    }
    foreach ($caps['corpora'] as $corpus) { exact_hash($files, $prefix . $corpus['path'], $corpus['sha256']); }
    foreach ($contracts['modules'] as $module) {
        foreach (['corpus', 'preparation_corpus'] as $key) {
            if (array_key_exists($key, $module)) {
                if (!is_string($module[$key])) { throw new ReleaseError('Malformed semantic corpus path.'); }
                exact_hash($files, $prefix . $module[$key], $module[$key . '_sha256'] ?? null);
            }
        }
        $release = $module['semantic_release'] ?? null;
        if (!is_map($release) || !matches('/\A[a-f0-9]{40}\z/D', ((array) $release)['commit'] ?? null)) {
            throw new ReleaseError('Semantic owner material requires an exact source commit.');
        }
        if (($module['corpus_sha256'] ?? null) !== (((array) $release)['corpus_sha256'] ?? null)) {
            throw new ReleaseError('Semantic owner corpus disagrees with embedded corpus identity.');
        }
    }
    $blockers = computation_baseline_blockers($contracts['computation_baseline'] ?? null);
    if (!matches('/\A[1-9][0-9]*\.[0-9]+\.[0-9]+\z/D', $caps['version'] ?? null)) {
        $blockers[] = 'Engine version is a development candidate, not an App-eligible stable release.';
    }
    if (($contracts['abi_frozen'] ?? null) !== true || !in_array($abi['status'] ?? null, ['stable', 'frozen'], true)
            || !in_array($caps['abi_status'] ?? null, ['stable', 'frozen'], true)) {
        $blockers[] = 'The Engine ABI remains unfrozen.';
    }
    if (preg_match('/draft|candidate|development/', $contracts['state'] ?? '')) {
        $blockers[] = 'The semantic contract matrix still declares candidate inputs.';
    }
    $verified = ($caps['semantic_release_verified'] ?? null) === true;
    $attested = true;
    foreach ($contracts['modules'] as $module) {
        $verified = $verified && ($module['release_verified'] ?? null) === true;
        $attested = $attested && external_attestation_present($module['semantic_release']['external_attestation'] ?? null);
    }
    if (!$verified) { $blockers[] = 'Independent semantic-owner release verification is incomplete.'; }
    if (!$attested) { $blockers[] = 'Every semantic-owner release requires an external attestation URI and SHA256 identity.'; }
    return [$caps, $abi, $contracts, $blockers];
}

function source_facts(array $files, string $package_kind): array
{
    $manifestPaths = ['LICENSE', 'CHARTER.md', 'MIGRATION-HANDOFF.md'];
    if ($package_kind === 'engine') {
        [$caps, $abi, $contracts, $blockers] = engine_materials($files);
        array_push($manifestPaths, 'resources/capabilities.json', 'resources/abi-manifest.json', 'resources/contracts.json',
            'resources/pcre2-source.json', 'resources/unicode-source.json');
        $identity = ['version' => $caps['version'], 'abi_major' => $abi['abi_major'], 'abi_minor' => $abi['abi_minor']];
        $dependencies = array_column($contracts['modules'], 'semantic_release');
    } else {
        $compatibility = read_json($files, 'resources/compatibility/v1.json');
        $lock = read_json($files, 'resources/engine-lock.json');
        if (!matches('/\A[a-f0-9]{40}\z/D', $lock['commit'] ?? null)) {
            throw new ReleaseError('Embedded Engine requires an exact source commit.');
        }
        if (!matches('/\A[a-f0-9]{64}\z/D', $lock['archive_sha256'] ?? null)) {
            throw new ReleaseError('Embedded Engine requires an exact source archive digest.');
        }
        $actual = [];
        foreach ($files as $path => $data) {
            if (str_starts_with($path, 'vendor/engine/')) { $actual[substr($path, 14)] = digest($data); }
        }
        $locked = fields($lock['files'] ?? null);
        ksort($actual, SORT_STRING);
        ksort($locked, SORT_STRING);
        if ($actual === [] || $actual !== $locked) {
            throw new ReleaseError('Embedded Engine source closure differs from the exact lock.');
        }
        [, , $contracts, $blockers] = engine_materials($files, 'vendor/engine/');
        if (isset($lock['snapshot'])) {
            $snapshot = fields($lock['snapshot']);
            if (($snapshot['profile'] ?? null) !== 'php-native-tooling/v1'
                    || !is_map($snapshot['upstream_files'] ?? null) || (array) $snapshot['upstream_files'] === []) {
                throw new ReleaseError('Embedded source snapshot requires its exact upstream inventory and profile.');
            }
            foreach ((array) $snapshot['upstream_files'] as $path => $expected) {
                if (!is_string($path) || !safe_path($path) || !matches('/\\A[a-f0-9]{64}\\z/D', $expected)) {
                    throw new ReleaseError('Malformed embedded upstream source inventory.');
                }
            }
            $blockers[] = 'The transformed embedded source snapshot has no independent immutable release verification.';
        }
        if (($compatibility['state'] ?? null) === 'candidate' || ($lock['state'] ?? null) === 'candidate') {
            $blockers[] = 'The extension or its embedded Engine is a candidate.';
        }
        if (($compatibility['publication_allowed'] ?? null) !== true) {
            $blockers[] = 'Extension compatibility metadata does not permit publication.';
        }
        if (($lock['release_verified'] ?? null) !== true || empty($lock['release'])) {
            $blockers[] = 'The embedded immutable Engine release has not been independently verified.';
        }
        if (!external_attestation_present($lock['external_attestation'] ?? null)) {
            $blockers[] = 'The embedded Engine requires an external release-attestation URI and SHA256 identity.';
        }
        if (!matches('/\A[0-9]+\.[0-9]+\.[0-9]+\z/D', $compatibility['version'] ?? null)
                || $compatibility['version'] === '0.0.0') { $blockers[] = 'The extension version is not stable.'; }
        array_push($manifestPaths, 'composer.json', 'resources/api/v1.json', 'resources/compatibility/v1.json',
            'resources/engine-lock.json', 'php_kumwe_engine_build.h');
        $identity = ['version' => $compatibility['version'], 'abi_major' => $compatibility['abi_major'],
                     'abi_minor' => $compatibility['abi_minor'], 'engine_commit' => $lock['commit'],
                     'engine_archive_sha256' => $lock['archive_sha256']];
        unset($lock['files']);
        $dependencies = [$lock];
    }
    $materials = [];
    foreach ($manifestPaths as $path) {
        if (!isset($files[$path])) { throw new ReleaseError('Required source distribution material is absent: ' . $path); }
        $materials[] = ['path' => $path, 'sha256' => digest($files[$path])];
    }
    return ['identity' => $identity, 'materials' => $materials, 'dependencies' => $dependencies,
            'computation_baseline' => $contracts['computation_baseline'] ?? null, 'stable_source_blockers' => $blockers];
}

function binding_sbom(array $files, string $commit, string $timestamp, string $package_kind = 'binding'): string
{
    $entries = [];
    ksort($files, SORT_STRING);
    foreach ($files as $path => $data) {
        $entries[] = ['SPDXID' => 'SPDXRef-File-' . count($entries), 'fileName' => './' . $path,
            'checksums' => [['algorithm' => 'SHA1', 'checksumValue' => digest($data, 'sha1')],
                            ['algorithm' => 'SHA256', 'checksumValue' => digest($data)]],
            'licenseConcluded' => 'NOASSERTION', 'licenseInfoInFiles' => ['NOASSERTION'], 'copyrightText' => 'NOASSERTION'];
    }
    $hashes = array_map(static fn(array $entry): string => $entry['checksums'][0]['checksumValue'], $entries);
    sort($hashes, SORT_STRING);
    $package = $package_kind === 'binding' ? 'kumwe/kumwe-engine' : 'kumwe/engine';
    $packageId = $package_kind === 'binding' ? 'SPDXRef-Binding' : 'SPDXRef-Engine';
    $packages = [['SPDXID' => $packageId, 'name' => $package, 'versionInfo' => $commit,
        'downloadLocation' => 'git+https://github.com/' . $package . '.git@' . $commit,
        'filesAnalyzed' => true, 'packageVerificationCode' => ['packageVerificationCodeValue' => digest(implode('', $hashes), 'sha1')],
        'licenseConcluded' => 'NOASSERTION', 'licenseDeclared' => 'Apache-2.0', 'copyrightText' => 'NOASSERTION']];
    $relationships = [['spdxElementId' => 'SPDXRef-DOCUMENT', 'relatedSpdxElement' => $packageId, 'relationshipType' => 'DESCRIBES']];
    if ($package_kind === 'binding') {
        $lock = read_json($files, 'resources/engine-lock.json');
        $packages[] = ['SPDXID' => 'SPDXRef-EmbeddedEngine', 'name' => 'kumwe/engine', 'versionInfo' => $lock['commit'],
            'downloadLocation' => 'git+' . $lock['repository'] . '.git@' . $lock['commit'], 'filesAnalyzed' => false,
            'licenseConcluded' => 'NOASSERTION', 'licenseDeclared' => 'Apache-2.0 AND BSD-3-Clause AND BSD-2-Clause AND PHP-3.01 AND Unicode-3.0',
            'copyrightText' => 'NOASSERTION'];
        $relationships[] = ['spdxElementId' => $packageId, 'relatedSpdxElement' => 'SPDXRef-EmbeddedEngine', 'relationshipType' => 'DEPENDS_ON'];
    }
    foreach ($entries as $entry) {
        $relationships[] = ['spdxElementId' => $packageId, 'relatedSpdxElement' => $entry['SPDXID'], 'relationshipType' => 'CONTAINS'];
    }
    return encode(['spdxVersion' => 'SPDX-2.3', 'dataLicense' => 'CC0-1.0', 'SPDXID' => 'SPDXRef-DOCUMENT',
        'name' => $package_kind === 'binding' ? 'Kumwe PHP binding committed source inventory' : 'Kumwe Engine committed source inventory',
        'documentNamespace' => 'https://github.com/' . $package . '/sbom/' . $commit,
        'creationInfo' => ['created' => $timestamp, 'creators' => ['Tool: kumwe-native-release-source']],
        'packages' => $packages, 'files' => $entries, 'relationships' => $relationships]);
}

function source_record(string $root, string $commit, array $files, string $archive, string $sbom, ?string $tag): array
{
    $tree = trim(run($root, 'git', 'rev-parse', 'HEAD^{tree}'));
    $timestamp = gmdate('Y-m-d\TH:i:s\Z', (int) trim(run($root, 'git', 'show', '-s', '--format=%ct', $commit)));
    $facts = source_facts($files, kind($root));
    if ($tag !== null && (!matches('/\Av?[0-9]+\.[0-9]+\.[0-9]+\z/D', $tag)
            || trim(run($root, 'git', 'rev-parse', 'refs/tags/' . $tag . '^{commit}')) !== $commit)) {
        throw new ReleaseError('The supplied source tag does not identify the exact selected commit.');
    }
    if ($tag !== null && (str_starts_with($tag, 'v') ? substr($tag, 1) : $tag) !== $facts['identity']['version']) {
        throw new ReleaseError('The supplied source tag version differs from the declared source version.');
    }
    $package = kind($root) === 'binding' ? 'kumwe/kumwe-engine' : 'kumwe/engine';
    return ['schema' => 'kumwe-native-source-bundle/v1', 'package' => $package,
        'source' => ['repository' => 'https://github.com/' . $package, 'commit' => $commit, 'tree' => $tree,
                     'committed_at' => $timestamp, 'tag' => $tag],
        'archive' => ['name' => archive_name($root), 'sha256' => digest($archive), 'bytes' => strlen($archive)],
        'sbom' => ['name' => 'source.spdx.json', 'sha256' => digest($sbom)],
        ...$facts, 'release_attestation' => false, 'publication_performed' => false];
}

function provenance(array $record): string
{
    return encode(['_type' => 'https://in-toto.io/Statement/v1',
        'subject' => [['name' => $record['archive']['name'], 'digest' => ['sha256' => $record['archive']['sha256']]],
                      ['name' => $record['sbom']['name'], 'digest' => ['sha256' => $record['sbom']['sha256']]]],
        'predicateType' => 'https://kumwe.dev/provenance/native-source-assembly/v1',
        'predicate' => ['source' => $record['source'], 'materials' => $record['materials'],
            'dependencies' => $record['dependencies'], 'computation_baseline' => $record['computation_baseline'],
            'recipe' => 'committed git archive; gzip -n; committed-source SPDX inventory',
            'signed' => false, 'release_attestation' => false, 'compiled_artifact' => false]]);
}

function bundle(string $root, string $commit, ?string $tag = null): array
{
    $first = archive_bytes($root);
    if ($first !== archive_bytes($root)) {
        throw new ReleaseError('Repeated committed source archives are not byte-reproducible.');
    }
    $files = archive_files($first, kind($root));
    $timestamp = gmdate('Y-m-d\TH:i:s\Z', (int) trim(run($root, 'git', 'show', '-s', '--format=%ct', $commit)));
    $sbom = binding_sbom($files, $commit, $timestamp, kind($root));
    $inventory = decode_object($sbom);
    $listed = [];
    foreach ($inventory['files'] as $entry) {
        $path = str_starts_with($entry['fileName'], './') ? substr($entry['fileName'], 2) : $entry['fileName'];
        if (isset($listed[$path])) { throw new ReleaseError('Duplicate SPDX file.'); }
        foreach ($entry['checksums'] as $checksum) {
            if ($checksum['algorithm'] === 'SHA256') { $listed[$path] = $checksum['checksumValue']; }
        }
    }
    ksort($listed, SORT_STRING);
    if ($listed !== array_map(__NAMESPACE__ . '\\digest', $files)) {
        throw new ReleaseError('SPDX inventory does not describe exactly the exported source archive.');
    }
    $record = source_record($root, $commit, $files, $first, $sbom, $tag);
    $result = [archive_name($root) => $first, 'source.spdx.json' => $sbom,
               'source.json' => encode($record), 'source.provenance.json' => provenance($record)];
    $sorted = $result;
    ksort($sorted, SORT_STRING);
    $sums = '';
    foreach ($sorted as $path => $data) { $sums .= digest($data) . '  ' . $path . "\n"; }
    $result['SHA256SUMS'] = $sums;
    if (clean_source($root) !== $commit) {
        throw new ReleaseError('Source changed while preparing its bundle; repeat against one committed head.');
    }
    return [$result, $record];
}

function require_stable(array $record): void
{
    if (!isset($record['stable_source_blockers']) || !is_array($record['stable_source_blockers'])
            || !array_is_list($record['stable_source_blockers'])) {
        throw new ReleaseError('Stable source requires an explicit blocker inventory.');
    }
    if ($record['stable_source_blockers'] !== []) {
        throw new ReleaseError('Stable source refused: ' . implode(' ', $record['stable_source_blockers']));
    }
}

function resolve_path(string $path): string
{
    if ($path === '' || str_contains($path, "\0")) { throw new ReleaseError('Invalid evidence path.'); }
    $absolute = str_starts_with($path, '/') ? $path : getcwd() . '/' . $path;
    $resolved = '';
    foreach (explode('/', $absolute) as $part) {
        if ($part === '' || $part === '.') { continue; }
        if ($part === '..') { $resolved = dirname($resolved ?: '/'); continue; }
        $resolved = rtrim($resolved, '/') . '/' . $part;
        if (file_exists($resolved) || is_link($resolved)) {
            $real = realpath($resolved);
            if ($real === false) { throw new ReleaseError('Unresolvable evidence path.'); }
            $resolved = $real;
        }
    }
    return $resolved ?: '/';
}

function outside_source(string $root, string $path): string
{
    $root = realpath($root) ?: throw new ReleaseError('Missing source root.');
    $resolved = resolve_path($path);
    if ($resolved === $root || str_starts_with($resolved, rtrim($root, '/') . '/')) {
        throw new ReleaseError('Source evidence must be stored outside the tested repository.');
    }
    return $resolved;
}

function directory_names(string $directory): array
{
    $names = scandir($directory);
    if ($names === false) { throw new ReleaseError('Cannot list evidence directory.'); }
    $names = array_values(array_diff($names, ['.', '..']));
    sort($names, SORT_STRING);
    return $names;
}

function verify_checksums(string $directory, string $source_name): void
{
    $names = [$source_name, 'source.json', 'source.spdx.json', 'source.provenance.json'];
    $complete = [...$names, 'SHA256SUMS'];
    sort($complete, SORT_STRING);
    if (directory_names($directory) !== $complete) { throw new ReleaseError('Source bundle has missing or unrecorded files.'); }
    $checksumFile = $directory . '/SHA256SUMS';
    if (is_link($checksumFile) || !is_file($checksumFile) || filesize($checksumFile) > 2048) {
        throw new ReleaseError('Invalid checksum inventory.');
    }
    $contents = read_bytes($checksumFile);
    $lines = preg_split('/\r\n|\n|\r/', $contents);
    if (end($lines) === '') { array_pop($lines); }
    $recorded = [];
    foreach ($lines as $line) {
        if (preg_match('/\A([a-f0-9]{64})  ([a-zA-Z0-9._-]+)\z/D', $line, $match) !== 1
                || isset($recorded[$match[2]])) { throw new ReleaseError('Invalid or duplicate checksum entry.'); }
        $recorded[$match[2]] = $match[1];
    }
    sort($names, SORT_STRING);
    $actual = array_keys($recorded);
    sort($actual, SORT_STRING);
    if ($actual !== $names) { throw new ReleaseError('Checksum inventory does not name exactly the source bundle.'); }
    foreach ($recorded as $name => $expected) {
        $path = $directory . '/' . $name;
        if (is_link($path) || !is_file($path)) { throw new ReleaseError('Evidence must be a regular file: ' . $name); }
        if (hash_file('sha256', $path) !== $expected) { throw new ReleaseError('Evidence checksum mismatch: ' . $name); }
    }
}

function remove_directory(string $directory): void
{
    if (is_link($directory) || !is_dir($directory)) { if (file_exists($directory) || is_link($directory)) { unlink($directory); } return; }
    foreach (directory_names($directory) as $name) { remove_directory($directory . '/' . $name); }
    rmdir($directory);
}

function arguments(array $argv): array
{
    $result = ['action' => null, 'directory' => null, 'expected_commit' => null, 'expected_sha256' => null,
               'tag' => null, 'require_stable' => false];
    for ($index = 1; $index < count($argv); ++$index) {
        $value = $argv[$index];
        if ($value === '--require-stable') { $result['require_stable'] = true; continue; }
        if (str_starts_with($value, '--')) {
            $parts = explode('=', $value, 2);
            $key = str_replace('-', '_', substr($parts[0], 2));
            if (!in_array($key, ['expected_commit', 'expected_sha256', 'tag'], true)) {
                throw new ReleaseError('Unknown option: ' . $value);
            }
            $result[$key] = $parts[1] ?? ($argv[++$index] ?? throw new ReleaseError('Missing option value.'));
            continue;
        }
        if ($result['action'] === null) { $result['action'] = $value; }
        elseif ($result['directory'] === null) { $result['directory'] = $value; }
        else { throw new ReleaseError('Unexpected argument: ' . $value); }
    }
    if (!in_array($result['action'], ['prepare', 'verify'], true) || $result['directory'] === null) {
        throw new ReleaseError('Usage: php tools/release-source.php prepare|verify DIRECTORY [--expected-commit SHA] [--expected-sha256 SHA] [--tag TAG] [--require-stable]');
    }
    return $result;
}

function main(array $argv): void
{
    $arguments = arguments($argv);
    $root = realpath(dirname(__DIR__)) ?: throw new ReleaseError('Cannot resolve source root.');
    if (is_link($arguments['directory'])) { throw new ReleaseError('Evidence directory may not be a symbolic link.'); }
    $destination = outside_source($root, $arguments['directory']);
    $commit = clean_source($root);
    if ($arguments['action'] === 'verify' && $arguments['expected_commit'] === null) {
        throw new ReleaseError('Verification requires an independently supplied --expected-commit.');
    }
    if ($arguments['expected_commit'] !== null && $arguments['expected_commit'] !== $commit) {
        throw new ReleaseError('Expected commit differs from the clean checked-out source.');
    }
    $tag = $arguments['tag'];
    if ($arguments['action'] === 'verify') {
        if (!is_dir($destination) || is_link($destination)) { throw new ReleaseError('Verification requires a regular source bundle directory.'); }
        verify_checksums($destination, archive_name($root));
        $sourceFile = $destination . '/source.json';
        if (is_link($sourceFile) || filesize($sourceFile) > 4 * 1024 * 1024) {
            throw new ReleaseError('Invalid or oversized bundle source record.');
        }
        $supplied = decode_object(read_bytes($sourceFile));
        if ($tag === null) {
            if (!array_key_exists('tag', $supplied['source'] ?? [])) { throw new ReleaseError('Missing bundle source tag.'); }
            $tag = $supplied['source']['tag'];
        }
    }
    if ($arguments['require_stable']) {
        require_stable(source_facts(archive_files(archive_bytes($root), kind($root)), kind($root)));
    }
    [$prepared, $record] = bundle($root, $commit, $tag);
    if ($arguments['expected_sha256'] !== null && $arguments['expected_sha256'] !== $record['archive']['sha256']) {
        throw new ReleaseError('Expected archive digest differs from the exact committed source.');
    }
    if ($arguments['require_stable']) { require_stable($record); }
    if ($arguments['action'] === 'prepare') {
        if (file_exists($destination) || is_link($destination)) {
            throw new ReleaseError('Refusing to overwrite an existing evidence directory.');
        }
        $parent = dirname($destination);
        if (!is_dir($parent) && !mkdir($parent, 0777, true) && !is_dir($parent)) {
            throw new ReleaseError('Cannot create evidence parent.');
        }
        $temporary = $parent . '/.kumwe-source-' . bin2hex(random_bytes(12));
        if (!mkdir($temporary, 0700)) { throw new ReleaseError('Cannot allocate temporary evidence directory.'); }
        try {
            foreach ($prepared as $name => $data) { write_bytes($temporary . '/' . $name, $data); }
            if (file_exists($destination) || is_link($destination) || !rename($temporary, $destination)) {
                throw new ReleaseError('Cannot install evidence directory without overwriting existing evidence.');
            }
        } finally { if (is_dir($temporary)) { remove_directory($temporary); } }
    } else {
        $names = array_keys($prepared);
        sort($names, SORT_STRING);
        if (directory_names($destination) !== $names) { throw new ReleaseError('Source bundle has missing or unrecorded files.'); }
        foreach ($prepared as $name => $expected) {
            $path = $destination . '/' . $name;
            if (is_link($path) || !is_file($path) || filesize($path) !== strlen($expected) || read_bytes($path) !== $expected) {
                throw new ReleaseError('Source bundle differs from reproducible committed input: ' . $name);
            }
        }
    }
    echo ($arguments['action'] === 'prepare' ? 'Prepared' : 'Verified') . ' exact ' . $record['package'] . ' source bundle for ' . $commit . "\n";
    echo "Unsigned source evidence only; independent release attestation and publication remain separate.\n";
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    try { main($argv); }
    catch (\Throwable $error) { fwrite(STDERR, 'Source release check failed: ' . $error->getMessage() . "\n"); exit(1); }
}
