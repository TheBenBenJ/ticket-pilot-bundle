<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Contract;

final readonly class QualityReport
{
    public function __construct(
        public bool $passed,
        public string $errors = '',
    ) {
    }
}
