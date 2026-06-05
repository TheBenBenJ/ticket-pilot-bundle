<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Tests\Unit\Review;

use PHPUnit\Framework\TestCase;
use TheBenBenJ\TicketPilotBundle\Review\ReviewUrlResolver;

final class ReviewUrlResolverTest extends TestCase
{
    public function testOverrideWins(): void
    {
        $url = (new ReviewUrlResolver('https://{branch}.review'))->resolve('LYSI-1', 'feature/x', 'https://manual');

        self::assertSame('https://manual', $url);
    }

    public function testPatternSubstitution(): void
    {
        $resolver = new ReviewUrlResolver('https://{branch_slug}.review.example.com');

        self::assertSame('https://feature-lysi-1.review.example.com', $resolver->resolve('LYSI-1', 'feature/LYSI-1'));
    }

    public function testNoUrlConfiguredThrows(): void
    {
        $this->expectException(\RuntimeException::class);

        (new ReviewUrlResolver())->resolve('LYSI-1', 'feature/x');
    }
}
