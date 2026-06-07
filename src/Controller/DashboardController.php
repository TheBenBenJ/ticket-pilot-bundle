<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use TheBenBenJ\TicketPilotBundle\Agent\AgentModelCatalog;
use TheBenBenJ\TicketPilotBundle\Registry\AgentRegistry;
use TheBenBenJ\TicketPilotBundle\Registry\TicketSourceRegistry;
use TheBenBenJ\TicketPilotBundle\Run\RunStoreInterface;

/**
 * HTML dashboard: lists the runs the bundle has recorded and (when a VCS
 * provider exposing pipelines is enabled) offers forms to launch new ones.
 *
 * Registered as a service; the route is opt-in (imported from the bundle routes).
 */
final class DashboardController
{
    public function __construct(
        private readonly RunStoreInterface $store,
        private readonly DashboardRenderer $renderer,
        private readonly UrlGeneratorInterface $urls,
        private readonly TicketSourceRegistry $sources,
        private readonly AgentRegistry $agents,
        private readonly AgentModelCatalog $modelCatalog,
        private readonly string $defaultSource,
        private readonly string $defaultAgent,
        private readonly bool $canLaunch,
        private readonly string $cursorBinary = '',
        private readonly string $claudeBinary = '',
    ) {
    }

    public function __invoke(): Response
    {
        $launchUrl = $this->urls->generate('ticket_pilot_dashboard_launch');
        // Built once with a placeholder; the renderer substitutes each ticket key.
        $detailUrlTemplate = $this->urls->generate('ticket_pilot_dashboard_ticket', ['ticket' => '__TICKET__']);

        $agentNames = $this->agents->names();
        $binaries = [];
        if ('' !== $this->cursorBinary && \in_array('cursor', $agentNames, true)) {
            $binaries['cursor'] = $this->cursorBinary;
        }
        if ('' !== $this->claudeBinary && \in_array('claude', $agentNames, true)) {
            $binaries['claude'] = $this->claudeBinary;
        }

        $html = $this->renderer->page(
            $this->store->recent(100),
            $launchUrl,
            $this->canLaunch,
            $this->sources->names(),
            $agentNames,
            $this->defaultSource,
            $this->defaultAgent,
            $detailUrlTemplate,
            $this->modelCatalog->all($agentNames, $binaries),
        );

        return new Response($html);
    }
}
