<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Contract;

/**
 * Minimal browser page abstraction the recipe executor drives. Implemented by a
 * real engine (e.g. headless Chromium); kept tiny so the step logic is testable
 * with a fake page. Methods throw on failure.
 */
interface BrowserPageInterface
{
    public function visit(string $url): void;

    public function click(string $selector): void;

    public function fill(string $selector, string $value): void;

    public function waitForSelector(string $selector, int $timeoutMs): void;

    public function hasSelector(string $selector): bool;

    /**
     * Visible text of the current page.
     */
    public function text(): string;

    public function screenshot(string $path): void;

    public function close(): void;
}
