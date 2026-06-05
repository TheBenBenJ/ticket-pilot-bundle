<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Review;

use HeadlessChromium\Page;
use TheBenBenJ\TicketPilotBundle\Contract\BrowserPageInterface;

/**
 * Adapts a headless-Chromium page (chrome-php/chrome) to {@see BrowserPageInterface}.
 *
 * Interactions go through `evaluate()` (querySelector + native events) rather than
 * mouse coordinates, which is the most robust approach for headless forms.
 */
final class ChromePage implements BrowserPageInterface
{
    public function __construct(
        private readonly Page $page,
    ) {
    }

    public function visit(string $url): void
    {
        $this->page->navigate($url)->waitForNavigation();
    }

    public function click(string $selector): void
    {
        $ok = (bool) $this->page->evaluate(\sprintf(
            '(() => { const el = document.querySelector(%s); if (!el) { return false; } el.click(); return true; })()',
            json_encode($selector, \JSON_THROW_ON_ERROR),
        ))->getReturnValue();

        if (!$ok) {
            throw new \RuntimeException(\sprintf('click: selector "%s" not found', $selector));
        }
    }

    public function fill(string $selector, string $value): void
    {
        $ok = (bool) $this->page->evaluate(\sprintf(
            '(() => { const el = document.querySelector(%s); if (!el) { return false; }'
            .' el.value = %s; el.dispatchEvent(new Event("input", {bubbles:true}));'
            .' el.dispatchEvent(new Event("change", {bubbles:true})); return true; })()',
            json_encode($selector, \JSON_THROW_ON_ERROR),
            json_encode($value, \JSON_THROW_ON_ERROR),
        ))->getReturnValue();

        if (!$ok) {
            throw new \RuntimeException(\sprintf('fill: selector "%s" not found', $selector));
        }
    }

    public function waitForSelector(string $selector, int $timeoutMs): void
    {
        $deadline = microtime(true) + ($timeoutMs / 1000);
        do {
            if ($this->hasSelector($selector)) {
                return;
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);

        throw new \RuntimeException(\sprintf('waitForSelector: "%s" did not appear within %dms', $selector, $timeoutMs));
    }

    public function hasSelector(string $selector): bool
    {
        return (bool) $this->page->evaluate(\sprintf(
            '!!document.querySelector(%s)',
            json_encode($selector, \JSON_THROW_ON_ERROR),
        ))->getReturnValue();
    }

    public function text(): string
    {
        return (string) $this->page->evaluate('document.body ? document.body.innerText : ""')->getReturnValue();
    }

    public function screenshot(string $path): void
    {
        $dir = \dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        $this->page->screenshot()->saveToFile($path);
    }

    public function close(): void
    {
        // The browser lifecycle is owned by ChromeRecipeRunner.
    }
}
