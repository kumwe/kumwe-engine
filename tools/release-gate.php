#!/usr/bin/env php
<?php
declare(strict_types=1);

namespace Kumwe\ReleaseGate;

use RuntimeException;
use Throwable;

require_once __DIR__ . '/engine-source.php';
require_once __DIR__ . '/release-source.php';

use function Kumwe\EngineSource\readLock;
use function Kumwe\EngineSource\verifyBundle;
use function Kumwe\ReleaseSource\archive_files;
use function Kumwe\ReleaseSource\process;
use function Kumwe\ReleaseSource\run;
use const Kumwe\ReleaseSource\ARCHIVE_PREFIX;

/**
 * Decide what a default-branch run releases and emit that identity for GitHub Actions. Nothing here is
 * decided by a person: the extension version is hard-linked to the embedded Engine release, tags are
 * never moved or deleted, a tag whose GitHub release is missing is completed from the commit it
 * identifies, and a binding-only change requests the next Engine patch release itself.
 *
 *   php tools/release-gate.php    writes version/tag/sha/release/followup/engine_bump/engine_release/
 *                                 engine_commit to $GITHUB_OUTPUT (or stdout) and prints the reason
 */
const REPOSITORY = 'kumwe/kumwe-engine';
const TAG = '/^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/D';

/** Order vMAJOR.MINOR.PATCH tags numerically, lowest first. */
function sort_tags(array $tags): array
{
    $tags = array_values(array_filter($tags, static fn ($tag): bool => is_string($tag) && preg_match(TAG, $tag) === 1));
    usort($tags, static fn (string $a, string $b): int => version_compare(substr($a, 1), substr($b, 1)));
    return $tags;
}

/** Everything the decision asks of the repository; tests replace it with recorded answers. */
class Repository
{
    public function __construct(public readonly string $root, public readonly string $repository = REPOSITORY)
    {
    }

    public function head(): string
    {
        $head = trim(run($this->root, 'git', 'rev-parse', 'HEAD'));
        if (!preg_match('/^[a-f0-9]{40}$/D', $head)) { throw new RuntimeException('Cannot resolve the checked-out commit.'); }
        return $head;
    }

    /** The commit a tag identifies on origin (annotated tags are peeled), or null when it does not exist. */
    public function tagCommit(string $tag): ?string
    {
        $output = run($this->root, 'git', 'ls-remote', '--tags', 'origin', 'refs/tags/' . $tag, 'refs/tags/' . $tag . '^{}');
        $plain = null;
        $peeled = null;
        foreach (explode("\n", trim($output)) as $line) {
            if (!preg_match('/^([a-f0-9]{40})\t(\S+)$/', $line, $match)) { continue; }
            if (str_ends_with($match[2], '^{}')) { $peeled = $match[1]; } else { $plain = $match[1]; }
        }
        return $peeled ?? $plain;
    }

    /** Every vMAJOR.MINOR.PATCH tag on origin, lowest first. */
    public function versionTags(): array
    {
        $tags = [];
        foreach (explode("\n", trim(run($this->root, 'git', 'ls-remote', '--tags', '--refs', 'origin', 'refs/tags/v*'))) as $line) {
            if (preg_match('/^[a-f0-9]{40}\trefs\/tags\/(\S+)$/', $line, $match)) { $tags[] = $match[1]; }
        }
        return sort_tags($tags);
    }

    /** Every tag with a published (non-draft) GitHub release. Requires gh with a token. */
    public function publishedTags(): array
    {
        $releases = json_decode(run($this->root, 'gh', 'release', 'list', '--repo', $this->repository, '--limit', '1000',
            '--json', 'tagName,isDraft'), true, 512, JSON_THROW_ON_ERROR);
        $tags = [];
        foreach ($releases as $release) {
            if (($release['isDraft'] ?? true) === false && is_string($release['tagName'] ?? null)) { $tags[] = $release['tagName']; }
        }
        return sort_tags($tags);
    }

    /** The Engine lock a commit carries: the extension version, the embedded release and its commit. */
    public function lockAt(string $commit): array
    {
        $this->ensure($commit);
        $lock = json_decode(run($this->root, 'git', 'show', $commit . ':resources/engine-lock.json'), true, 512, JSON_THROW_ON_ERROR);
        if (!is_string($lock['version'] ?? null) || !array_key_exists('release', $lock) || !is_string($lock['commit'] ?? null)) {
            throw new RuntimeException('Commit ' . $commit . ' carries no usable Engine lock.');
        }
        return $lock;
    }

    /**
     * Identity of the released source of a commit: every exported path with its mode and content digest.
     * Commits that differ only in export-ignored files (workflow, benchmark oracle) share it.
     */
    public function exportDigest(string $commit): string
    {
        $this->ensure($commit);
        $tar = run($this->root, 'git', 'archive', '--format=tar', '--prefix=' . ARCHIVE_PREFIX, $commit);
        $gzip = process($this->root, ['gzip', '-n'], $tar);
        if ($gzip['returncode'] !== 0) { throw new RuntimeException('Cannot compress the exported source of ' . $commit . '.'); }
        $lines = [];
        foreach (archive_files($gzip['stdout'], ARCHIVE_PREFIX) as $path => $entry) {
            $lines[] = $path . "\0" . sprintf('%o', $entry['mode'] & 0777) . "\0" . hash('sha256', $entry['data']);
        }
        sort($lines, SORT_STRING);
        return hash('sha256', implode("\n", $lines));
    }

    private function ensure(string $commit): void
    {
        $present = process($this->root, ['git', 'cat-file', '-e', $commit . '^{commit}']);
        if ($present['returncode'] !== 0) { run($this->root, 'git', 'fetch', '--quiet', 'origin', $commit); }
    }
}

/**
 * @return array{version: string, tag: string, sha: string, release: bool, followup: bool, engine_bump: bool,
 *               engine_release: string, engine_commit: string, reason: string}
 */
function decide(Repository $repository): array
{
    $head = $repository->head();
    $lock = $repository->lockAt($head);
    $version = $lock['version'];
    $tag = 'v' . $version;
    $decision = ['version' => $version, 'tag' => $tag, 'sha' => $head, 'release' => false, 'followup' => false,
        'engine_bump' => false, 'engine_release' => $lock['release'] ?? '', 'engine_commit' => $lock['commit'], 'reason' => ''];

    // A tag without a published release comes first: an earlier run failed after tagging, or left a draft.
    // It is completed from the commit it identifies, with this run's tooling; tags are never moved or deleted.
    $dangling = array_values(array_diff($repository->versionTags(), $repository->publishedTags()))[0] ?? null;
    if ($dangling !== null) {
        $commit = $repository->tagCommit($dangling) ?? throw new RuntimeException('Cannot resolve the commit of ' . $dangling . '.');
        $tagged = $repository->lockAt($commit);
        if ('v' . $tagged['version'] !== $dangling) {
            throw new RuntimeException($dangling . ' identifies ' . $commit . ', which declares version ' . $tagged['version'] . '.');
        }
        $decision['version'] = $tagged['version'];
        $decision['tag'] = $dangling;
        $decision['sha'] = $commit;
        $decision['release'] = true;
        $decision['engine_release'] = $tagged['release'] ?? '';
        $decision['engine_commit'] = $tagged['commit'];
        if ($commit === $head) {
            $decision['reason'] = $dangling . ' identifies this exact commit but its GitHub release is not published; the release job completes it.';
        } else {
            $decision['followup'] = true;
            $decision['reason'] = $dangling . ' identifies ' . $commit . ' but its GitHub release is not published (an earlier run failed after tagging). This run completes that release from the tagged commit; a follow-up run then evaluates this commit.';
        }
        return $decision;
    }
    if ($lock['release'] === null) {
        $decision['reason'] = 'The embedded Engine is unreleased source at ' . $lock['commit'] . '; the binding is published only once it embeds a published Engine release (the Engine sync workflow does this automatically).';
        return $decision;
    }
    $published = $repository->tagCommit($tag);
    if ($published === null) {
        $decision['release'] = true;
        $decision['reason'] = $tag . ' is not published yet; this commit is released once every quality lane passes.';
    } elseif ($published === $head) {
        $decision['reason'] = $tag . ' already identifies this exact commit and its release is published; nothing new to release.';
    } elseif ($repository->exportDigest($head) === $repository->exportDigest($published)) {
        $decision['reason'] = $tag . ' is published from ' . $published . ' and this commit leaves released source untouched; nothing new to release.';
    } else {
        // Versions are hard-linked to the Engine, so a binding-only change needs the next Engine release. The
        // workflow requests it; the Engine sync embeds it here and a green run publishes this change under it.
        $decision['engine_bump'] = true;
        $decision['reason'] = $tag . ' is published from ' . $published . ' and this commit changes released source. Versions are hard-linked to the Engine, so this run requests the next Engine patch release; its sync embeds that release here and publishes this change under the new version.';
    }
    return $decision;
}

function main(): void
{
    $root = dirname(__DIR__);
    verifyBundle($root, readLock($root));
    $decision = decide(new Repository($root, getenv('GITHUB_REPOSITORY') ?: REPOSITORY));
    $lines = '';
    foreach (['version', 'tag', 'sha', 'release', 'followup', 'engine_bump', 'engine_release', 'engine_commit'] as $key) {
        $value = $decision[$key];
        $lines .= $key . '=' . (is_bool($value) ? ($value ? 'true' : 'false') : $value) . "\n";
    }
    $output = getenv('GITHUB_OUTPUT');
    if ($output !== false && $output !== '') {
        if (file_put_contents($output, $lines, FILE_APPEND) === false) { throw new RuntimeException('Cannot write workflow outputs.'); }
    } else {
        echo $lines;
    }
    echo '::notice::' . $decision['reason'] . "\n";
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    try {
        main();
    } catch (Throwable $error) {
        fwrite(STDERR, '::error::Release gate refused: ' . $error->getMessage() . "\n");
        exit(1);
    }
}
