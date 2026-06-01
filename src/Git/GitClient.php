<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Git;

use Symfony\Component\Process\Process;

/**
 * Thin wrapper around the local git binary, scoped to the project directory.
 *
 * Every command is prefixed with `-c safe.directory=<dir>` to avoid the
 * "dubious ownership" failure that occurs when running inside CI containers.
 */
final class GitClient implements GitInterface
{
    public function __construct(
        private readonly string $projectDir,
    ) {
    }

    public function remoteBranchExists(string $branch): bool
    {
        $process = $this->process(['ls-remote', '--heads', 'origin', $branch], 30);
        $process->run();

        return '' !== trim($process->getOutput());
    }

    public function localBranchExists(string $branch): bool
    {
        $process = $this->process(['rev-parse', '--verify', 'refs/heads/'.$branch], 10);
        $process->run();

        return $process->isSuccessful();
    }

    public function hasChanges(): bool
    {
        $process = $this->process(['status', '--porcelain'], 10);
        $process->run();

        return '' !== trim($process->getOutput());
    }

    public function createBranch(string $branch, string $base): void
    {
        $this->mustRun(['fetch', 'origin', $base], 60);
        $this->mustRun(['checkout', $base], 30);
        $this->mustRun(['pull', 'origin', $base], 60);
        $this->mustRun(['checkout', '-b', $branch], 30);
    }

    /**
     * Best-effort deletion of a local branch: checks out the fallback branch first,
     * then force-deletes the branch. Never throws (used during failure cleanup).
     */
    public function deleteLocalBranch(string $branch, string $fallbackBranch): void
    {
        $this->process(['checkout', $fallbackBranch], 30)->run();
        $this->process(['branch', '-D', $branch], 30)->run();
    }

    /**
     * Best-effort deletion of a remote branch. Never throws (used during failure cleanup).
     */
    public function deleteRemoteBranch(string $branch): void
    {
        $this->process(['push', 'origin', '--delete', $branch], 60)->run();
    }

    /**
     * Stages every change, un-stages the excluded paths, commits and pushes.
     *
     * @param list<string> $excludePaths Paths the agent must never commit (infra, the bundle itself, secrets)
     *
     * @throws \RuntimeException when there is nothing to commit
     */
    public function commitAndPush(string $branch, string $message, array $excludePaths = []): void
    {
        $this->mustRun(['add', '-A'], 30);

        foreach ($excludePaths as $path) {
            $absolute = $this->projectDir.'/'.$path;
            if (is_file($absolute) || is_dir($absolute)) {
                $this->mustRun(['reset', 'HEAD', '--', $path], 10);
            }
        }

        $staged = $this->process(['diff', '--cached', '--name-only'], 10);
        $staged->run();
        if ('' === trim($staged->getOutput())) {
            throw new \RuntimeException('No staged changes to commit');
        }

        $this->mustRun(['commit', '-m', $message], 30);
        $this->mustRun(['push', 'origin', $branch], 120);
    }

    /**
     * @param list<string> $args
     */
    private function mustRun(array $args, int $timeout): void
    {
        $process = $this->process($args, $timeout);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(\sprintf('git %s failed: %s', implode(' ', $args), $process->getErrorOutput() ?: $process->getOutput()));
        }
    }

    /**
     * @param list<string> $args
     */
    private function process(array $args, int $timeout): Process
    {
        $command = array_merge(['git', '-c', \sprintf('safe.directory=%s', $this->projectDir)], $args);

        $process = new Process($command, $this->projectDir);
        $process->setTimeout($timeout);

        return $process;
    }
}
