<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Run;

/**
 * Decorator that persists review screenshots and scenarios to web-served
 * directories before forwarding the run to the inner store (local JSONL or
 * remote HTTP ingest).
 */
final class TrackedRunStore implements RunStoreInterface
{
    public function __construct(
        private readonly RunStoreInterface $inner,
        private readonly RunScreenshotPersister $screenshotPersister,
        private readonly RunScenarioPersister $scenarioPersister,
    ) {
    }

    public function record(RunRecord $record): void
    {
        $this->inner->record($this->withPersistedScenario($this->withPersistedScreenshots($record)));
    }

    public function recent(int $limit = 50): array
    {
        return $this->inner->recent($limit);
    }

    private function withPersistedScreenshots(RunRecord $record): RunRecord
    {
        if ([] === $record->screenshots) {
            return $record;
        }

        $urls = $this->screenshotPersister->persist($record->id, $record->screenshots);
        if ([] === $urls) {
            return $record;
        }

        return new RunRecord(
            $record->id,
            $record->type,
            $record->ticketKey,
            $record->status,
            $record->startedAt,
            $record->branch,
            $record->summary,
            $record->url,
            $record->agent,
            $record->source,
            $record->duration,
            $urls,
            $record->scenario,
            $record->scenarioUrl,
        );
    }

    private function withPersistedScenario(RunRecord $record): RunRecord
    {
        if ('' === trim($record->scenario)) {
            return $record;
        }

        $url = $this->scenarioPersister->persist($record->ticketKey, $record->scenario);
        if ('' === $url) {
            return $record;
        }

        return new RunRecord(
            $record->id,
            $record->type,
            $record->ticketKey,
            $record->status,
            $record->startedAt,
            $record->branch,
            $record->summary,
            $record->url,
            $record->agent,
            $record->source,
            $record->duration,
            $record->screenshots,
            $record->scenario,
            $url,
        );
    }
}
