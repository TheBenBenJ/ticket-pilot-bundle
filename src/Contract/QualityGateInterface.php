<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Contract;

/**
 * Runs the project quality checks (linters, static analysis, tests) and reports
 * whether the working tree passes.
 */
interface QualityGateInterface
{
    public function verify(): QualityReport;
}
