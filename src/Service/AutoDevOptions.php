<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Service;

/**
 * Behavioural options for {@see AutoDevRunner}, grouped to keep the runner's
 * constructor stable as new policies are added.
 */
final readonly class AutoDevOptions
{
    public const ON_QUALITY_FAILURE_ABORT = 'abort';
    public const ON_QUALITY_FAILURE_DRAFT = 'draft';

    /**
     * @param list<string> $excludePaths     Paths the agent's commit must never include
     * @param bool         $draft            Open every merge/pull request as a draft
     * @param string       $onQualityFailure Policy when the quality gate fails: "abort" (no push,
     *                                       no MR) or "draft" (push and open a draft MR flagged
     *                                       with the failing checks)
     * @param bool         $cleanupOnFailure Delete the branch created for the ticket (locally, and
     *                                       remotely if it was pushed) when the run fails
     * @param int          $lockTtl          TTL (seconds) of the per-ticket lock, when a lock factory
     *                                       is wired; should cover the longest expected run
     * @param string       $attachmentsDir   Base dir where ticket attachments are downloaded
     *                                       (per-ticket subfolder); empty disables attachments
     */
    public function __construct(
        public array $excludePaths = [],
        public bool $draft = false,
        public string $onQualityFailure = self::ON_QUALITY_FAILURE_ABORT,
        public bool $cleanupOnFailure = true,
        public int $lockTtl = 3600,
        public string $attachmentsDir = '',
    ) {
    }

    public function abortsOnQualityFailure(): bool
    {
        return self::ON_QUALITY_FAILURE_ABORT === $this->onQualityFailure;
    }
}
