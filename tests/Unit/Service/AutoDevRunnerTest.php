<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Tests\Unit\Service;

use PHPUnit\Framework\MockObject\MockObject;
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
use TheBenBenJ\TicketPilotBundle\Service\AutoDevOptions;
use TheBenBenJ\TicketPilotBundle\Service\AutoDevRunner;
use TheBenBenJ\TicketPilotBundle\Service\BranchPlanner;
use TheBenBenJ\TicketPilotBundle\Service\MergeRequestFactory;

final class AutoDevRunnerTest extends TestCase
{
    public function testFailingQualityGateWithAbortPolicyOpensNoMergeRequestAndCleansUp(): void
    {
        $git = $this->git();
        $git->expects(self::never())->method('commitAndPush');
        $git->expects(self::once())->method('deleteLocalBranch');
        $git->expects(self::never())->method('deleteRemoteBranch'); // never pushed

        $vcs = $this->createMock(VcsProviderInterface::class);
        $vcs->expects(self::never())->method('createMergeRequest');

        $runner = $this->runner($git, $vcs, $this->gate(false), new AutoDevOptions(onQualityFailure: 'abort'));

        $this->expectException(QualityGateFailedException::class);
        $runner->process($this->ticket(), 'cursor');
    }

    public function testMergeRequestFailureDeletesPushedBranch(): void
    {
        $git = $this->git();
        $git->expects(self::once())->method('deleteRemoteBranch'); // pushed before the MR failed
        $git->expects(self::once())->method('deleteLocalBranch');

        $vcs = $this->createMock(VcsProviderInterface::class);
        $vcs->method('createMergeRequest')->willThrowException(new \RuntimeException('boom'));

        $this->expectException(\RuntimeException::class);
        $this->runner($git, $vcs, $this->gate(true), new AutoDevOptions())->process($this->ticket(), 'cursor');
    }

    public function testCleanupCanBeDisabled(): void
    {
        $git = $this->git();
        $git->expects(self::never())->method('deleteLocalBranch');
        $git->expects(self::never())->method('deleteRemoteBranch');

        $vcs = $this->createMock(VcsProviderInterface::class);

        $runner = $this->runner($git, $vcs, $this->gate(false), new AutoDevOptions(onQualityFailure: 'abort', cleanupOnFailure: false));

        $this->expectException(QualityGateFailedException::class);
        $runner->process($this->ticket(), 'cursor');
    }

    public function testFailingQualityGateWithDraftPolicyOpensADraftMergeRequest(): void
    {
        $git = $this->git();
        $git->expects(self::once())->method('commitAndPush');

        $vcs = $this->createMock(VcsProviderInterface::class);
        $vcs->expects(self::once())->method('createMergeRequest')
            ->with(self::anything(), self::anything(), self::anything(), self::anything(), self::isTrue())
            ->willReturn(new MergeRequest(7, 'https://mr/7'));

        $outcome = $this->runner($git, $vcs, $this->gate(false), new AutoDevOptions(onQualityFailure: 'draft'))
            ->process($this->ticket(), 'cursor');

        self::assertSame(7, $outcome->mergeRequest->iid);
    }

    public function testAlwaysDraftOptionOpensADraftEvenWhenQualityPasses(): void
    {
        $vcs = $this->createMock(VcsProviderInterface::class);
        $vcs->expects(self::once())->method('createMergeRequest')
            ->with(self::anything(), self::anything(), self::anything(), self::anything(), self::isTrue())
            ->willReturn(new MergeRequest(1, 'https://mr/1'));

        $this->runner($this->git(), $vcs, $this->gate(true), new AutoDevOptions(draft: true))
            ->process($this->ticket(), 'cursor');
    }

    public function testWithoutQualityGateOpensANonDraftMergeRequest(): void
    {
        $vcs = $this->createMock(VcsProviderInterface::class);
        $vcs->expects(self::once())->method('createMergeRequest')
            ->with(self::anything(), self::anything(), self::anything(), self::anything(), self::isFalse())
            ->willReturn(new MergeRequest(1, 'https://mr/1'));

        $this->runner($this->git(), $vcs, null, new AutoDevOptions())
            ->process($this->ticket(), 'cursor');
    }

    private function runner(GitClient $git, VcsProviderInterface $vcs, ?QualityGateInterface $gate, AutoDevOptions $options): AutoDevRunner
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
            $options,
            $gate,
        );
    }

    private function git(): GitClient&MockObject
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
