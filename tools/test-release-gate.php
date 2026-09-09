#!/usr/bin/env php
<?php
declare(strict_types=1);

namespace Kumwe\ReleaseGate\Test;

use Kumwe\ReleaseGate\Repository;
use RuntimeException;
use Throwable;

use function Kumwe\ReleaseGate\decide;
use function Kumwe\ReleaseGate\sort_tags;

require_once __DIR__ . '/release-gate.php';

/** Every default-branch release decision against recorded repository answers, plus the real released-source digest. */
final class Recorded extends Repository
{
    /**
     * @param array<string, array> $locks       commit => Engine lock
     * @param array<string, string> $tags       tag => commit
     * @param list<string> $published           tags with a published release
     * @param array<string, string> $digests    commit => released-source identity
     */
    public function __construct(
        private readonly string $headCommit,
        private readonly array $locks,
        private readonly array $tags,
        private readonly array $published,
        private readonly array $digests,
    ) {
        parent::__construct('/nonexistent');
    }

    public function head(): string { return $this->headCommit; }
    public function tagCommit(string $tag): ?string { return $this->tags[$tag] ?? null; }
    public function versionTags(): array { return sort_tags(array_keys($this->tags)); }
    public function publishedTags(): array { return sort_tags($this->published); }
    public function lockAt(string $commit): array
    {
        return $this->locks[$commit] ?? throw new RuntimeException('No lock recorded for ' . $commit);
    }
    public function exportDigest(string $commit): string
    {
        return $this->digests[$commit] ?? throw new RuntimeException('No digest recorded for ' . $commit);
    }
}

$passed = 0;
$check = static function (string $name, callable $case) use (&$passed): void {
    try {
        $case();
    } catch (Throwable $error) {
        fwrite(STDERR, 'FAIL ' . $name . ': ' . $error->getMessage() . "\n");
        exit(1);
    }
    ++$passed;
    echo 'ok ' . $passed . ' ' . $name . "\n";
};
$expect = static function (string $what, mixed $actual, mixed $expected): void {
    if ($actual !== $expected) {
        throw new RuntimeException($what . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
};
$lock = static fn (string $version, ?string $release, string $commit): array => ['version' => $version, 'release' => $release, 'commit' => $commit];
$head = str_repeat('a', 40);
$older = str_repeat('b', 40);
$engineCommit = str_repeat('e', 40);

$check('tags sort numerically and ignore other refs', static function () use ($expect): void {
    $expect('sorted', sort_tags(['v1.0.10', 'v1.0.9', 'candidate', 'v1.0.0', 'v0.9.9', 'v01.0.0']), ['v0.9.9', 'v1.0.0', 'v1.0.9', 'v1.0.10']);
});

$check('an unreleased embedded Engine publishes nothing', static function () use ($expect, $lock, $head, $engineCommit): void {
    $decision = decide(new Recorded($head, [$head => $lock('1.0.0', null, $engineCommit)], [], [], []));
    $expect('release', $decision['release'], false);
    $expect('engine_bump', $decision['engine_bump'], false);
    $expect('engine_release', $decision['engine_release'], '');
    $expect('reason', str_contains($decision['reason'], 'unreleased source'), true);
});

$check('an unreleased declared version is released from the tested commit', static function () use ($expect, $lock, $head, $engineCommit): void {
    $decision = decide(new Recorded($head, [$head => $lock('1.0.0', 'v1.0.0', $engineCommit)], [], [], []));
    $expect('release', $decision['release'], true);
    $expect('sha', $decision['sha'], $head);
    $expect('tag', $decision['tag'], 'v1.0.0');
    $expect('followup', $decision['followup'], false);
    $expect('engine_release', $decision['engine_release'], 'v1.0.0');
});

$check('a tag on this commit with a published release publishes nothing', static function () use ($expect, $lock, $head, $engineCommit): void {
    $decision = decide(new Recorded($head, [$head => $lock('1.0.0', 'v1.0.0', $engineCommit)], ['v1.0.0' => $head], ['v1.0.0'], []));
    $expect('release', $decision['release'], false);
    $expect('engine_bump', $decision['engine_bump'], false);
});

$check('a tag on this commit whose release is missing is completed', static function () use ($expect, $lock, $head, $engineCommit): void {
    $decision = decide(new Recorded($head, [$head => $lock('1.0.0', 'v1.0.0', $engineCommit)], ['v1.0.0' => $head], [], []));
    $expect('release', $decision['release'], true);
    $expect('sha', $decision['sha'], $head);
    $expect('followup', $decision['followup'], false);
});

$check('the lowest tag without a published release is completed from its own commit first', static function () use ($expect, $lock, $head, $older, $engineCommit): void {
    $locks = [$head => $lock('1.0.2', 'v1.0.2', $engineCommit), $older => $lock('1.0.0', 'v1.0.0', str_repeat('d', 40))];
    $decision = decide(new Recorded($head, $locks, ['v1.0.0' => $older, 'v1.0.1' => $older], ['v1.0.1'], []));
    $expect('release', $decision['release'], true);
    $expect('sha', $decision['sha'], $older);
    $expect('version', $decision['version'], '1.0.0');
    $expect('tag', $decision['tag'], 'v1.0.0');
    $expect('followup', $decision['followup'], true);
    $expect('engine_release', $decision['engine_release'], 'v1.0.0');
    $expect('engine_commit', $decision['engine_commit'], str_repeat('d', 40));
    $expect('engine_bump', $decision['engine_bump'], false);
});

$check('a tag whose commit declares another version is refused', static function () use ($expect, $lock, $head, $older, $engineCommit): void {
    $locks = [$head => $lock('1.0.1', 'v1.0.1', $engineCommit), $older => $lock('1.0.1', 'v1.0.1', $engineCommit)];
    try {
        decide(new Recorded($head, $locks, ['v1.0.0' => $older], [], []));
    } catch (RuntimeException $error) {
        $expect('message', str_contains($error->getMessage(), 'declares version 1.0.1'), true);
        return;
    }
    throw new RuntimeException('expected a refusal');
});

$check('a published version with untouched released source publishes nothing', static function () use ($expect, $lock, $head, $older, $engineCommit): void {
    $locks = [$head => $lock('1.0.0', 'v1.0.0', $engineCommit), $older => $lock('1.0.0', 'v1.0.0', $engineCommit)];
    $decision = decide(new Recorded($head, $locks, ['v1.0.0' => $older], ['v1.0.0'], [$head => 'same', $older => 'same']));
    $expect('release', $decision['release'], false);
    $expect('engine_bump', $decision['engine_bump'], false);
    $expect('reason', str_contains($decision['reason'], 'leaves released source untouched'), true);
});

$check('a binding-only change to a published version requests the next Engine patch release', static function () use ($expect, $lock, $head, $older, $engineCommit): void {
    $locks = [$head => $lock('1.0.0', 'v1.0.0', $engineCommit), $older => $lock('1.0.0', 'v1.0.0', $engineCommit)];
    $decision = decide(new Recorded($head, $locks, ['v1.0.0' => $older], ['v1.0.0'], [$head => 'changed', $older => 'same']));
    $expect('release', $decision['release'], false);
    $expect('engine_bump', $decision['engine_bump'], true);
    $expect('engine_release', $decision['engine_release'], 'v1.0.0');
    $expect('reason', str_contains($decision['reason'], 'requests the next Engine patch release'), true);
});

$check('the released-source digest ignores export-ignored files and follows released content', static function () use ($expect): void {
    $work = sys_get_temp_dir() . '/kumwe-gate-' . bin2hex(random_bytes(6));
    mkdir($work, 0700);
    $git = static function (string ...$arguments) use ($work): string {
        $pipes = [];
        $process = proc_open(array_merge(['git', '-C', $work, '-c', 'user.name=Lemuel', '-c', 'user.email=lemuel@vdm.to'], $arguments),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) { throw new RuntimeException('git ' . implode(' ', $arguments) . ': ' . $stderr); }
        return trim((string) $stdout);
    };
    try {
        $git('init', '--quiet', '--initial-branch=main');
        mkdir($work . '/.github');
        file_put_contents($work . '/.gitattributes', "/.github export-ignore\n/.gitattributes export-ignore\n");
        file_put_contents($work . '/.github/ci.yml', "lane: one\n");
        file_put_contents($work . '/source.c', "int main(void) { return 0; }\n");
        $git('add', '--all');
        $git('commit', '--quiet', '--message', 'Seed');
        $first = $git('rev-parse', 'HEAD');
        file_put_contents($work . '/.github/ci.yml', "lane: two\n");
        $git('commit', '--quiet', '--all', '--message', 'Ignored change');
        $second = $git('rev-parse', 'HEAD');
        file_put_contents($work . '/source.c', "int main(void) { return 1; }\n");
        $git('commit', '--quiet', '--all', '--message', 'Released change');
        $third = $git('rev-parse', 'HEAD');
        $repository = new Repository($work);
        $expect('ignored change keeps the identity', $repository->exportDigest($second), $repository->exportDigest($first));
        $expect('released change alters the identity', $repository->exportDigest($third) !== $repository->exportDigest($first), true);
        $expect('head', $repository->head(), $third);
    } finally {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($work, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
        rmdir($work);
    }
});

echo $passed . " release gate decision cases passed.\n";
