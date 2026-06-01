<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use TheBenBenJ\TicketPilotBundle\Contract\PipelineTriggerInterface;
use TheBenBenJ\TicketPilotBundle\Registry\AgentRegistry;
use TheBenBenJ\TicketPilotBundle\Registry\TicketSourceRegistry;

/**
 * HTTP entry point that triggers a CI pipeline carrying the auto-dev variables.
 *
 * Registered as a service; route is loaded from Resources/config/routes.php and
 * is opt-in (the bundle does not import it automatically).
 */
final class TriggerPipelineController
{
    public function __construct(
        private readonly PipelineTriggerInterface $pipelineTrigger,
        private readonly TicketSourceRegistry $sources,
        private readonly AgentRegistry $agents,
        private readonly string $defaultRef,
        private readonly string $defaultSource,
        private readonly string $defaultAgent,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $ticket = (string) $request->query->get('ticket', '');
        if ('' === $ticket) {
            return new JsonResponse(['error' => 'The "ticket" query parameter is required'], 400);
        }

        $source = (string) $request->query->get('source', $this->defaultSource);
        $agent = (string) $request->query->get('agent', $this->defaultAgent);
        $model = (string) $request->query->get('model', 'auto');
        $ref = (string) $request->query->get('ref', '');

        if (!$this->sources->has($source)) {
            return new JsonResponse(['error' => \sprintf('Unknown source "%s"', $source)], 400);
        }
        if (!$this->agents->has($agent)) {
            return new JsonResponse(['error' => \sprintf('Unknown agent "%s"', $agent)], 400);
        }

        try {
            $pipeline = $this->pipelineTrigger->triggerPipeline('' !== $ref ? $ref : $this->defaultRef, [
                'IA_ENABLE' => 'true',
                'IA_TICKET' => $ticket,
                'IA_SOURCE' => $source,
                'IA_MODEL' => $model,
                'IA_AGENT' => $agent,
            ]);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }

        return new JsonResponse([
            'ticket' => $ticket,
            'source' => $source,
            'agent' => $agent,
            'model' => $model,
            'pipeline_id' => $pipeline->id,
            'pipeline_url' => $pipeline->url,
            'status' => $pipeline->status,
        ]);
    }
}
