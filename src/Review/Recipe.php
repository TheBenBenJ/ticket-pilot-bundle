<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Review;

/**
 * A browser test recipe for a ticket: an ordered list of steps the agent wrote
 * while implementing the feature, replayed by `ia:review`.
 */
final readonly class Recipe
{
    /**
     * @param list<RecipeStep> $steps
     */
    public function __construct(
        public string $key,
        public string $description,
        public array $steps,
    ) {
    }
}
