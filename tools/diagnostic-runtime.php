#!/usr/bin/env php
<?php
declare(strict_types=1);
namespace Kumwe\Tools\DiagnosticRuntime;

/** Capture CI-installed PHP; verify every byte before explicit activation. */
const SCHEMA = 'kumwe-php-diagnostic-runtime/v1';
const MODULES = ['pdo', 'ctype', 'tokenizer', 'phar', 'fileinfo', 'iconv', 'mbstring',
    'intl', 'curl', 'dom', 'simplexml', 'xml', 'xmlreader', 'xmlwriter', 'zip', 'sodium'];
const MAX_FILES = 1024;
const MAX_BYTES = 512 * 1024 * 1024;
const ROOT = __DIR__ . '/..';
final class Invalid extends \RuntimeException {}

function command(array $arguments): array
{
    $stdout = tmpfile();
    $stderr = tmpfile();
    if ($stdout === false || $stderr === false) {
        throw new Invalid('Cannot allocate command output streams.');
    }
    $environment = getenv();
    $environment['LC_ALL'] = 'C';
    $process = proc_open($arguments, [0 => ['file', '/dev/null', 'r'], 1 => $stdout, 2 => $stderr],
        $pipes, null, $environment);
    if (!is_resource($process)) {
        throw new Invalid('Cannot start command: ' . $arguments[0]);
    }
    $status = proc_close($process);
    rewind($stdout);
    rewind($stderr);
    $result = [$status, stream_get_contents($stdout), stream_get_contents($stderr)];
    fclose($stdout);
    fclose($stderr);
    return $result;
}

function run(string ...$arguments): string
{
    [$status, $stdout, $stderr] = command($arguments);
    if ($status !== 0) {
        throw new Invalid('Command failed: ' . $arguments[0] . ': ' . trim($stderr));
    }
    return $stdout;
}

function sha(string $path): string
{
    return hash_file('sha256', $path) ?: throw new Invalid('Cannot hash file: ' . $path);
}

function sortedDocumentValue(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    if (!array_is_list($value)) {
        ksort($value, SORT_STRING);
    }
    return array_map(__NAMESPACE__ . '\\sortedDocumentValue', $value);
}

function document(array $value): string
{
    return json_encode(sortedDocumentValue($value),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
}

/** Validate JSON syntax, then reject duplicate decoded object keys at every depth. */
function decode(string $json): array
{
    $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    $offset = 0;
    $space = static function () use ($json, &$offset): void {
        while (isset($json[$offset]) && str_contains(" \r\n\t", $json[$offset])) {
            ++$offset;
        }
    };
    $string = static function () use ($json, &$offset): string {
        $start = $offset++;
        while ($json[$offset] !== '"') {
            if ($json[$offset] === '\\') {
                ++$offset;
            }
            ++$offset;
        }
        ++$offset;
        return json_decode(substr($json, $start, $offset - $start), true, 512, JSON_THROW_ON_ERROR);
    };
    $scan = null;
    $scan = static function () use ($json, &$offset, $space, $string, &$scan): void {
        $space();
        $character = $json[$offset];
        if ($character === '{') {
            ++$offset;
            $space();
            $keys = [];
            if ($json[$offset] !== '}') {
                do {
                    $space();
                    $key = $string();
                    if (array_key_exists($key, $keys)) {
                        throw new Invalid('Duplicate JSON object key.');
                    }
                    $keys[$key] = true;
                    $space();
                    ++$offset;
                    $scan();
                    $space();
                    if ($json[$offset] !== ',') {
                        break;
                    }
                    ++$offset;
                } while (true);
            }
            ++$offset;
        } elseif ($character === '[') {
            ++$offset;
            $space();
            if ($json[$offset] !== ']') {
                do {
                    $scan();
                    $space();
                    if ($json[$offset] !== ',') {
                        break;
                    }
                    ++$offset;
                } while (true);
            }
            ++$offset;
        } elseif ($character === '"') {
            $string();
        } else {
            while (isset($json[$offset]) && !str_contains(",]} \t\r\n", $json[$offset])) {
                ++$offset;
            }
        }
    };
    $scan();
    if (!is_array($value) || !str_starts_with(ltrim($json), '{')) {
        throw new Invalid('Expected a JSON object.');
    }
    return $value;
}

function regular(string $path): array
{
    clearstatcache(true, $path);
    $info = lstat($path);
    if ($info === false || ($info['mode'] & 0170000) !== 0100000 || $info['nlink'] !== 1) {
        throw new Invalid('Expected a regular, unlinked file: ' . $path);
    }
    return $info;
}

function absolute(string $path): string
{
    if (!str_starts_with($path, '/')) {
        $path = getcwd() . '/' . $path;
    }
    $parts = [];
    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            array_pop($parts);
        } else {
            $parts[] = $part;
        }
    }
    return '/' . implode('/', $parts);
}

function directory(string $path): string
{
    $path = absolute($path);
    $current = '';
    foreach (explode('/', ltrim($path, '/')) as $part) {
        $current .= '/' . $part;
        clearstatcache(true, $current);
        $info = lstat($current);
        if ($info === false || ($info['mode'] & 0170000) !== 0040000) {
            throw new Invalid('Directory path contains a symlink or non-directory: ' . $current);
        }
    }
    return $path;
}

function relative(mixed $name): string
{
    if (!is_string($name) || $name === '' || strlen($name) > 240
        || !preg_match('~\A[A-Za-z0-9._+/-]+\z~D', $name) || str_starts_with($name, '/')) {
        throw new Invalid('Unsafe fixture path.');
    }
    foreach (explode('/', $name) as $part) {
        if ($part === '' || $part === '.' || $part === '..') {
            throw new Invalid('Unsafe fixture path.');
        }
    }
    return $name;
}

function closure(string $root): array
{
    $files = [];
    $directories = [];
    $walk = null;
    $walk = static function (string $current, string $prefix) use (&$walk, &$files, &$directories): void {
        $names = scandir($current);
        if ($names === false) {
            throw new Invalid('Cannot read fixture directory: ' . $current);
        }
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $entry = relative($prefix . $name);
            $path = $current . '/' . $name;
            clearstatcache(true, $path);
            $info = lstat($path);
            if ($info !== false && ($info['mode'] & 0170000) === 0040000) {
                $directories[] = $entry;
                if (count($files) + count($directories) > MAX_FILES) {
                    throw new Invalid('Fixture file closure exceeds its bound.');
                }
                $walk($path, $entry . '/');
            } else {
                regular($path);
                $files[$entry] = $path;
            }
            if (count($files) + count($directories) > MAX_FILES) {
                throw new Invalid('Fixture file closure exceeds its bound.');
            }
        }
    };
    $walk($root, '');
    sort($directories, SORT_STRING);
    ksort($files, SORT_STRING);
    return [$files, $directories];
}

function write(string $path, string $bytes): void
{
    if (file_put_contents($path, $bytes) !== strlen($bytes)) {
        throw new Invalid('Cannot write file: ' . $path);
    }
}

function mkdirs(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0755, true)) {
        throw new Invalid('Cannot create directory: ' . $path);
    }
}

function removeTree(string $path): void
{
    if (is_link($path) || !is_dir($path)) {
        if (file_exists($path) || is_link($path)) {
            unlink($path);
        }
        return;
    }
    foreach (scandir($path) as $name) {
        if ($name !== '.' && $name !== '..') {
            removeTree($path . '/' . $name);
        }
    }
    rmdir($path);
}

function temporaryDirectory(string $parent, string $prefix): string
{
    directory($parent);
    $path = $parent . '/' . $prefix . bin2hex(random_bytes(12));
    if (!mkdir($path, 0700)) {
        throw new Invalid('Cannot allocate a private temporary directory.');
    }
    return $path;
}

/** Compare every verified byte through no-follow descriptors before fchmod. */
function activate(string $root, array $checked): void
{
    $temporary = temporaryDirectory(sys_get_temp_dir(), 'kumwe-activate-');
    $process = null;
    try {
        $helper = $temporary . '/activate';
        run('c++', '-std=c++20', '-O2', '-Wall', '-Wextra', '-Werror',
            __DIR__ . '/diagnostic-activate.cpp', '-o', $helper);
        $errors = tmpfile();
        if ($errors === false) {
            throw new Invalid('Cannot allocate activation diagnostics.');
        }
        $process = proc_open([$helper, $root],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => $errors], $pipes);
        if (!is_resource($process)) {
            throw new Invalid('Cannot start safe activation helper.');
        }
        $send = static function (string $bytes) use ($pipes): void {
            for ($offset = 0, $length = strlen($bytes); $offset < $length;) {
                $written = fwrite($pipes[0], substr($bytes, $offset));
                if ($written === false || $written === 0) {
                    throw new Invalid('Activation helper refused its input.');
                }
                $offset += $written;
            }
        };
        foreach ($checked as $name => [$file, $item, $previous]) {
            $send($name . "\t" . $item['bytes'] . "\t" . $item['mode'] . "\t"
                . $previous['dev'] . "\t" . $previous['ino'] . "\n");
            $handle = fopen($file, 'rb');
            if ($handle === false) {
                throw new Invalid('Cannot reopen verified file.');
            }
            $digest = hash_init('sha256');
            $remaining = $item['bytes'];
            try {
                while ($remaining > 0) {
                    $bytes = fread($handle, min($remaining, 1024 * 1024));
                    if ($bytes === false || $bytes === '') {
                        throw new Invalid('Fixture changed between verification and activation.');
                    }
                    hash_update($digest, $bytes);
                    $send($bytes);
                    $remaining -= strlen($bytes);
                }
                if (fread($handle, 1) !== '' || hash_final($digest) !== $item['sha256']) {
                    throw new Invalid('Fixture changed between verification and activation.');
                }
            } finally {
                fclose($handle);
            }
        }
        $send("COMMIT\n");
        fclose($pipes[0]);
        $status = proc_close($process);
        $process = null;
        rewind($errors);
        $detail = stream_get_contents($errors);
        fclose($errors);
        if ($status !== 0) {
            throw new Invalid('Activation refused: ' . trim($detail));
        }
    } finally {
        if (is_resource($process)) {
            if (isset($pipes[0]) && is_resource($pipes[0])) {
                fclose($pipes[0]);
            }
            proc_close($process);
        }
        removeTree($temporary);
    }
}

function verify(string $path, mixed $expectedCommit, bool $activate = false): array
{
    if (!is_string($expectedCommit) || !preg_match('/\A[a-f0-9]{40}\z/D', $expectedCommit)) {
        throw new Invalid('Verification requires an independently supplied full --expected-commit.');
    }
    $root = directory($path);
    $manifest = $root . '/fixture.json';
    if (regular($manifest)['size'] > 2 * 1024 * 1024) {
        throw new Invalid('Oversized diagnostic manifest.');
    }
    $data = decode(file_get_contents($manifest));
    if (($data['schema'] ?? null) !== SCHEMA || !is_array($data['source'] ?? null)
        || ($data['source']['repository'] ?? null) !== 'https://github.com/kumwe/kumwe-engine'
        || ($data['source']['commit'] ?? null) !== $expectedCommit
        || ($data['release_attestation'] ?? null) !== false || ($data['purpose'] ?? null) !== 'diagnostic-only') {
        throw new Invalid('Diagnostic source identity or purpose differs from the expected CI artifact.');
    }
    $facts = $data['php'] ?? null;
    if (!is_array($facts) || !is_string($facts['version'] ?? null)
        || !preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+[A-Za-z0-9.+-]*\z/D', $facts['version'])
        || ($data['required_extensions'] ?? null) !== MODULES) {
        throw new Invalid('Invalid PHP facts or required module inventory.');
    }
    $recorded = $data['files'] ?? null;
    $expectedDirectories = $data['directories'] ?? null;
    if (!is_array($recorded) || !$recorded || count($recorded) > MAX_FILES
        || !is_array($expectedDirectories) || !array_is_list($expectedDirectories)
        || count(array_filter($expectedDirectories, 'is_string')) !== count($expectedDirectories)
        || count(array_unique($expectedDirectories)) !== count($expectedDirectories)) {
        throw new Invalid('Invalid file closure inventory.');
    }
    if (array_key_exists('fixture.json', $recorded)) {
        throw new Invalid('Manifest must not recursively inventory itself.');
    }
    foreach ($expectedDirectories as $name) {
        relative($name);
    }
    [$actual, $directories] = closure($root);
    $expectedNames = array_merge(array_keys($recorded), ['fixture.json']);
    sort($expectedNames, SORT_STRING);
    sort($expectedDirectories, SORT_STRING);
    if (array_keys($actual) !== $expectedNames || $directories !== $expectedDirectories) {
        throw new Invalid('Fixture contains missing or unrecorded files/directories.');
    }
    foreach (['bin/php', 'runtime/php', 'php.ini'] as $name) {
        if (!array_key_exists($name, $recorded)) {
            throw new Invalid('Fixture omits its runtime entry points.');
        }
    }
    $loader = relative($data['loader'] ?? '');
    if (!str_starts_with($loader, 'lib/') || !array_key_exists($loader, $recorded)) {
        throw new Invalid('Fixture omits its captured loader.');
    }
    $checked = [];
    $total = 0;
    foreach ($recorded as $name => $item) {
        relative($name);
        if (!is_array($item) || !is_int($item['bytes'] ?? null) || $item['bytes'] < 0
            || $item['bytes'] > MAX_BYTES || !is_int($item['mode'] ?? null)
            || !in_array($item['mode'], [0644, 0755], true) || !is_string($item['sha256'] ?? null)
            || !preg_match('/\A[a-f0-9]{64}\z/D', $item['sha256'])) {
            throw new Invalid('Invalid file size, mode or digest: ' . $name);
        }
        $total += $item['bytes'];
        if ($total > MAX_BYTES) {
            throw new Invalid('Fixture byte closure exceeds its bound.');
        }
        $info = regular($actual[$name]);
        if ($info['size'] !== $item['bytes'] || sha($actual[$name]) !== $item['sha256']) {
            throw new Invalid('Fixture byte identity mismatch: ' . $name);
        }
        $checked[$name] = [$actual[$name], $item, $info];
    }
    if ($activate) {
        activate($root, $checked);
    }
    return $data;
}

/** The injected query permits deterministic package-database regressions. */
function packageFor(string $path, ?callable $query = null): string
{
    $paths = [$path, realpath($path) ?: $path];
    foreach ($paths as $name) {
        if (str_starts_with($name, '/usr/lib/') || str_starts_with($name, '/usr/bin/')) {
            $paths[] = substr($name, 4);
        } elseif (str_starts_with($name, '/lib/')) {
            $paths[] = '/usr' . $name;
        }
    }
    $query ??= __NAMESPACE__ . '\\command';
    foreach (array_unique($paths) as $name) {
        [$status, $stdout] = $query(['dpkg-query', '-S', $name]);
        if ($status !== 0) {
            continue;
        }
        foreach (preg_split('/\r?\n/', $stdout) as $line) {
            $separator = strrpos($line, ': ');
            if ($separator === false) {
                continue;
            }
            $owners = substr($line, 0, $separator);
            $ownedPath = substr($line, $separator + 2);
            if ($ownedPath === $name && !str_starts_with($owners, 'diversion')) {
                $owner = explode(', ', $owners)[0];
                if (preg_match('/\A[A-Za-z0-9.+:-]+\z/D', $owner)) {
                    return $owner;
                }
            }
        }
    }
    throw new Invalid('No installed package attribution for captured file: ' . $path);
}

function libraries(string $path): array
{
    $output = run('ldd', $path);
    if (str_contains($output, 'not found')) {
        throw new Invalid('Unresolved runtime ELF dependency: ' . $path);
    }
    $result = [];
    foreach (explode("\n", $output) as $line) {
        if (trim($line) === '' || preg_match('/^\s*linux-vdso\S* \(0x[0-9a-f]+\)/', $line)) {
            continue;
        }
        if (!preg_match('~\A\s*(?:(\S+) => )?(/\S+) \(0x[0-9a-f]+\)\s*\z~D', $line, $match)) {
            throw new Invalid('Unrecognized ldd dependency: ' . $line);
        }
        $name = $match[1] !== '' ? $match[1] : basename($match[2]);
        if (!preg_match('/\A[A-Za-z0-9._+-]+\z/D', $name)) {
            throw new Invalid('Unsafe library soname.');
        }
        $source = $match[2];
        if (isset($result[$name]) && sha($source) !== sha($result[$name])) {
            throw new Invalid('Conflicting library resolution.');
        }
        $result[$name] = $source;
    }
    return $result;
}

function executable(string $php): string
{
    $candidates = str_contains($php, '/') ? [$php]
        : array_map(static fn(string $part): string => $part . '/' . $php, explode(':', getenv('PATH') ?: ''));
    foreach ($candidates as $path) {
        if (is_file($path) && is_executable($path)) {
            return realpath($path);
        }
    }
    throw new Invalid('PHP executable is unavailable: ' . $php);
}

function capture(string $destination, string $php, ?string $module = null): array
{
    $destination = absolute($destination);
    if (file_exists($destination) || is_link($destination)) {
        throw new Invalid('Refusing to overwrite a diagnostic fixture.');
    }
    mkdirs(dirname($destination));
    directory(dirname($destination));
    $repository = realpath(ROOT);
    if ($destination === $repository || str_starts_with($destination, $repository . '/')) {
        throw new Invalid('Diagnostic binaries must be captured outside the repository.');
    }
    $commit = trim(run('git', '-C', $repository, 'rev-parse', 'HEAD'));
    if (trim(run('git', '-C', $repository, 'status', '--porcelain', '--untracked-files=no')) !== '') {
        throw new Invalid('Capture requires committed, unchanged tracked source.');
    }
    $executable = executable($php);
    $facts = decode(run($executable, '-n', '-r',
        'echo json_encode(["version"=>PHP_VERSION,"version_id"=>PHP_VERSION_ID,'
        . '"int_size"=>PHP_INT_SIZE,"zts"=>PHP_ZTS,"debug"=>PHP_DEBUG,'
        . '"sapi"=>PHP_SAPI,"os_family"=>PHP_OS_FAMILY,"architecture"=>php_uname("m"),'
        . '"extension_dir"=>ini_get("extension_dir"),'
        . '"builtin_extensions"=>get_loaded_extensions()], JSON_THROW_ON_ERROR);'));
    if ($facts['sapi'] !== 'cli' || $facts['os_family'] !== 'Linux'
        || $facts['version_id'] < 80500 || $facts['version_id'] >= 80600 || $facts['int_size'] !== 8
        || $facts['zts'] || $facts['debug']) {
        throw new Invalid('Capture requires the installed PHP 8.5 Linux 64-bit NTS non-debug CLI.');
    }
    if (!preg_match('/\[Requesting program interpreter: ([^\]]+)\]/',
        run('readelf', '-l', $executable), $interpreter)) {
        throw new Invalid('PHP does not identify its ELF dynamic loader.');
    }
    $loaderSource = $interpreter[1];
    $loaderName = relative('lib/' . basename($loaderSource));
    if (!str_starts_with($loaderSource, '/')) {
        throw new Invalid('Invalid ELF loader.');
    }
    $builtin = array_map('strtolower', $facts['builtin_extensions']);
    $modules = [];
    foreach (MODULES as $name) {
        if (!in_array($name, $builtin, true)) {
            $modules[$name] = $facts['extension_dir'] . '/' . $name . '.so';
        }
    }
    $resolved = [];
    $dependencyRoots = array_merge([$executable], array_values($modules));
    if ($module !== null) {
        $module = realpath($module) ?: throw new Invalid('Native module does not exist.');
        $dependencyRoots[] = $module;
    }
    foreach ($dependencyRoots as $path) {
        if (!is_file($path)) {
            throw new Invalid('Curated PHP module is not installed: ' . $path);
        }
        foreach (libraries($path) as $name => $source) {
            if (isset($resolved[$name]) && sha($source) !== sha($resolved[$name])) {
                throw new Invalid('Different libraries share a captured soname: ' . $name);
            }
            $resolved[$name] = $source;
        }
    }
    $root = temporaryDirectory(dirname($destination), '.php-diagnostic-');
    try {
        $origins = [];
        $owners = [];
        $copy = static function (string $source, string $name, bool $attributed = true)
            use ($root, &$origins, &$owners): void {
            relative($name);
            $target = $root . '/' . $name;
            if (!is_file($source)) {
                throw new Invalid('Captured dependency is not a regular file: ' . $source);
            }
            if (file_exists($target)) {
                if (sha($target) !== sha($source)) {
                    throw new Invalid('Captured filenames collide: ' . $name);
                }
                return;
            }
            mkdirs(dirname($target));
            if (!copy($source, $target) || !chmod($target, 0644)) {
                throw new Invalid('Cannot copy captured dependency: ' . $source);
            }
            $owner = $attributed ? packageFor($source) : null;
            $origins[$name] = ['path' => $source, 'resolved_path' => realpath($source), 'package' => $owner];
            if ($owner !== null) {
                $owners[$owner] = true;
            }
        };
        $copy($executable, 'runtime/php');
        $copy($loaderSource, $loaderName);
        ksort($resolved, SORT_STRING);
        foreach ($resolved as $name => $source) {
            $copy($source, 'lib/' . $name);
        }
        foreach ($modules as $name => $source) {
            $copy($source, 'extensions/' . $name . '.so');
        }
        $packageInventory = [];
        $queryFormat = '-f=' . implode("\t", array_map(static fn(string $name): string => '$' . '{' . $name . '}',
            ['binary:Package', 'Version', 'Architecture', 'source:Package', 'source:Version']));
        ksort($owners, SORT_STRING);
        foreach (array_keys($owners) as $owner) {
            $fields = explode("\t", run('dpkg-query', '-W', $queryFormat, $owner));
            if (count($fields) !== 5) {
                throw new Invalid('Incomplete installed package version record.');
            }
            $copyrightFile = '/usr/share/doc/' . explode(':', $owner)[0] . '/copyright';
            if (!is_file($copyrightFile)) {
                throw new Invalid('Installed package lacks copyright material: ' . $owner);
            }
            $target = 'licenses/packages/' . str_replace(':', '_', $owner) . '/copyright';
            $copy($copyrightFile, $target, false);
            $packageInventory[] = array_combine(
                ['package', 'version', 'architecture', 'source_package', 'source_version'], $fields)
                + ['copyright' => $target];
        }
        $common = '/usr/share/common-licenses';
        if (!is_dir($common)) {
            throw new Invalid('Installed common license texts are unavailable.');
        }
        foreach (scandir($common) as $name) {
            if ($name !== '.' && $name !== '..' && is_file($common . '/' . $name)) {
                $copy($common . '/' . $name, 'licenses/common/' . $name, false);
            }
        }
        mkdirs($root . '/bin');
        mkdirs($root . '/empty-ini');
        write($root . '/empty-ini/README.txt', "No scanned PHP configuration files.\n");
        mkdirs($root . '/extensions');
        if (!$modules) {
            write($root . '/extensions/README.txt', "All curated extensions are built in.\n");
        }
        $wrapper = "#!/bin/sh\nset -eu\n"
            . 'fixture_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd -P)' . "\n"
            . 'export KUMWE_DIAGNOSTIC_ROOT="$fixture_root"' . "\n"
            . 'export PHPRC="$fixture_root/php.ini"' . "\n"
            . 'export PHP_INI_SCAN_DIR="$fixture_root/empty-ini"' . "\n"
            . "unset LD_PRELOAD LD_AUDIT LD_LIBRARY_PATH\n"
            . 'exec "$fixture_root/' . $loaderName . '" --inhibit-cache '
            . '--library-path "$fixture_root/lib" "$fixture_root/runtime/php" "$@"' . "\n";
        write($root . '/bin/php', $wrapper);
        $ini = "; Diagnostic CI host; no kumwe_engine module is loaded by default.\ndate.timezone=UTC\n"
            . 'extension_dir="' . '$' . '{KUMWE_DIAGNOSTIC_ROOT}/extensions"' . "\n";
        foreach (array_keys($modules) as $name) {
            $ini .= 'extension=' . $name . ".so\n";
        }
        write($root . '/php.ini', $ini);
        foreach (['bin/php', 'runtime/php', $loaderName] as $name) {
            if (!chmod($root . '/' . $name, 0755)) {
                throw new Invalid('Cannot set diagnostic entrypoint permissions.');
            }
        }
        [$files, $directories] = closure($root);
        $inventory = [];
        foreach ($files as $name => $path) {
            $info = regular($path);
            $inventory[$name] = ['sha256' => sha($path), 'bytes' => $info['size'], 'mode' => $info['mode'] & 07777];
        }
        $record = [
            'schema' => SCHEMA, 'purpose' => 'diagnostic-only', 'release_attestation' => false,
            'source' => ['repository' => 'https://github.com/kumwe/kumwe-engine', 'commit' => $commit],
            'php' => $facts, 'required_extensions' => MODULES, 'loader' => $loaderName,
            'packages' => $packageInventory, 'origins' => $origins,
            'native_module_probe' => $module === null ? null
                : ['name' => basename($module), 'sha256' => sha($module), 'included' => false],
            'host_resources' => ['Linux kernel, /proc and /dev', 'Host timezone database',
                'Host DNS configuration and CA certificates when networking is used'],
            'trust' => 'Obtain from the trusted CI run for the independently expected commit. '
                . 'The manifest checks file consistency; it is not an artifact signature.',
            'directories' => $directories, 'files' => $inventory,
        ];
        write($root . '/fixture.json', document($record));
        verify($root, $commit);
        if (trim(run('git', '-C', $repository, 'rev-parse', 'HEAD')) !== $commit) {
            throw new Invalid('Source head changed during runtime capture.');
        }
        if (trim(run('git', '-C', $repository, 'status', '--porcelain', '--untracked-files=no')) !== '') {
            throw new Invalid('Tracked source changed during runtime capture.');
        }
        if (file_exists($destination) || is_link($destination) || !rename($root, $destination)) {
            throw new Invalid('Diagnostic destination appeared or could not be published.');
        }
        return $record;
    } finally {
        removeTree($root);
    }
}

function copyTree(string $source, string $target): void
{
    mkdirs($target);
    foreach (scandir($source) as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        if (is_dir($source . '/' . $name)) {
            copyTree($source . '/' . $name, $target . '/' . $name);
        } elseif (!copy($source . '/' . $name, $target . '/' . $name)) {
            throw new Invalid('Cannot copy self-test fixture.');
        }
    }
}

function selfTest(): void
{
    $expected = str_repeat('a', 40);
    $count = 0;
    $temporary = temporaryDirectory(sys_get_temp_dir(), 'php diagnostic verifier ');
    try {
        $base = $temporary . '/original';
        foreach (['bin/php', 'runtime/php', 'php.ini', 'lib/ld-linux.so'] as $name) {
            $path = $base . '/' . $name;
            mkdirs(dirname($path));
            write($path, "inert test bytes\\n");
            chmod($path, 0644);
        }
        [$files, $directories] = closure($base);
        $inventory = [];
        foreach ($files as $name => $path) {
            $inventory[$name] = ['sha256' => sha($path), 'bytes' => regular($path)['size'],
                'mode' => $name === 'php.ini' ? 0644 : 0755];
        }
        $data = ['schema' => SCHEMA, 'purpose' => 'diagnostic-only', 'release_attestation' => false,
            'source' => ['repository' => 'https://github.com/kumwe/kumwe-engine', 'commit' => $expected],
            'php' => ['version' => '8.5.10'], 'required_extensions' => MODULES,
            'loader' => 'lib/ld-linux.so', 'directories' => $directories, 'files' => $inventory];
        write($base . '/fixture.json', document($data));
        verify($base, $expected);
        if ((regular($base . '/bin/php')['mode'] & 07777) !== 0644) {
            throw new Invalid('Read-only verification altered permissions.');
        }
        $relocated = $temporary . '/relocated download';
        copyTree($base, $relocated);
        verify($relocated, $expected, true);
        if ((regular($relocated . '/bin/php')['mode'] & 07777) !== 0755) {
            throw new Invalid('Verified activation did not restore executable permission.');
        }
        $count += 2;
        $refuse = static function (callable $mutate, mixed $selected, ?callable $rewrite = null)
            use ($temporary, $base, &$count): void {
            $target = $temporary . '/refusal-' . $count;
            copyTree($base, $target);
            if ($rewrite !== null) {
                $changed = decode(file_get_contents($target . '/fixture.json'));
                $rewrite($changed);
                write($target . '/fixture.json', document($changed));
            }
            $mutate($target);
            try {
                verify($target, $selected, true);
            } catch (\Throwable) {
                ++$count;
                $entry = $target . '/bin/php';
                if (file_exists($entry) && !is_link($entry) && (regular($entry)['mode'] & 07777) !== 0644) {
                    throw new Invalid('Refused fixture was activated.');
                }
                return;
            }
            throw new Invalid('Verifier admitted a malformed fixture.');
        };
        $nothing = static function (string $path): void {};
        $refuse($nothing, str_repeat('b', 40));
        $refuse($nothing, null);
        $refuse(static fn(string $path) => write($path . '/runtime/php', 'corrupt'), $expected);
        $refuse(static fn(string $path) => write($path . '/extra', 'extra'), $expected);
        $refuse(static fn(string $path) => mkdir($path . '/empty-extra'), $expected);
        $refuse(static fn(string $path) => unlink($path . '/php.ini'), $expected);
        $refuse(static fn(string $path) => write($path . '/fixture.json',
            '{"schema":"duplicate",' . substr(file_get_contents($path . '/fixture.json'), 1)), $expected);
        foreach ([true, -1, '1'] as $wrong) {
            $refuse($nothing, $expected, static function (array &$data) use ($wrong): void {
                $data['files']['php.ini']['bytes'] = $wrong;
            });
        }
        foreach (['../escape', '/absolute', 'lib//alias', 'lib/./alias'] as $name) {
            $refuse($nothing, $expected, static function (array &$data) use ($name): void {
                $data['files'][$name] = $data['files']['php.ini'];
                unset($data['files']['php.ini']);
            });
        }
        $refuse($nothing, $expected, static function (array &$data): void { $data['php'] = null; });
        $linkFile = static function (string $path, string $name) use ($base): void {
            unlink($path . '/' . $name);
            symlink($base . '/' . $name, $path . '/' . $name);
        };
        $refuse(static fn(string $path) => $linkFile($path, 'runtime/php'), $expected);
        $refuse(static fn(string $path) => $linkFile($path, 'fixture.json'), $expected);
        $refuse(static function (string $path) use ($base): void {
            removeTree($path . '/lib');
            symlink($base . '/lib', $path . '/lib');
        }, $expected);
        $link = $temporary . '/root-link';
        symlink($base, $link);
        try {
            verify($link, $expected);
        } catch (Invalid) {
            ++$count;
        }
        if ($count !== 21) {
            throw new Invalid('Symlink fixture root was admitted or self-test coverage changed unexpectedly.');
        }
        try {
            decode('{"schema":1,"\u0073chema":2}');
            throw new \LogicException('Encoded duplicate JSON key was admitted.');
        } catch (Invalid) {
            ++$count;
        }
        echo $count . " diagnostic verifier closure/identity/activation checks passed.\n";
    } finally {
        removeTree($temporary);
    }
}

function main(array $arguments): void
{
    $action = array_shift($arguments);
    if ($action === '--help' || $action === '-h') {
        echo "Usage: diagnostic-runtime.php capture DIRECTORY [--php EXECUTABLE] [--module MODULE]\n"
            . "       diagnostic-runtime.php verify DIRECTORY --expected-commit SHA [--activate]\n"
            . "       diagnostic-runtime.php self-test\n";
        return;
    }
    if (!in_array($action, ['capture', 'verify', 'self-test'], true)) {
        throw new Invalid('Expected capture, verify or self-test; use --help for usage.');
    }
    $directory = null;
    $options = ['php' => 'php', 'module' => null, 'expected-commit' => null, 'activate' => false];
    $seen = [];
    while ($arguments) {
        $argument = array_shift($arguments);
        if (str_starts_with($argument, '--')) {
            $pair = explode('=', substr($argument, 2), 2);
            $name = $pair[0];
            if (!array_key_exists($name, $options) || isset($seen[$name])) {
                throw new Invalid('Unknown or duplicate option: --' . $name);
            }
            $seen[$name] = true;
            if ($name === 'activate') {
                if (count($pair) !== 1) {
                    throw new Invalid('--activate accepts no value.');
                }
                $options[$name] = true;
            } else {
                $value = $pair[1] ?? array_shift($arguments);
                if (!is_string($value) || $value === '' || str_starts_with($value, '--')) {
                    throw new Invalid('Missing value for --' . $name);
                }
                $options[$name] = $value;
            }
        } elseif ($directory === null) {
            $directory = $argument;
        } else {
            throw new Invalid('Unexpected additional directory.');
        }
    }
    if ($action === 'self-test') {
        if ($directory !== null || $options['activate'] || $options['module'] !== null
            || $options['expected-commit'] !== null) {
            throw new Invalid('Self-test accepts no fixture options.');
        }
        selfTest();
        return;
    }
    if ($directory === null) {
        throw new Invalid('Capture/verify requires a fixture directory.');
    }
    if ($action === 'capture') {
        if ($options['activate'] || $options['expected-commit'] !== null) {
            throw new Invalid('Capture does not accept verification/activation options.');
        }
        $record = capture($directory, $options['php'], $options['module']);
    } else {
        if ($options['module'] !== null) {
            throw new Invalid('Verification never probes executable/module dependencies.');
        }
        $record = verify($directory, $options['expected-commit'], $options['activate']);
    }
    echo ($action === 'capture' ? 'Captured' : 'Verified') . ' diagnostic PHP '
        . $record['php']['version'] . ' for ' . $record['source']['commit'] . "\n";
    echo "Diagnostic-only dependency inventory; no release verification or attestation.\n";
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
        throw new \ErrorException($message, 0, $severity, $file, $line);
    });
    try {
        main(array_slice($argv, 1));
    } catch (\Throwable $error) {
        fwrite(STDERR, 'Diagnostic runtime refused: ' . $error->getMessage() . "\n");
        exit(1);
    }
}
