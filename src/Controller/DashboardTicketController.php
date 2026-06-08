<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use TheBenBenJ\TicketPilotBundle\Run\RunRecord;
use TheBenBenJ\TicketPilotBundle\Run\RunScreenshotResolver;
use TheBenBenJ\TicketPilotBundle\Run\RunStoreInterface;

/**
 * Per-ticket detail page: the full timeline of every run (dev, iterate, review)
 * recorded for one ticket, in chronological order, with summaries and the
 * review screenshots.
 */
final class DashboardTicketController
{
    public function __construct(
        private readonly RunStoreInterface $store,
        private readonly DashboardRenderer $renderer,
        private readonly UrlGeneratorInterface $urls,
        private readonly RunScreenshotResolver $screenshotResolver,
    ) {
    }

    public function __invoke(string $ticket): Response
    {
        $runs = array_values(array_filter(
            $this->store->recent(1000),
            static fn (RunRecord $r): bool => $r->ticketKey === $ticket,
        ));
        // recent() is newest-first; a timeline reads oldest-first.
        $runs = array_reverse(array_map($this->hydrateScreenshots(...), $runs));

        return new Response($this->renderer->ticketTimeline($ticket, $runs, $this->urls->generate('ticket_pilot_dashboard')));
    }

    private function hydrateScreenshots(RunRecord $run): RunRecord
    {
        if ([] === $run->screenshots) {
            return $run;
        }

        return new RunRecord(
            $run->id,
            $run->type,
            $run->ticketKey,
            $run->status,
            $run->startedAt,
            $run->branch,
            $run->summary,
            $run->url,
            $run->agent,
            $run->source,
            $run->duration,
            $this->screenshotResolver->resolve($run),
        );
    }
}
