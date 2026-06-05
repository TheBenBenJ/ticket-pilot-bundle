<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Review;

use TheBenBenJ\TicketPilotBundle\Contract\BrowserPageInterface;

/**
 * Replays a recipe step by step against a {@see BrowserPageInterface}, stopping
 * at the first failed step. Engine-agnostic, so it is fully unit-testable with a
 * fake page.
 */
final class RecipeExecutor
{
    public function __construct(
        private readonly string $screenshotDir = 'var/ticket-pilot/screenshots',
        private readonly int $waitTimeoutMs = 5000,
    ) {
    }

    public function run(BrowserPageInterface $page, Recipe $recipe, string $baseUrl): RecipeResult
    {
        $results = [];
        $screenshots = [];
        $passed = true;

        foreach ($recipe->steps as $index => $step) {
            try {
                $screenshot = $this->execute($page, $step, rtrim($baseUrl, '/'), $recipe->key, $index);
                $results[] = new StepResult($step, true, '', $screenshot);
                if (null !== $screenshot) {
                    $screenshots[] = $screenshot;
                }
            } catch (\Throwable $e) {
                $results[] = new StepResult($step, false, $e->getMessage());
                $passed = false;
                break;
            }
        }

        return new RecipeResult($passed, $results, $screenshots);
    }

    private function execute(BrowserPageInterface $page, RecipeStep $step, string $baseUrl, string $key, int $index): ?string
    {
        switch ($step->action) {
            case RecipeStep::VISIT:
                $page->visit($baseUrl.'/'.ltrim((string) $step->target, '/'));

                return null;
            case RecipeStep::CLICK:
                $page->click($this->requireTarget($step));

                return null;
            case RecipeStep::FILL:
                $page->fill($this->requireTarget($step), (string) $step->value);

                return null;
            case RecipeStep::WAIT_FOR:
                $page->waitForSelector($this->requireTarget($step), $this->waitTimeoutMs);

                return null;
            case RecipeStep::ASSERT_SELECTOR:
                if (!$page->hasSelector($this->requireTarget($step))) {
                    throw new \RuntimeException(\sprintf('Selector "%s" not found', $step->target));
                }

                return null;
            case RecipeStep::ASSERT_SEE:
                $needle = (string) ($step->value ?? $step->target);
                if (!str_contains($page->text(), $needle)) {
                    throw new \RuntimeException(\sprintf('Expected to see "%s"', $needle));
                }

                return null;
            case RecipeStep::ASSERT_NOT_SEE:
                $needle = (string) ($step->value ?? $step->target);
                if (str_contains($page->text(), $needle)) {
                    throw new \RuntimeException(\sprintf('Did not expect to see "%s"', $needle));
                }

                return null;
            case RecipeStep::SCREENSHOT:
                $name = $step->value ?? $step->target ?? (string) $index;
                $path = \sprintf('%s/%s-%s.png', rtrim($this->screenshotDir, '/'), $key, preg_replace('/[^A-Za-z0-9_-]+/', '_', $name));
                $page->screenshot($path);

                return $path;
            default:
                throw new \RuntimeException(\sprintf('Unknown recipe action "%s"', $step->action));
        }
    }

    private function requireTarget(RecipeStep $step): string
    {
        if (null === $step->target || '' === $step->target) {
            throw new \RuntimeException(\sprintf('Action "%s" requires a target selector', $step->action));
        }

        return $step->target;
    }
}
