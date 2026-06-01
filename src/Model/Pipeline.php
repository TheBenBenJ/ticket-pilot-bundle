<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Model;

/**
 * Result of a CI pipeline trigger against a VCS provider.
 */
final readonly class Pipeline
{
    public function __construct(
        public int $id,
        public string $url,
        public string $status,
    ) {
    }
}
