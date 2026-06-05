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
        private readonly int $timeout = 120,
    ) {
    }

    public function remoteBranchExists(string $branch): bool
    {
        $process = $this->process(['ls-remote', '--heads', 'origin', $branch]);
        $process->run();

        return '' !== trim($process->getOutput());
    }

    public function localBranchExists(string $branch): bool
    {
        $process = $this->process(['rev-parse', '--verify', 'refs/heads/'.$branch]);
        $process->run();

        return $process->isSuccessful();
    }

    public function hasChanges(): bool
    {
        $process = $this->process(['status', '--porcelain']);
        $process->run();

        return '' !== trim($process->getOutput());
    }

    public function changedFiles(): array
    {
        $process = $this->process(['status', '--porcelain']);
        $process->run();

        $files = [];
        foreach (preg_split('/\r?\n/', trim($process->getOutput())) ?: [] as $line) {
            if ('' === $line) {
                continue;
            }
            // Porcelain lines are "XY <path>"; drop the 2-char status + space.
            $files[] = trim(substr($line, 3));
        }

        return array_values(array_filter($files));
    }

    public function createBranch(string $branch, string $base): void
    {
        $this->mustRun(['fetch', 'origin', $base]);
        $this->mustRun(['checkout', $base]);
        $this->mustRun(['pull', 'origin', $base]);
        $this->mustRun(['checkout', '-b', $branch]);
    }

    public function checkoutBranch(string $branch): void
    {
        $this->mustRun(['fetch', 'origin', $branch]);
        // -B creates or resets the local branch to the freshly fetched remote tip,
        // so iterating always starts from what the previous run actually pushed.
        $this->mustRun(['checkout', '-B', $branch, 'origin/'.$branch]);
    }

    /**
     * Best-effort deletion of a local branch: checks out the fallback branch first,
     * then force-deletes the branch. Never throws (used during failure cleanup).
     */
    public function deleteLocalBranch(string $branch, string $fallbackBranch): void
    {
        $this->process(['checkout', $fallbackBranch])->run();
        $this->process(['branch', '-D', $branch])->run();
    }

    /**
     * Best-effort deletion of a remote branch. Never throws (used during failure cleanup).
     */
    public function deleteRemoteBranch(string $branch): void
    {
        $this->process(['push', 'origin', '--delete', $branch])->run();
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
        $this->mustRun(['add', '-A']);

        foreach ($excludePaths as $path) {
            $absolute = $this->projectDir.'/'.$path;
            if (is_file($absolute) || is_dir($absolute)) {
                $this->mustRun(['reset', 'HEAD', '--', $path]);
            }
        }

        $staged = $this->process(['diff', '--cached', '--name-only']);
        $staged->run();
        if ('' === trim($staged->getOutput())) {
            throw new \RuntimeException('No staged changes to commit');
        }

        $this->mustRun(['commit', '-m', $message]);
        $this->mustRun(['push', 'origin', $branch]);
    }

    /**
     * @param list<string> $args
     */
    private function mustRun(array $args): void
    {
        $process = $this->process($args);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(\sprintf('git %s failed: %s', implode(' ', $args), $process->getErrorOutput() ?: $process->getOutput()));
        }
    }

    /**
     * @param list<string> $args
     */
    private function process(array $args): Process
    {
        $command = array_merge(['git', '-c', \sprintf('safe.directory=%s', $this->projectDir)], $args);

        $process = new Process($command, $this->projectDir);
        $process->setTimeout((float) $this->timeout);

        return $process;
    }
}
