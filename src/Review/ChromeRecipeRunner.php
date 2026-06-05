<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Review;

use HeadlessChromium\BrowserFactory;
use TheBenBenJ\TicketPilotBundle\Contract\RecipeRunnerInterface;

/**
 * Runs recipes in headless Chromium via chrome-php/chrome.
 *
 * Requires the chrome-php/chrome package and a Chromium/Chrome binary available
 * in the runtime. The step logic itself lives in {@see RecipeExecutor}.
 */
final class ChromeRecipeRunner implements RecipeRunnerInterface
{
    /**
     * @param array<string, mixed> $browserOptions Options passed to BrowserFactory::createBrowser()
     */
    public function __construct(
        private readonly RecipeExecutor $executor,
        private readonly string $chromeBinary = '',
        private readonly array $browserOptions = ['headless' => true],
    ) {
    }

    public function run(Recipe $recipe, string $baseUrl): RecipeResult
    {
        $factory = '' !== $this->chromeBinary ? new BrowserFactory($this->chromeBinary) : new BrowserFactory();
        $browser = $factory->createBrowser($this->browserOptions);

        try {
            return $this->executor->run(new ChromePage($browser->createPage()), $recipe, $baseUrl);
        } finally {
            $browser->close();
        }
    }
}
