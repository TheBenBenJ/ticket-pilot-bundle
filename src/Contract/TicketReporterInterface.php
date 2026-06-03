<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Contract;

use TheBenBenJ\TicketPilotBundle\Model\MergeRequest;
use TheBenBenJ\TicketPilotBundle\Model\Ticket;

/**
 * Optional capability a {@see TicketSourceInterface} may also implement to report
 * back to the tracker once a merge/pull request has been opened (e.g. post a
 * comment with the MR URL). Best-effort: a failure here never fails the run.
 */
interface TicketReporterInterface
{
    public function reportMergeRequest(Ticket $ticket, MergeRequest $mergeRequest): void;
}
