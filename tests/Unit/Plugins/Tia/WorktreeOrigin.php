<?php

declare(strict_types=1);

use Pest\Plugins\Tia\Baselines\GitHubRemote;
use Pest\Plugins\Tia\Storage;
use Pest\Plugins\Tia\WatchPatterns;
use Symfony\Component\Process\Process;

function worktreeOriginGit(string $cwd, string ...$args): void
{
    $process = new Process(['git', ...$args], $cwd);
    $process->setTimeout(10.0);
    $process->mustRun();
}

/**
 * @return array{0: string, 1: string}
 */
function worktreeOriginRepository(string $origin): array
{
    $clone = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pest_worktree_origin_'.uniqid();
    $worktree = $clone.'-wt';

    mkdir($clone, 0777, true);

    worktreeOriginGit($clone, 'init', '-q');
    worktreeOriginGit($clone, 'config', 'user.email', 'pest@example.com');
    worktreeOriginGit($clone, 'config', 'user.name', 'Pest');
    worktreeOriginGit($clone, 'remote', 'add', 'origin', $origin);

    file_put_contents($clone.DIRECTORY_SEPARATOR.'README.md', 'pest');

    worktreeOriginGit($clone, 'add', '-A');
    worktreeOriginGit($clone, 'commit', '-q', '-m', 'init');
    worktreeOriginGit($clone, 'worktree', 'add', '-q', $worktree);

    return [$clone, $worktree];
}

function worktreeOriginRemove(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $file) {
        @chmod($file->getPathname(), 0777);
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }

    @rmdir($path);
}

function worktreeOriginKeyHash(string $projectRoot): string
{
    preg_match('/([a-f0-9]{16})$/', basename(Storage::tempDir($projectRoot)), $match);

    return $match[1] ?? '';
}

afterEach(function (): void {
    Storage::useDirectory(null);
});

it('derives the storage key from the origin remote inside a linked git worktree', function (): void {
    [$clone, $worktree] = worktreeOriginRepository('git@github.com:foo/bar.git');

    try {
        $originHash = substr(hash('sha256', 'github.com/foo/bar'), 0, 16);

        expect(worktreeOriginKeyHash($clone))->toBe($originHash)
            ->and(worktreeOriginKeyHash($worktree))->toBe($originHash);
    } finally {
        worktreeOriginRemove($worktree);
        worktreeOriginRemove($clone);
    }
})->skipOnWindows();

it('detects the github repository inside a linked git worktree', function (): void {
    [$clone, $worktree] = worktreeOriginRepository('git@github.com:foo/bar.git');

    try {
        $remote = new GitHubRemote(new WatchPatterns);

        expect($remote->detect($clone))->toBe('foo/bar')
            ->and($remote->detect($worktree))->toBe('foo/bar');
    } finally {
        worktreeOriginRemove($worktree);
        worktreeOriginRemove($clone);
    }
})->skipOnWindows();
