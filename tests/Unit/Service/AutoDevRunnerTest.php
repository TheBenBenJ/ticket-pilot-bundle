<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Tests\Unit\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use TheBenBenJ\TicketPilotBundle\Contract\CodingAgentInterface;
use TheBenBenJ\TicketPilotBundle\Contract\PromptBuilderInterface;
use TheBenBenJ\TicketPilotBundle\Contract\QualityGateInterface;
use TheBenBenJ\TicketPilotBundle\Contract\QualityReport;
use TheBenBenJ\TicketPilotBundle\Contract\VcsProviderInterface;
use TheBenBenJ\TicketPilotBundle\Event\TicketFailedEvent;
use TheBenBenJ\TicketPilotBundle\Event\TicketProcessedEvent;
use TheBenBenJ\TicketPilotBundle\Exception\QualityGateFailedException;
use TheBenBenJ\TicketPilotBundle\Exception\TicketLockedException;
use TheBenBenJ\TicketPilotBundle\Git\GitInterface;
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

        self::assertSame(7, $outcome->mergeRequest->number);
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

    public function testSuccessDispatchesTicketProcessedEvent(): void
    {
        $vcs = $this->createMock(VcsProviderInterface::class);
        $vcs->method('createMergeRequest')->willReturn(new MergeRequest(1, 'https://mr/1'));

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())->method('dispatch')
            ->with(self::isInstanceOf(TicketProcessedEvent::class))
            ->willReturnArgument(0);

        $this->runner($this->git(), $vcs, null, new AutoDevOptions(), $dispatcher)->process($this->ticket(), 'cursor');
    }

    public function testFailureDispatchesTicketFailedEvent(): void
    {
        $vcs = $this->createMock(VcsProviderInterface::class);
        $vcs->method('createMergeRequest')->willThrowException(new \RuntimeException('boom'));

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())->method('dispatch')
            ->with(self::isInstanceOf(TicketFailedEvent::class))
            ->willReturnArgument(0);

        $this->expectException(\RuntimeException::class);
        $this->runner($this->git(), $vcs, $this->gate(true), new AutoDevOptions(), $dispatcher)->process($this->ticket(), 'cursor');
    }

    public function testTicketAlreadyLockedIsSkipped(): void
    {
        $store = new InMemoryStore();
        $factory = new LockFactory($store);
        // Simulate a concurrent run holding the ticket's lock.
        $held = $factory->createLock('ticket-pilot-PROJ-1');
        self::assertTrue($held->acquire());

        $git = $this->git();
        $git->expects(self::never())->method('createBranch');

        $runner = $this->runner($git, $this->createMock(VcsProviderInterface::class), null, new AutoDevOptions(), null, $factory);

        $this->expectException(TicketLockedException::class);
        $runner->process($this->ticket(), 'cursor');
    }

    public function testLockIsReleasedAfterTheRun(): void
    {
        $store = new InMemoryStore();
        $factory = new LockFactory($store);

        $vcs = $this->createMock(VcsProviderInterface::class);
        $vcs->method('createMergeRequest')->willReturn(new MergeRequest(1, 'https://mr/1'));

        $this->runner($this->git(), $vcs, null, new AutoDevOptions(), null, $factory)->process($this->ticket(), 'cursor');

        // The lock must be free again once the run finished.
        self::assertTrue($factory->createLock('ticket-pilot-PROJ-1')->acquire());
    }

    private function runner(GitInterface $git, VcsProviderInterface $vcs, ?QualityGateInterface $gate, AutoDevOptions $options, ?EventDispatcherInterface $dispatcher = null, ?LockFactory $lockFactory = null): AutoDevRunner
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
            $dispatcher,
            $lockFactory,
        );
    }

    private function git(): GitInterface&MockObject
    {
        $git = $this->createMock(GitInterface::class);
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
