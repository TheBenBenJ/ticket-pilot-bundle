<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use TheBenBenJ\TicketPilotBundle\Git\GitInterface;
use TheBenBenJ\TicketPilotBundle\Model\Ticket;
use TheBenBenJ\TicketPilotBundle\Service\BranchPlanner;

final class BranchPlannerTest extends TestCase
{
    public function testFeatureTicketBranchesOffFeatureBase(): void
    {
        $plan = $this->planner()->plan($this->ticket(type: 'Story'));

        self::assertSame('feature/PROJ-1', $plan->branch);
        self::assertSame('develop', $plan->base);
    }

    public function testBugTicketBranchesOffHotfixBase(): void
    {
        $plan = $this->planner()->plan($this->ticket(type: 'Bug'));

        self::assertSame('hotfix/PROJ-1', $plan->branch);
        self::assertSame('main', $plan->base);
    }

    public function testNumericFixVersionTargetsExistingReleaseBranch(): void
    {
        $git = $this->createMock(GitInterface::class);
        $git->method('remoteBranchExists')->with('release/RC-1.4')->willReturn(true);

        $plan = $this->planner($git)->plan($this->ticket(type: 'Story', fixVersions: ['1.4']));

        self::assertSame('release/RC-1.4', $plan->base);
    }

    public function testMissingReleaseBranchFallsBackToBase(): void
    {
        $git = $this->createMock(GitInterface::class);
        $git->method('remoteBranchExists')->willReturn(false);

        $plan = $this->planner($git)->plan($this->ticket(type: 'Story', fixVersions: ['9.9']));

        self::assertSame('develop', $plan->base);
    }

    private function planner(?GitInterface $git = null): BranchPlanner
    {
        return new BranchPlanner($git ?? $this->createStub(GitInterface::class));
    }

    /**
     * @param list<string> $fixVersions
     */
    private function ticket(string $type, array $fixVersions = []): Ticket
    {
        return new Ticket('PROJ-1', 'Title', 'Description', $type, 'jira', $fixVersions);
    }
}
