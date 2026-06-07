<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use TheBenBenJ\TicketPilotBundle\Contract\PipelineTriggerInterface;

/**
 * Launches a run from the dashboard by triggering a CI pipeline carrying the
 * IA_* variables for the requested action (auto-dev, iterate or review). The
 * project's CI decides what each action runs (see IA_MODE).
 *
 * Async by design: like the HTTP trigger, it starts a pipeline rather than
 * running an agent inside the web request.
 */
final class DashboardLaunchController
{
    private const ACTIONS = ['auto-dev', 'iterate', 'review'];

    public function __construct(
        private readonly DashboardRenderer $renderer,
        private readonly UrlGeneratorInterface $urls,
        private readonly string $defaultRef,
        private readonly string $defaultSource,
        private readonly string $defaultAgent,
        private readonly ?PipelineTriggerInterface $pipelineTrigger = null,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $back = $this->urls->generate('ticket_pilot_dashboard');

        if (null === $this->pipelineTrigger) {
            return new Response($this->renderer->confirmation('No pipeline trigger is configured — cannot launch from here.', $back), 400);
        }

        $action = (string) $request->request->get('action', '');
        $ticket = trim((string) $request->request->get('ticket', ''));

        if (!\in_array($action, self::ACTIONS, true)) {
            return new Response($this->renderer->confirmation(\sprintf('Unknown action "%s".', $action), $back), 400);
        }
        if ('' === $ticket) {
            return new Response($this->renderer->confirmation('A ticket key is required.', $back), 400);
        }

        $variables = $this->variables($action, $ticket, $request);

        try {
            $pipeline = $this->pipelineTrigger->triggerPipeline($this->defaultRef, $variables);
        } catch (\RuntimeException $e) {
            return new Response($this->renderer->confirmation('Failed to launch: '.$e->getMessage(), $back), 502);
        }

        return new Response($this->renderer->confirmation(
            \sprintf('%s launched for %s (pipeline #%d).', $action, $ticket, $pipeline->id),
            $back,
            $pipeline->url,
        ));
    }

    /**
     * @return array<string, string>
     */
    private function variables(string $action, string $ticket, Request $request): array
    {
        $source = (string) $request->request->get('source', $this->defaultSource);
        $agent = (string) $request->request->get('agent', $this->defaultAgent);

        $variables = [
            'IA_ENABLE' => 'true',
            'IA_MODE' => $action,
            'IA_TICKET' => $ticket,
            'IA_SOURCE' => '' !== $source ? $source : $this->defaultSource,
            'IA_AGENT' => '' !== $agent ? $agent : $this->defaultAgent,
            'IA_MODEL' => trim((string) $request->request->get('model', '')),
        ];

        if ('iterate' === $action) {
            $variables['IA_BRANCH'] = trim((string) $request->request->get('branch', ''));
        }
        if ('review' === $action) {
            $variables['IA_URL'] = trim((string) $request->request->get('url', ''));
        }

        return array_filter($variables, static fn (string $v): bool => '' !== $v);
    }
}
