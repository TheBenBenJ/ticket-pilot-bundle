<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use TheBenBenJ\TicketPilotBundle\Model\Ticket;
use TheBenBenJ\TicketPilotBundle\Security\TicketGuard;

final class TicketGuardTest extends TestCase
{
    public function testEmptyAllowlistTrustsEveryTicket(): void
    {
        $guard = new TicketGuard([]);

        self::assertFalse($guard->isRestricted());
        self::assertTrue($guard->isTrusted($this->ticket('anyone')));
        self::assertTrue($guard->isTrusted($this->ticket(null)));
    }

    public function testAllowlistTrustsOnlyListedReporters(): void
    {
        $guard = new TicketGuard(['alice', 'bob']);

        self::assertTrue($guard->isRestricted());
        self::assertTrue($guard->isTrusted($this->ticket('alice')));
        self::assertFalse($guard->isTrusted($this->ticket('mallory')));
        self::assertFalse($guard->isTrusted($this->ticket(null)));
    }

    private function ticket(?string $reporter): Ticket
    {
        return new Ticket('PROJ-1', 'Title', 'Description', 'Task', 'jira', reporter: $reporter);
    }
}
