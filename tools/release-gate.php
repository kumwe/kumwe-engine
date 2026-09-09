<?php
declare(strict_types=1);

/**
 * Decide whether the checked-out default-branch commit is publishable, and emit the release
 * identity for GitHub Actions. The extension is released only under the version of a
 * published Engine release it embeds (versions are hard-linked), a tag is never moved, and a
 * tag that already identifies this commit without its GitHub release is completed.
 *
 *   php tools/release-gate.php            writes version/tag/release/... to $GITHUB_OUTPUT (or stdout)
 */
require_once __DIR__ . '/engine-source.php';

$root = dirname(__DIR__);
$lock = \Kumwe\EngineSource\readLock($root);
\Kumwe\EngineSource\verifyBundle($root, $lock);
$version = $lock['version'];
$tag = 'v' . $version;

function command_output(array $command): ?string
{
    $pipes = [];
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (!is_resource($process)) { return null; }
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    return proc_close($process) === 0 && is_string($output) ? $output : null;
}

function remote_tag_commit(string $root, string $tag): ?string
{
    $output = command_output(['git', '-C', $root, 'ls-remote', '--tags', 'origin', 'refs/tags/' . $tag, 'refs/tags/' . $tag . '^{}']);
    if ($output === null) { throw new RuntimeException('Cannot query published tags.'); }
    $plain = null;
    $peeled = null;
    foreach (explode("\n", trim($output)) as $line) {
        if (!preg_match('/^([a-f0-9]{40})\t(\S+)$/', $line, $match)) { continue; }
        if (str_ends_with($match[2], '^{}')) { $peeled = $match[1]; } else { $plain = $match[1]; }
    }
    return $peeled ?? $plain;
}

/** Without gh or a token this reports "no release", and the idempotent publisher then verifies or completes it. */
function release_exists(string $tag): bool
{
    return command_output(['gh', 'release', 'view', $tag, '--json', 'id']) !== null;
}

$head = trim((string) command_output(['git', '-C', $root, 'rev-parse', 'HEAD']));
if (!preg_match('/^[a-f0-9]{40}$/D', $head)) { throw new RuntimeException('Cannot resolve the checked-out commit.'); }
$release = false;
if ($lock['release'] === null) {
    $reason = 'The embedded Engine is unreleased source at ' . $lock['commit'] . '; the binding is published only once it embeds a published Engine release (the Engine sync workflow does this automatically).';
} else {
    $published = remote_tag_commit($root, $tag);
    if ($published === null) {
        $release = true;
        $reason = $tag . ' is not published yet; this commit is released once every quality lane passes.';
    } elseif ($published === $head) {
        if (release_exists($tag)) {
            $reason = $tag . ' already identifies this exact commit and its release exists; nothing new to release.';
        } else {
            $release = true;
            $reason = $tag . ' already identifies this exact commit but its GitHub release is missing; the release job completes it.';
        }
    } else {
        $reason = $tag . ' already identifies ' . $published . '. Versions are hard-linked to the Engine, so a binding-only change ships with the next Engine release: start the Engine "Native quality" workflow on its default branch with the "bump" input enabled, and the Engine sync embeds the new release here.';
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
echo '::notice::' . $reason . "\n";
