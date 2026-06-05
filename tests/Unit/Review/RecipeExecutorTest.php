<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Tests\Unit\Review;

use PHPUnit\Framework\TestCase;
use TheBenBenJ\TicketPilotBundle\Contract\BrowserPageInterface;
use TheBenBenJ\TicketPilotBundle\Review\Recipe;
use TheBenBenJ\TicketPilotBundle\Review\RecipeExecutor;
use TheBenBenJ\TicketPilotBundle\Review\RecipeStep;

final class RecipeExecutorTest extends TestCase
{
    public function testHappyPathRunsEveryStepAndCapturesScreenshots(): void
    {
        $page = new FakeBrowserPage('Délai mis à jour', ['.alert-success']);
        $recipe = new Recipe('LYSI-2098', 'check', [
            new RecipeStep(RecipeStep::VISIT, '/admin/facture/1'),
            new RecipeStep(RecipeStep::FILL, '#delai', '30'),
            new RecipeStep(RecipeStep::CLICK, 'button[type=submit]'),
            new RecipeStep(RecipeStep::ASSERT_SELECTOR, '.alert-success'),
            new RecipeStep(RecipeStep::ASSERT_SEE, null, 'Délai mis à jour'),
            new RecipeStep(RecipeStep::SCREENSHOT, null, 'result'),
        ]);

        $result = (new RecipeExecutor('/tmp/shots'))->run($page, $recipe, 'https://app.example.com/');

        self::assertTrue($result->passed);
        self::assertNull($result->failedStep());
        self::assertSame('https://app.example.com/admin/facture/1', $page->visited);
        self::assertCount(1, $result->screenshots);
        self::assertStringContainsString('LYSI-2098-result.png', $result->screenshots[0]);
    }

    public function testStopsAtFirstFailedAssertion(): void
    {
        $page = new FakeBrowserPage('Erreur', []);
        $recipe = new Recipe('LYSI-1', 'check', [
            new RecipeStep(RecipeStep::VISIT, '/'),
            new RecipeStep(RecipeStep::ASSERT_SEE, null, 'Succès'),
            new RecipeStep(RecipeStep::SCREENSHOT, null, 'after'),
        ]);

        $result = (new RecipeExecutor('/tmp/shots'))->run($page, $recipe, 'https://app');

        self::assertFalse($result->passed);
        self::assertCount(2, $result->steps); // stopped before the screenshot step

        $failed = $result->failedStep();
        self::assertNotNull($failed);
        self::assertSame(RecipeStep::ASSERT_SEE, $failed->step->action);
        self::assertStringContainsString('Expected to see "Succès"', $failed->message);
    }
}

final class FakeBrowserPage implements BrowserPageInterface
{
    public ?string $visited = null;

    /**
     * @param list<string> $selectors
     */
    public function __construct(
        private readonly string $pageText,
        private readonly array $selectors,
    ) {
    }

    public function visit(string $url): void
    {
        $this->visited = $url;
    }

    public function click(string $selector): void
    {
    }

    public function fill(string $selector, string $value): void
    {
    }

    public function waitForSelector(string $selector, int $timeoutMs): void
    {
    }

    public function hasSelector(string $selector): bool
    {
        return \in_array($selector, $this->selectors, true);
    }

    public function text(): string
    {
        return $this->pageText;
    }

    public function screenshot(string $path): void
    {
    }

    public function close(): void
    {
    }
}
