<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Event;

use TheBenBenJ\TicketPilotBundle\Model\Ticket;
use TheBenBenJ\TicketPilotBundle\Service\AutoDevOutcome;

/**
 * Dispatched after a ticket has been processed successfully and its merge/pull
 * request opened. Listen to it for notifications, metrics or follow-up actions.
 */
final class TicketProcessedEvent
{
    public function __construct(
        public readonly Ticket $ticket,
        public readonly AutoDevOutcome $outcome,
    ) {
    }
}
