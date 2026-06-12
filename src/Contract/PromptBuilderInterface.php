<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Contract;

use TheBenBenJ\TicketPilotBundle\Model\Ticket;

/**
 * Builds the natural-language prompt handed to the coding agent for a ticket.
 *
 * Override the default implementation to inject project-specific conventions
 * (coding rules, quality commands, summary markers, language, ...).
 */
interface PromptBuilderInterface
{
    /**
     * @param string $instructions Optional free-text directive from the operator,
     *                             injected with priority over the ticket description
     */
    public function build(Ticket $ticket, string $instructions = ''): string;
}
