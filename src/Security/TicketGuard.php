<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Security;

use TheBenBenJ\TicketPilotBundle\Model\Ticket;

/**
 * Decides whether a ticket is allowed to be processed automatically.
 *
 * Because ticket content is attacker-controllable (anyone may open an issue or
 * add the trigger label), the auto-pickup path can be restricted to tickets
 * authored by a known set of reporters. An empty allowlist means "no restriction".
 */
final class TicketGuard
{
    /**
     * @param list<string> $trustedReporters Reporter display names/logins allowed on the auto path
     */
    public function __construct(
        private readonly array $trustedReporters = [],
    ) {
    }

    public function isTrusted(Ticket $ticket): bool
    {
        if ([] === $this->trustedReporters) {
            return true;
        }

        return null !== $ticket->reporter && \in_array($ticket->reporter, $this->trustedReporters, true);
    }

    public function isRestricted(): bool
    {
        return [] !== $this->trustedReporters;
    }
}
