<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use TheBenBenJ\TicketPilotBundle\Contract\CodingAgentInterface;
use TheBenBenJ\TicketPilotBundle\Contract\IterationReporterInterface;
use TheBenBenJ\TicketPilotBundle\Contract\MergeRequestCommentReaderInterface;
use TheBenBenJ\TicketPilotBundle\Contract\MergeRequestReaderInterface;
use TheBenBenJ\TicketPilotBundle\Contract\TicketSourceInterface;
use TheBenBenJ\TicketPilotBundle\Contract\VcsProviderInterface;
use TheBenBenJ\TicketPilotBundle\Git\GitInterface;
use TheBenBenJ\TicketPilotBundle\Model\AgentResult;
use TheBenBenJ\TicketPilotBundle\Model\MergeRequest;
use TheBenBenJ\TicketPilotBundle\Model\Ticket;
use TheBenBenJ\TicketPilotBundle\Prompt\IteratePromptBuilder;
use TheBenBenJ\TicketPilotBundle\Registry\AgentRegistry;
use TheBenBenJ\TicketPilotBundle\Service\IterateRunner;
use TheBenBenJ\TicketPilotBundle\Service\MergeRequestFactory;

final class IterateRunnerTest extends TestCase
{
    private function runner(FakeGit $git, FakeVcs $vcs): IterateRunner
    {
        return new IterateRunner(
            new AgentRegistry([new FakeIterateAgent()]),
            new IteratePromptBuilder(),
            new MergeRequestFactory('<<<MR_SUMMARY', 'MR_SUMMARY>>>', '[{key}] {title} #REVIEW'),
            $git,
            $vcs,
        );
    }

    private function ticket(): Ticket
    {
        return new Ticket('PROJ-7', 'Fix planning', 'desc', 'Bug', 'jira', comments: ['Carol: please fix']);
    }

    public function testThrowsWhenTheBranchDoesNotExist(): void
    {
        $runner = $this->runner(new FakeGit(branchExists: false), new FakeVcs());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/does not exist/');
        $runner->process($this->ticket(), 'hotfix/PROJ-7', 'cursor');
    }

    public function testCheckoutsCommitsAndReports(): void
    {
        $git = new FakeGit(branchExists: true, changes: true, files: ['src/A.php']);
        $vcs = new FakeVcs(comments: ['Alice: nope'], description: 'a button');
        $source = new FakeIterateSource();

        $outcome = $this->runner($git, $vcs)->process($this->ticket(), 'hotfix/PROJ-7', 'cursor', null, null, $source);

        self::assertSame('hotfix/PROJ-7', $git->checkedOut);
        self::assertSame('hotfix/PROJ-7', $git->pushedBranch);
        self::assertStringContainsString('did the thing', $outcome->summary);
        self::assertSame(['src/A.php'], $outcome->filesChanged);
        // Ticket comment (1) + merge request comment (1).
        self::assertSame(2, $outcome->feedbackCount);
        self::assertSame('hotfix/PROJ-7', $source->reportedBranch);
        self::assertStringContainsString('did the thing', $source->reportedSummary);
    }

    public function testThrowsAndDoesNotPushWhenTheAgentMadeNoChange(): void
    {
        $git = new FakeGit(branchExists: true, changes: false);

        try {
            $this->runner($git, new FakeVcs())->process($this->ticket(), 'hotfix/PROJ-7', 'cursor');
            self::fail('Expected a RuntimeException');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('no change', $e->getMessage());
        }

        self::assertNull($git->pushedBranch);
    }
}

final class FakeIterateAgent implements CodingAgentInterface
{
    public function getName(): string
    {
        return 'cursor';
    }

    public function run(string $prompt, ?string $model = null, ?callable $onOutput = null): AgentResult
    {
        return new AgentResult(true, "blah\n<<<MR_SUMMARY\n- did the thing\nMR_SUMMARY>>>\nblah");
    }
}

final class FakeGit implements GitInterface
{
    public ?string $checkedOut = null;
    public ?string $pushedBranch = null;

    /**
     * @param list<string> $files
     */
    public function __construct(
        private readonly bool $branchExists = true,
        private readonly bool $changes = true,
        private readonly array $files = [],
    ) {
    }

    public function remoteBranchExists(string $branch): bool
    {
        return $this->branchExists;
    }

    public function localBranchExists(string $branch): bool
    {
        return false;
    }

    public function hasChanges(): bool
    {
        return $this->changes;
    }

    public function changedFiles(): array
    {
        return $this->files;
    }

    public function createBranch(string $branch, string $base): void
    {
    }

    public function checkoutBranch(string $branch): void
    {
        $this->checkedOut = $branch;
    }

    public function commitAndPush(string $branch, string $message, array $excludePaths = []): void
    {
        $this->pushedBranch = $branch;
    }

    public function deleteLocalBranch(string $branch, string $fallbackBranch): void
    {
    }

    public function deleteRemoteBranch(string $branch): void
    {
    }
}

final class FakeVcs implements VcsProviderInterface, MergeRequestReaderInterface, MergeRequestCommentReaderInterface
{
    /**
     * @param list<string> $comments
     */
    public function __construct(
        private readonly array $comments = [],
        private readonly string $description = '',
    ) {
    }

    public function createMergeRequest(string $sourceBranch, string $targetBranch, string $title, string $description, bool $draft = false): MergeRequest
    {
        return new MergeRequest(1, 'http://mr');
    }

    public function remoteBranchExists(string $branch): bool
    {
        return true;
    }

    public function mergeRequestDescription(string $sourceBranch): string
    {
        return $this->description;
    }

    public function mergeRequestComments(string $sourceBranch): array
    {
        return $this->comments;
    }
}

final class FakeIterateSource implements TicketSourceInterface, IterationReporterInterface
{
    public ?string $reportedBranch = null;
    public string $reportedSummary = '';

    public function getName(): string
    {
        return 'jira';
    }

    public function fetchPending(int $limit = 1): array
    {
        return [];
    }

    public function fetchOne(string $key): Ticket
    {
        return new Ticket($key, 'T', 'd', 'Bug', 'jira');
    }

    public function reportIteration(Ticket $ticket, string $branch, string $summary): void
    {
        $this->reportedBranch = $branch;
        $this->reportedSummary = $summary;
    }
}
