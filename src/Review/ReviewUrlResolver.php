<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Review;

/**
 * Resolves the base URL to test against: an explicit override wins, otherwise the
 * configured pattern with {ticket}/{key}/{branch}/{branch_slug} placeholders
 * (review apps are commonly deployed per branch).
 */
final class ReviewUrlResolver
{
    public function __construct(
        private readonly string $urlPattern = '',
    ) {
    }

    public function resolve(string $key, string $branch = '', ?string $override = null): string
    {
        if (null !== $override && '' !== $override) {
            return $override;
        }

        if ('' === $this->urlPattern) {
            throw new \RuntimeException('No review URL: pass --url or configure ticket_pilot.review.url_pattern.');
        }

        return strtr($this->urlPattern, [
            '{ticket}' => $key,
            '{key}' => $key,
            '{branch}' => $branch,
            '{branch_slug}' => $this->slug($branch),
        ]);
    }

    private function slug(string $value): string
    {
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $value));

        return trim($slug, '-');
    }
}
