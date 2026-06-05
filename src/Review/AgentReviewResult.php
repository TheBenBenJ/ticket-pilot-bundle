<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Review;

/**
 * Outcome of an agent-driven browser review (the {@see AgentReviewRunner}).
 *
 * Unlike {@see RecipeResult} (a deterministic replay with per-step results), the
 * agent freely explores the app and reports a verdict plus a human-readable
 * summary it wrote between the configured markers, and the screenshots it took.
 */
final readonly class AgentReviewResult
{
    /**
     * @param list<string> $screenshots Absolute paths to the screenshots taken during the review
     */
    public function __construct(
        public bool $passed,
        public string $summary,
        public array $screenshots = [],
        public string $rawOutput = '',
    ) {
    }
}
