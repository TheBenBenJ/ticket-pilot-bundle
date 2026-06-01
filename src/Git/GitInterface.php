<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Git;

/**
 * Git operations the pipeline needs, scoped to the project working directory.
 *
 * Abstracted so the orchestration can be unit-tested without a real repository
 * and so a project may provide its own implementation.
 */
interface GitInterface
{
    public function remoteBranchExists(string $branch): bool;

    public function localBranchExists(string $branch): bool;

    public function hasChanges(): bool;

    public function createBranch(string $branch, string $base): void;

    /**
     * Stages every change, un-stages the excluded paths, commits and pushes.
     *
     * @param list<string> $excludePaths Paths the commit must never include
     *
     * @throws \RuntimeException when there is nothing to commit
     */
    public function commitAndPush(string $branch, string $message, array $excludePaths = []): void;

    /**
     * Best-effort deletion of a local branch (used during failure cleanup; never throws).
     */
    public function deleteLocalBranch(string $branch, string $fallbackBranch): void;

    /**
     * Best-effort deletion of a remote branch (used during failure cleanup; never throws).
     */
    public function deleteRemoteBranch(string $branch): void;
}
