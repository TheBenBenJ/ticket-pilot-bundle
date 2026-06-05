<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Review;

final readonly class StepResult
{
    public function __construct(
        public RecipeStep $step,
        public bool $passed,
        public string $message = '',
        public ?string $screenshot = null,
    ) {
    }
}
