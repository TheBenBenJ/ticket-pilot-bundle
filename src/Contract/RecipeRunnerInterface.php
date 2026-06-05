<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Contract;

use TheBenBenJ\TicketPilotBundle\Review\Recipe;
use TheBenBenJ\TicketPilotBundle\Review\RecipeResult;

/**
 * Runs a recipe against a deployed application and reports the outcome.
 * The default implementation drives headless Chromium.
 */
interface RecipeRunnerInterface
{
    public function run(Recipe $recipe, string $baseUrl): RecipeResult;
}
