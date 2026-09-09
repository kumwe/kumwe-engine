#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/embed-engine.php';

use function Kumwe\EngineSource\entryHashes;
use function Kumwe\EngineSource\makeDirectory;
use function Kumwe\EngineSource\readFile;
use function Kumwe\EngineSource\removeTree;
use function Kumwe\EngineSource\sourceEntries;
use function Kumwe\EngineSource\verifyBundle;
use function Kumwe\EngineSource\writeFile;
use function Kumwe\ReleaseSource\encode;
use function Kumwe\ReleaseSource\process;
use function Kumwe\ReleaseSource\run;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$checks = 0;
$workspace = sys_get_temp_dir() . '/kumwe-source-replacement-' . bin2hex(random_bytes(12));
$engine = $workspace . '/engine';
$binding = $workspace . '/binding';
makeDirectory($engine);
makeDirectory($binding . '/tools');
try {
    foreach (['embed-engine.php', 'engine-source.php', 'release-source.php'] as $name) {
        writeFile($binding . '/tools/' . $name, readFile(__DIR__ . '/' . $name));
    }
    $git = static fn(string ...$arguments): string => trim(run($engine, 'git', ...$arguments));
    $git('init', '-q');
    $git('config', 'user.name', 'Source fixture');
    $git('config', 'user.email', 'source-fixture@example.invalid');
    $git('config', 'commit.gpgsign', 'false');
    $commit = static function (string $message) use ($git): string {
        $git('add', '.');
        $git('commit', '-qm', $message);
        return $git('rev-parse', 'HEAD');
    };
    $embed = static function (string $sha, ?string $option = null, bool $succeeds = true)
        use ($binding, $engine, &$checks): void {
        $command = [PHP_BINARY, $binding . '/tools/embed-engine.php', $engine, $sha];
        if ($option !== null) {
            $command[] = $option;
        }
        $result = process($binding, $command);
        check(($result['returncode'] === 0) === $succeeds,
            'Unexpected embedding result: ' . $result['stdout'] . $result['stderr']);
        if ($succeeds) {
            $lock = json_decode(readFile($binding . '/resources/engine-lock.json'), true, 512, JSON_THROW_ON_ERROR);
            verifyBundle($binding, $lock);
        }
        ++$checks;
    };
    writeFile($engine . '/old.txt', "old source\n");
    $first = $commit('first source');
    $embed($first);
    $bundle = $binding . '/vendor/engine';
    $lockPath = $binding . '/resources/engine-lock.json';
    $headerPath = $binding . '/php_kumwe_engine_build.h';
    $originalLock = readFile($lockPath);
    unlink($engine . '/old.txt');
    makeDirectory($engine . '/src');
    writeFile($engine . '/src/engine.c', "new Engine source\n");
    writeFile($engine . '/CMakeLists.txt', "reviewed upstream build recipe\n");
    makeDirectory($engine . '/third_party/pcre2');
    $dependency = "Unmodified source dependency and license bytes.\n";
    writeFile($engine . '/third_party/pcre2/LICENCE', $dependency);
    $second = $commit('complete new offline source closure');
    $embed($second, null, false);
    $embed($second, '--same-source', false);
    writeFile($bundle . '/old.txt', "unreviewed edit\n");
    $embed($second, '--replace-source', false);
    check(readFile($lockPath) === $originalLock, 'A refusal changed the old source lock.');
    writeFile($bundle . '/old.txt', "old source\n");
    writeFile($bundle . '/unexpected.txt', "untracked work\n");
    $embed($second, '--replace-source', false);
    unlink($bundle . '/unexpected.txt');
    check(symlink('old.txt', $bundle . '/link'), 'Cannot create symlink fixture.');
    $embed($second, '--replace-source', false);
    unlink($bundle . '/link');
    $embed($second, '--replace-source');
    $lock = json_decode(readFile($lockPath), true, 512, JSON_THROW_ON_ERROR);
    check($lock['commit'] === $second && $lock['release_verified'] === false, 'Replacement identity drifted.');
    check(!file_exists($bundle . '/old.txt'), 'Replacement retained obsolete source.');
    check(readFile($bundle . '/third_party/pcre2/LICENCE') === $dependency, 'Dependency bytes changed.');
    check($lock['files']['third_party/pcre2/LICENCE'] === hash('sha256', $dependency), 'Dependency digest changed.');
    $git('commit', '--allow-empty', '-qm', 'reachable same-tree identity');
    $same = $git('rev-parse', 'HEAD');
    $embed($same, '--same-source');
    check(symlink('src/engine.c', $engine . '/link'), 'Cannot create source symlink fixture.');
    $linked = $commit('invalid source shape');
    $embed($linked, '--replace-source', false);
    check(json_decode(readFile($lockPath), true, 512, JSON_THROW_ON_ERROR)['commit'] === $same,
        'Invalid source changed the current identity.');
    unlink($engine . '/link');

    // A reviewed snapshot retains upstream provenance while changing packaging only.
    makeDirectory($engine . '/tools');
    writeFile($engine . '/tools/obsolete.py', "retired upstream tool\n");
    $snapshotCommit = $commit('upstream packaging fixture');
    $embed($snapshotCommit, '--replace-source', false);
    $archive = run($engine, 'git', 'archive', '--format=tar', $snapshotCommit);
    $upstream = entryHashes(sourceEntries($archive));
    writeFile($bundle . '/CMakeLists.txt', "reviewed native build recipe\n");
    makeDirectory($bundle . '/tools');
    writeFile($bundle . '/tools/generate.php', "<?php echo \"native generation\";\n");
    $lock = json_decode(readFile($lockPath), true, 512, JSON_THROW_ON_ERROR);
    $oldCommit = $lock['commit'];
    $oldArchive = $lock['archive_sha256'];
    $lock['commit'] = $snapshotCommit;
    $lock['archive_sha256'] = hash('sha256', $archive);
    $lock['files'] = \Kumwe\EngineSource\treeFiles($bundle);
    $lock['snapshot'] = ['profile' => 'php-native-tooling/v1', 'upstream_files' => $upstream];
    writeFile($lockPath, encode($lock));
    writeFile($headerPath, str_replace([$oldCommit, $oldArchive],
        [$lock['commit'], $lock['archive_sha256']], readFile($headerPath)));
    verifyBundle($binding, $lock);
    $embed($snapshotCommit, '--same-source');
    check(!file_exists($bundle . '/tools/obsolete.py'), 'Snapshot refresh restored excluded tooling.');

    writeFile($engine . '/src/engine.c', "updated Engine source\n");
    $updated = $commit('new native implementation');
    $embed($updated, '--replace-source');
    check(readFile($bundle . '/src/engine.c') === "updated Engine source\n", 'Native update was not carried forward.');
    check(readFile($bundle . '/CMakeLists.txt') === "reviewed native build recipe\n", 'Reviewed build overlay was lost.');
    check(is_file($bundle . '/tools/generate.php'), 'Native generator addition was lost.');
    $snapshotLock = readFile($lockPath);
    writeFile($engine . '/CMakeLists.txt', "changed upstream build recipe\n");
    $changedOverlay = $commit('changed overlay input');
    $embed($changedOverlay, '--replace-source', false);
    check(readFile($lockPath) === $snapshotLock, 'Overlay refusal changed reviewed identity.');
    writeFile($engine . '/CMakeLists.txt', "reviewed upstream build recipe\n");
    writeFile($engine . '/tools/new.py', "new unsupported tool\n");
    $newTool = $commit('new unsupported upstream tool');
    $embed($newTool, '--replace-source', false);
    unlink($engine . '/tools/new.py');
    writeFile($engine . '/tools/generate.php', "<?php echo \"upstream collision\";\n");
    $collision = $commit('snapshot addition collision');
    $embed($collision, '--replace-source', false);
    unlink($engine . '/tools/generate.php');
    writeFile($engine . '/tools/check.sh', "#!/bin/sh\npython3 generator\n");
    $command = $commit('unsupported interpreter command');
    $embed($command, '--replace-source', false);
    check(readFile($lockPath) === $snapshotLock, 'Admission failure changed the snapshot lock.');
    echo $checks . " isolated Engine source admission/replacement cases passed; dependency bytes and reviewed overlays preserved.\n";
} finally {
    removeTree($workspace);
}
