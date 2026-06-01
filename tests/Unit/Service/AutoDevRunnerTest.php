<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use TheBenBenJ\TicketPilotBundle\Contract\CodingAgentInterface;
use TheBenBenJ\TicketPilotBundle\Contract\PromptBuilderInterface;
use TheBenBenJ\TicketPilotBundle\Contract\QualityGateInterface;
use TheBenBenJ\TicketPilotBundle\Contract\QualityReport;
use TheBenBenJ\TicketPilotBundle\Contract\VcsProviderInterface;
use TheBenBenJ\TicketPilotBundle\Exception\QualityGateFailedException;
use TheBenBenJ\TicketPilotBundle\Git\GitClient;
use TheBenBenJ\TicketPilotBundle\Model\AgentResult;
use TheBenBenJ\TicketPilotBundle\Model\MergeRequest;
use TheBenBenJ\TicketPilotBundle\Model\Ticket;
use TheBenBenJ\TicketPilotBundle\Registry\AgentRegistry;
use TheBenBenJ\TicketPilotBundle\Service\AutoDevRunner;
use TheBenBenJ\TicketPilotBundle\Service\BranchPlanner;
use TheBenBenJ\TicketPilotBundle\Service\MergeRequestFactory;

final class AutoDevRunnerTest extends TestCase
{
    public function testFailingQualityGateAbortsBeforePushAndMergeRequest(): void
    {
        $git = $this->git();
        $git->expects(self::never())->method('commitAndPush');

        $vcs = $this->createMock(VcsProviderInterface::class);
        $vcs->expects(self::never())->method('createMergeRequest');

        $runner = $this->runner($git, $vcs, $this->gate(false));

        $this->expectException(QualityGateFailedException::class);

        $runner->process($this->ticket(), 'cursor');
    }

    public function testPassingQualityGatePushesAndOpensMergeRequest(): void
    {
        $git = $this->git();
        $git->expects(self::once())->method('commitAndPush');

        $vcs = $this->createMock(VcsProviderInterface::class);
        $vcs->expects(self::once())->method('createMergeRequest')->willReturn(new MergeRequest(7, 'https://mr/7'));

        $outcome = $this->runner($git, $vcs, $this->gate(true))->process($this->ticket(), 'cursor');

        self::assertSame('PROJ-1', $outcome->ticketKey);
        self::assertSame(7, $outcome->mergeRequest->iid);
    }

    public function testWithoutQualityGateThePipelineRunsUnchanged(): void
    {
        $git = $this->git();
        $git->expects(self::once())->method('commitAndPush');

        $vcs = $this->createMock(VcsProviderInterface::class);
        $vcs->expects(self::once())->method('createMergeRequest')->willReturn(new MergeRequest(1, 'https://mr/1'));

        $outcome = $this->runner($git, $vcs, null)->process($this->ticket(), 'cursor');

        self::assertSame(1, $outcome->mergeRequest->iid);
    }

    private function runner(GitClient $git, VcsProviderInterface $vcs, ?QualityGateInterface $gate): AutoDevRunner
    {
        $agent = $this->createStub(CodingAgentInterface::class);
        $agent->method('getName')->willReturn('cursor');
        $agent->method('run')->willReturn(new AgentResult(true, 'agent output'));

        $promptBuilder = $this->createStub(PromptBuilderInterface::class);
        $promptBuilder->method('build')->willReturn('prompt');

        return new AutoDevRunner(
            new AgentRegistry([$agent]),
            $promptBuilder,
            new BranchPlanner($git),
            new MergeRequestFactory(),
            $git,
            $vcs,
            [],
            $gate,
        );
    }

    private function git(): GitClient&\PHPUnit\Framework\MockObject\MockObject
    {
        $git = $this->createMock(GitClient::class);
        $git->method('localBranchExists')->willReturn(false);
        $git->method('remoteBranchExists')->willReturn(false);

        return $git;
    }

    private function gate(bool $passed): QualityGateInterface
    {
        $gate = $this->createStub(QualityGateInterface::class);
        $gate->method('verify')->willReturn(new QualityReport($passed, $passed ? '' : 'make check failed'));

        return $gate;
    }

    private function ticket(): Ticket
    {
        return new Ticket('PROJ-1', 'Title', 'Description', 'Task', 'jira');
    }
}
