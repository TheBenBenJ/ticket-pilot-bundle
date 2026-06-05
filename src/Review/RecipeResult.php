<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Review;

/**
 * Outcome of replaying a recipe in the browser.
 */
final readonly class RecipeResult
{
    /**
     * @param list<StepResult> $steps
     * @param list<string>     $screenshots Absolute paths to the screenshots taken
     */
    public function __construct(
        public bool $passed,
        public array $steps,
        public array $screenshots = [],
    ) {
    }

    public function failedStep(): ?StepResult
    {
        foreach ($this->steps as $step) {
            if (!$step->passed) {
                return $step;
            }
        }

        return null;
    }
}
