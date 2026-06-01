<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Contract;

use TheBenBenJ\TicketPilotBundle\Model\Pipeline;

/**
 * A VCS provider able to trigger a CI pipeline carrying the auto-dev variables.
 *
 * Separated from {@see VcsProviderInterface} because not every host exposes a
 * pipeline API, and the HTTP controller only needs this capability.
 */
interface PipelineTriggerInterface
{
    /**
     * @param array<string, scalar> $variables CI variables to inject into the pipeline
     */
    public function triggerPipeline(string $ref, array $variables): Pipeline;
}
