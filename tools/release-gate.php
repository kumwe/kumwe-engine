<?php
declare(strict_types=1);

/**
 * Decide whether the checked-out default-branch commit is publishable, and emit the release
 * identity for GitHub Actions. The extension is released only under the version of a
 * published Engine release it embeds (versions are hard-linked), and a tag is never moved.
 *
 *   php tools/release-gate.php            writes version/tag/release/reason to $GITHUB_OUTPUT (or stdout)
 */
require_once __DIR__ . '/engine-source.php';

$root = dirname(__DIR__);
$lock = \Kumwe\EngineSource\readLock($root);
\Kumwe\EngineSource\verifyBundle($root, $lock);
$version = $lock['version'];
$tag = 'v' . $version;

function remote_tag_commit(string $root, string $tag): ?string
{
    $pipes = [];
    $process = proc_open(['git', '-C', $root, 'ls-remote', '--tags', 'origin', 'refs/tags/' . $tag, 'refs/tags/' . $tag . '^{}'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Cannot query published tags.'); }
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    if (proc_close($process) !== 0) { throw new RuntimeException('Cannot query published tags.'); }
    $plain = null;
    $peeled = null;
    foreach (explode("\n", trim((string) $output)) as $line) {
        if (!preg_match('/^([a-f0-9]{40})\t(\S+)$/', $line, $match)) { continue; }
        if (str_ends_with($match[2], '^{}')) { $peeled = $match[1]; } else { $plain = $match[1]; }
    }
    return $peeled ?? $plain;
}

$head = trim((string) shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse HEAD'));
if (!preg_match('/^[a-f0-9]{40}$/D', $head)) { throw new RuntimeException('Cannot resolve the checked-out commit.'); }
$release = false;
if ($lock['release'] === null) {
    $reason = 'The embedded Engine is unreleased source at ' . $lock['commit'] . '; the binding is published only once it embeds a published Engine release (the engine-sync workflow does this automatically).';
} else {
    $published = remote_tag_commit($root, $tag);
    if ($published === null) {
        $release = true;
        $reason = $tag . ' is not published yet; this commit is released once every quality lane passes.';
    } elseif ($published === $head) {
        $reason = $tag . ' already identifies this exact commit; nothing new to release.';
    } else {
        $reason = $tag . ' already identifies ' . $published . '. Versions are hard-linked to the Engine, so a binding-only change ships with the next Engine release (run the Engine "Native quality" workflow on main to cut one).';
    }
}
$lines = 'version=' . $version . "\n" . 'tag=' . $tag . "\n" . 'release=' . ($release ? 'true' : 'false') . "\n"
    . 'engine_release=' . ($lock['release'] ?? '') . "\n" . 'engine_commit=' . $lock['commit'] . "\n";
$output = getenv('GITHUB_OUTPUT');
if ($output !== false && $output !== '') {
    if (file_put_contents($output, $lines, FILE_APPEND) === false) { throw new RuntimeException('Cannot write workflow outputs.'); }
} else {
    echo $lines;
}
echo ($release ? '::notice::' : '::notice::') . $reason . "\n";
