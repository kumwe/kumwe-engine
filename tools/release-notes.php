<?php
declare(strict_types=1);

/** Render the GitHub release notes for an assembled binding source bundle (see release-source.php) to stdout. */
$bundle = $argv[1] ?? throw new InvalidArgumentException('Usage: php tools/release-notes.php BUNDLE_DIRECTORY');
$record = json_decode(file_get_contents($bundle . '/source.json') ?: throw new RuntimeException('Missing source.json'), true, 512, JSON_THROW_ON_ERROR);
$root = dirname(__DIR__);
$commit = $record['source']['commit'];
$version = $record['version'];
$engine = $record['engine'];
$sourceUrl = 'https://github.com/kumwe/kumwe-engine/blob/' . $commit;
$engineUrl = 'https://github.com/kumwe/engine/releases/tag/' . $engine['release'];
$previous = trim((string) shell_exec('git -C ' . escapeshellarg($root) . ' describe --tags --abbrev=0 --match "v[0-9]*" '
    . escapeshellarg($commit . '^') . ' 2>/dev/null'));

$lines = [];
$lines[] = 'Kumwe Engine PHP binding ' . $version . ': immutable source release from `' . $commit . '`.';
$lines[] = '';
$lines[] = 'Every lane of the `Native binding candidate` workflow (source packaging, NTS and ZTS builds with PHPT and Valgrind, '
    . 'ASan/UBSan, network-isolated PIE install and the whole-boundary benchmark) passed on this exact commit before the tag was created. '
    . 'The attached archive, SPDX inventory and checksums carry GitHub OIDC build provenance; verify a download with '
    . '`sha256sum --check SHA256SUMS` and `gh attestation verify kumwe-engine-php-source.tar.gz --repo kumwe/kumwe-engine`.';
$lines[] = '';
$lines[] = '[Versioning and releases](' . $sourceUrl . '/docs/releasing.md) · [Security policy](' . $sourceUrl . '/SECURITY.md) · '
    . '[Changelog](' . $sourceUrl . '/CHANGELOG.md) · [PHP API](' . $sourceUrl . '/resources/api/v1.json)';
$lines[] = '';
$lines[] = '### Embedded Engine (hard-linked version)';
$lines[] = '';
$lines[] = '| Field | Value |';
$lines[] = '|---|---|';
$lines[] = '| Engine release | [' . $engine['release'] . '](' . $engineUrl . ') |';
$lines[] = '| Engine commit | `' . $engine['commit'] . '` |';
$lines[] = '| Engine archive SHA-256 | `' . $engine['archive_sha256'] . '` (' . $engine['files'] . ' files embedded unchanged) |';
$lines[] = '| C ABI | `' . $record['abi']['major'] . '.' . $record['abi']['minor'] . '` (' . $record['abi']['status'] . ') |';
$lines[] = '';
$lines[] = '### Binding source identity';
$lines[] = '';
$lines[] = '| Field | Value |';
$lines[] = '|---|---|';
$lines[] = '| Version | `' . $version . '` |';
$lines[] = '| Commit | `' . $commit . '` |';
$lines[] = '| Tree | `' . $record['source']['tree'] . '` |';
$lines[] = '| Archive | `' . $record['archive']['name'] . '` (' . $record['archive']['bytes'] . ' bytes) |';
$lines[] = '| Archive SHA-256 | `' . $record['archive']['sha256'] . '` |';
$lines[] = '| PHP | `' . $record['php']['requirement'] . '`, NTS ' . ($record['php']['thread_safety']['nts'] ? 'yes' : 'no')
    . ', ZTS ' . ($record['php']['thread_safety']['zts'] ? 'yes' : 'no') . ', ' . implode('/', $record['php']['os_families']) . ' |';
$lines[] = '';
$lines[] = '### Capabilities';
$lines[] = '';
foreach ($record['capabilities'] as $capability) { $lines[] = '- `' . $capability . '`'; }
$lines[] = '';
$lines[] = '### Install';
$lines[] = '';
$lines[] = '```sh';
$lines[] = 'pie install kumwe/kumwe-engine:' . $version;
$lines[] = '```';
$lines[] = '';
$lines[] = '### Changes';
$lines[] = '';
if ($previous !== '') {
    $lines[] = 'Commits since ' . $previous . ':';
    $lines[] = '';
    $log = trim((string) shell_exec('git -C ' . escapeshellarg($root) . ' log --no-merges --format="- %s (%h)" '
        . escapeshellarg($previous . '..' . $commit)));
    $lines[] = $log === '' ? '- Embedded Engine ' . $engine['release'] . '.' : $log;
} else {
    $lines[] = 'First published release of this line.';
}
$lines[] = '';
echo implode("\n", $lines), "\n";
