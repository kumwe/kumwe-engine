#!/usr/bin/env php
<?php
declare(strict_types=1);

namespace Kumwe\ReleaseSource;

require_once __DIR__ . '/engine-source.php';

use function Kumwe\EngineSource\bindingVersion;
use function Kumwe\EngineSource\validateLock;

/**
 * Prepare and verify the committed binding source bundle:
 *   kumwe-engine-php-source.tar.gz  reproducible committed export (git archive, prefix kumwe-engine-php/, gzip -n)
 *   source.spdx.json                complete SPDX inventory of exactly that export
 *   source.json                     exact binding, archive, PHP and embedded Engine identities
 *   SHA256SUMS                      digests of the three files above
 * It never tags, signs or publishes; the workflow does that after every quality lane passes.
 */
class ReleaseError extends \RuntimeException {}

const PACKAGE = 'kumwe/kumwe-engine';
const ARCHIVE_NAME = 'kumwe-engine-php-source.tar.gz';
const ARCHIVE_PREFIX = 'kumwe-engine-php/';
const BUNDLE_SCHEMA = 'kumwe-engine-php-source-release/v1';

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

function decode_object(string $data): array
{
    $value = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($value) || ($value !== [] && array_is_list($value))) {
        throw new ReleaseError('JSON must be an object.');
    }
    return $value;
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

function archive_bytes(string $root): string
{
    $tar = run($root, 'git', 'archive', '--format=tar', '--prefix=' . ARCHIVE_PREFIX, 'HEAD');
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
        $pax = [];
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

/**
 * Read a gzip-compressed source archive without extraction. Every regular file is returned as
 * path => ['data' => bytes, 'mode' => permission bits], relative to the single declared root.
 */
function archive_files(string $data, string $prefix): array
{
    $tar = @gzdecode($data);
    if ($tar === false) { throw new ReleaseError('Invalid compressed source archive.'); }
    $files = [];
    $names = [];
    $forbidden = ['.git', '.github', 'node_modules', 'artifacts', 'build', 'engine-build', 'modules', '.libs', 'autom4te.cache'];
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
        $files[$relative] = ['data' => $member['data'], 'mode' => $member['mode'] & 0777];
    }
    if ($files === []) { throw new ReleaseError('An empty archive is not a source package.'); }
    ksort($files, SORT_STRING);
    return $files;
}

/** path => bytes view of archive_files(). */
function archive_contents(array $files): array
{
    return array_map(static fn(array $entry): string => $entry['data'], $files);
}

function read_json(array $files, string $path): array
{
    try { return decode_object($files[$path] ?? throw new ReleaseError('Missing JSON.')); }
    catch (\JsonException $error) { throw new ReleaseError('Invalid source JSON: ' . $path, 0, $error); }
    catch (ReleaseError $error) { throw new ReleaseError('Missing or invalid source JSON: ' . $path, 0, $error); }
}

function binding_sbom(array $files, string $commit, string $timestamp): string
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
    $lock = read_json($files, 'resources/engine-lock.json');
    $packages = [['SPDXID' => 'SPDXRef-Binding', 'name' => PACKAGE, 'versionInfo' => $commit,
        'downloadLocation' => 'git+https://github.com/' . PACKAGE . '.git@' . $commit,
        'filesAnalyzed' => true, 'packageVerificationCode' => ['packageVerificationCodeValue' => digest(implode('', $hashes), 'sha1')],
        'licenseConcluded' => 'NOASSERTION', 'licenseDeclared' => 'Apache-2.0', 'copyrightText' => 'NOASSERTION'],
        ['SPDXID' => 'SPDXRef-EmbeddedEngine', 'name' => 'kumwe/engine', 'versionInfo' => $lock['version'] . '+' . $lock['commit'],
        'downloadLocation' => 'git+' . $lock['repository'] . '.git@' . $lock['commit'], 'filesAnalyzed' => false,
        'licenseConcluded' => 'NOASSERTION', 'licenseDeclared' => 'Apache-2.0 AND BSD-3-Clause AND BSD-2-Clause AND PHP-3.01 AND Unicode-3.0',
        'copyrightText' => 'NOASSERTION']];
    $relationships = [['spdxElementId' => 'SPDXRef-DOCUMENT', 'relatedSpdxElement' => 'SPDXRef-Binding', 'relationshipType' => 'DESCRIBES'],
        ['spdxElementId' => 'SPDXRef-Binding', 'relatedSpdxElement' => 'SPDXRef-EmbeddedEngine', 'relationshipType' => 'DEPENDS_ON']];
    foreach ($entries as $entry) {
        $relationships[] = ['spdxElementId' => 'SPDXRef-Binding', 'relatedSpdxElement' => $entry['SPDXID'], 'relationshipType' => 'CONTAINS'];
    }
    return encode(['spdxVersion' => 'SPDX-2.3', 'dataLicense' => 'CC0-1.0', 'SPDXID' => 'SPDXRef-DOCUMENT',
        'name' => 'Kumwe PHP binding committed source inventory',
        'documentNamespace' => 'https://github.com/' . PACKAGE . '/sbom/' . $commit,
        'creationInfo' => ['created' => $timestamp, 'creators' => ['Tool: kumwe-native-release-source']],
        'packages' => $packages, 'files' => $entries, 'relationships' => $relationships]);
}

/** Exact facts about the exported source: binding version, hard-linked Engine identity and public manifests. */
function source_facts(array $files): array
{
    $lock = read_json($files, 'resources/engine-lock.json');
    try { validateLock($lock); }
    catch (\RuntimeException $error) { throw new ReleaseError($error->getMessage(), 0, $error); }
    $embedded = [];
    foreach ($files as $path => $data) {
        if (str_starts_with($path, 'vendor/engine/')) { $embedded[substr($path, strlen('vendor/engine/'))] = digest($data); }
    }
    if ($embedded !== $lock['files']) {
        throw new ReleaseError('Embedded Engine source closure differs from the exact lock.');
    }
    if (!preg_match('/^#define PHP_KUMWE_ENGINE_VERSION "([^"]+)"$/m', $files['php_kumwe_engine.h'] ?? '', $match)) {
        throw new ReleaseError('The exported source must declare the extension version.');
    }
    $compatibility = read_json($files, 'resources/compatibility/v1.json');
    $composer = read_json($files, 'composer.json');
    $capabilities = read_json($files, 'vendor/engine/resources/capabilities.json');
    $abi = read_json($files, 'vendor/engine/resources/abi-manifest.json');
    if ($match[1] !== $lock['version'] || ($compatibility['version'] ?? null) !== $lock['version']
            || ($capabilities['version'] ?? null) !== $lock['version']) {
        throw new ReleaseError('The extension version must equal the embedded Engine version everywhere; versions are hard-linked.');
    }
    if (($composer['name'] ?? null) !== PACKAGE || ($composer['type'] ?? null) !== 'php-ext'
            || ($composer['php-ext']['extension-name'] ?? null) !== 'kumwe_engine') {
        throw new ReleaseError('composer.json does not describe the PIE package.');
    }
    $materials = [];
    foreach (['LICENSE', 'composer.json', 'config.m4', 'php_kumwe_engine.h', 'php_kumwe_engine_build.h',
              'resources/api/v1.json', 'resources/compatibility/v1.json', 'resources/engine-lock.json',
              'vendor/engine/resources/capabilities.json', 'vendor/engine/resources/abi-manifest.json'] as $path) {
        if (!isset($files[$path])) { throw new ReleaseError('Required source distribution material is absent: ' . $path); }
        $materials[] = ['path' => $path, 'sha256' => digest($files[$path])];
    }
    return [
        'version' => $lock['version'],
        'tag' => 'v' . $lock['version'],
        'php' => ['requirement' => $composer['require']['php'] ?? null,
                  'thread_safety' => ['nts' => (bool) ($composer['php-ext']['support-nts'] ?? false),
                                      'zts' => (bool) ($composer['php-ext']['support-zts'] ?? false)],
                  'os_families' => $composer['php-ext']['os-families'] ?? []],
        'engine' => ['repository' => $lock['repository'], 'version' => $lock['version'], 'release' => $lock['release'],
                     'commit' => $lock['commit'], 'archive_name' => $lock['archive_name'],
                     'archive_sha256' => $lock['archive_sha256'], 'files' => count($lock['files'])],
        'abi' => ['major' => $abi['abi_major'] ?? null, 'minor' => $abi['abi_minor'] ?? null, 'status' => $abi['status'] ?? null],
        'capabilities' => $capabilities['capabilities'] ?? [],
        'materials' => $materials,
    ];
}

function source_record(string $root, string $commit, array $files, string $archive, string $sbom): array
{
    $tree = trim(run($root, 'git', 'rev-parse', 'HEAD^{tree}'));
    $timestamp = gmdate('Y-m-d\TH:i:s\Z', (int) trim(run($root, 'git', 'show', '-s', '--format=%ct', $commit)));
    $facts = source_facts($files);
    return ['schema' => BUNDLE_SCHEMA, 'package' => PACKAGE, 'version' => $facts['version'], 'tag' => $facts['tag'],
        'source' => ['repository' => 'https://github.com/' . PACKAGE, 'commit' => $commit, 'tree' => $tree,
                     'committed_at' => $timestamp],
        'archive' => ['name' => ARCHIVE_NAME, 'prefix' => ARCHIVE_PREFIX, 'sha256' => digest($archive), 'bytes' => strlen($archive),
                      'recipe' => 'git archive --format=tar --prefix=' . ARCHIVE_PREFIX . ' COMMIT | gzip -n'],
        'sbom' => ['name' => 'source.spdx.json', 'sha256' => digest($sbom)],
        'php' => $facts['php'], 'engine' => $facts['engine'], 'abi' => $facts['abi'],
        'capabilities' => $facts['capabilities'], 'materials' => $facts['materials']];
}

function bundle(string $root, string $commit): array
{
    $first = archive_bytes($root);
    $second = archive_bytes($root);
    if ($first !== $second) { throw new ReleaseError('Repeated committed source archives are not byte-reproducible.'); }
    $files = archive_contents(archive_files($first, ARCHIVE_PREFIX));
    foreach ($files as $path => $data) {
        if (str_starts_with($path, 'vendor/engine/')) {
            try { \Kumwe\EngineSource\nativeFile(substr($path, strlen('vendor/engine/')), $data); }
            catch (\RuntimeException $error) { throw new ReleaseError($error->getMessage(), 0, $error); }
        } elseif (preg_match('/\.(?:py[coiw]?|mjs|cjs|js|ts)$/iD', $path)) {
            throw new ReleaseError('Unsupported implementation language in the binding source: ' . $path);
        }
    }
    $timestamp = gmdate('Y-m-d\TH:i:s\Z', (int) trim(run($root, 'git', 'show', '-s', '--format=%ct', $commit)));
    $sbom = binding_sbom($files, $commit, $timestamp);
    $listed = [];
    foreach (decode_object($sbom)['files'] as $entry) {
        foreach ($entry['checksums'] as $checksum) {
            if ($checksum['algorithm'] === 'SHA256') { $listed[substr($entry['fileName'], 2)] = $checksum['checksumValue']; }
        }
    }
    if ($listed !== array_map(digest(...), $files)) {
        throw new ReleaseError('SPDX inventory does not describe exactly the exported source archive.');
    }
    $record = source_record($root, $commit, $files, $first, $sbom);
    $result = [ARCHIVE_NAME => $first, 'source.spdx.json' => $sbom, 'source.json' => encode($record)];
    $sums = '';
    foreach ($result as $name => $data) { $sums .= digest($data) . '  ' . $name . "\n"; }
    $result['SHA256SUMS'] = $sums;
    if (clean_source($root) !== $commit) {
        throw new ReleaseError('Source changed while preparing its bundle; repeat against one committed head.');
    }
    return [$result, $record];
}

function resolve_path(string $path): string
{
    $absolute = str_starts_with($path, '/') ? $path : getcwd() . '/' . $path;
    $parts = [];
    foreach (explode('/', $absolute) as $part) {
        if ($part === '' || $part === '.') { continue; }
        if ($part === '..') { array_pop($parts); continue; }
        $parts[] = $part;
    }
    $resolved = '/' . implode('/', $parts);
    for ($probe = $resolved; $probe !== '/' && $probe !== ''; $probe = dirname($probe)) {
        if (is_link($probe)) { throw new ReleaseError('Evidence paths may not traverse symbolic links.'); }
        if (file_exists($probe)) { return realpath($probe) . substr($resolved, strlen($probe)); }
    }
    return $resolved;
}

function outside_source(string $root, string $path): string
{
    $resolved = resolve_path($path);
    if ($resolved === $root || str_starts_with($resolved, $root . '/')) {
        throw new ReleaseError('Source evidence must be stored outside the tested repository.');
    }
    return $resolved;
}

function directory_names(string $directory): array
{
    $names = array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
    sort($names, SORT_STRING);
    return $names;
}

function verify_checksums(string $directory): void
{
    $names = [ARCHIVE_NAME, 'source.json', 'source.spdx.json'];
    sort($names, SORT_STRING);
    $expected = [...$names, 'SHA256SUMS'];
    sort($expected, SORT_STRING);
    if (directory_names($directory) !== $expected) { throw new ReleaseError('Source bundle has missing or unrecorded files.'); }
    $checksumFile = $directory . '/SHA256SUMS';
    if (is_link($checksumFile) || !is_file($checksumFile) || filesize($checksumFile) > 2048) {
        throw new ReleaseError('Invalid checksum inventory.');
    }
    $recorded = [];
    foreach (explode("\n", rtrim(read_bytes($checksumFile), "\n")) as $line) {
        if (!preg_match('/\A([a-f0-9]{64})  ([a-zA-Z0-9._-]+)\z/D', $line, $match) || isset($recorded[$match[2]])) {
            throw new ReleaseError('Invalid or duplicate checksum entry.');
        }
        $recorded[$match[2]] = $match[1];
    }
    $keys = array_keys($recorded);
    sort($keys, SORT_STRING);
    if ($keys !== $names) { throw new ReleaseError('Checksum inventory does not name exactly the source bundle.'); }
    foreach ($recorded as $name => $expected) {
        $path = $directory . '/' . $name;
        if (is_link($path) || !is_file($path)) { throw new ReleaseError('Evidence must be a regular file: ' . $name); }
        if (hash_file('sha256', $path) !== $expected) { throw new ReleaseError('Evidence checksum mismatch: ' . $name); }
    }
}

function remove_directory(string $directory): void
{
    foreach (directory_names($directory) as $name) {
        $path = $directory . '/' . $name;
        if (is_dir($path) && !is_link($path)) { remove_directory($path); } else { unlink($path); }
    }
    rmdir($directory);
}

function arguments(array $argv): array
{
    $result = ['action' => null, 'directory' => null, 'expected_commit' => null, 'expected_sha256' => null];
    for ($index = 1; $index < count($argv); ++$index) {
        $value = $argv[$index];
        if (str_starts_with($value, '--')) {
            $parts = explode('=', $value, 2);
            $key = str_replace('-', '_', substr($parts[0], 2));
            if (!in_array($key, ['expected_commit', 'expected_sha256'], true)) {
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
        throw new ReleaseError('Usage: php tools/release-source.php prepare|verify DIRECTORY [--expected-commit SHA] [--expected-sha256 SHA]');
    }
    return $result;
}

function main(array $argv): void
{
    $arguments = arguments($argv);
    // KUMWE_ROOT points the tooling at another checkout of this repository (the workflow completes a
    // release for an older tagged commit with the current scripts by checking that commit out separately).
    $root = realpath(getenv('KUMWE_ROOT') ?: dirname(__DIR__)) ?: throw new ReleaseError('Cannot resolve source root.');
    if (is_link($arguments['directory'])) { throw new ReleaseError('Evidence directory may not be a symbolic link.'); }
    $destination = outside_source($root, $arguments['directory']);
    $commit = clean_source($root);
    if ($arguments['action'] === 'verify' && $arguments['expected_commit'] === null) {
        throw new ReleaseError('Verification requires an independently supplied --expected-commit.');
    }
    if ($arguments['expected_commit'] !== null && $arguments['expected_commit'] !== $commit) {
        throw new ReleaseError('Expected commit differs from the clean checked-out source.');
    }
    if ($arguments['action'] === 'verify') {
        if (!is_dir($destination) || is_link($destination)) { throw new ReleaseError('Verification requires a regular source bundle directory.'); }
        verify_checksums($destination);
    }
    [$prepared, $record] = bundle($root, $commit);
    if ($arguments['expected_sha256'] !== null && $arguments['expected_sha256'] !== $record['archive']['sha256']) {
        throw new ReleaseError('Expected archive digest differs from the exact committed source.');
    }
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
    echo ($arguments['action'] === 'prepare' ? 'Prepared' : 'Verified') . ' exact ' . PACKAGE . ' ' . $record['version']
        . ' source bundle for ' . $commit . ' (embedded Engine ' . ($record['engine']['release'] ?? 'unreleased source')
        . ' at ' . $record['engine']['commit'] . ")\n";
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    try { main($argv); }
    catch (\Throwable $error) { fwrite(STDERR, 'Source release check failed: ' . $error->getMessage() . "\n"); exit(1); }
}
