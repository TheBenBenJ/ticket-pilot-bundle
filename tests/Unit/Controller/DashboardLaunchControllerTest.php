<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use TheBenBenJ\TicketPilotBundle\Contract\PipelineTriggerInterface;
use TheBenBenJ\TicketPilotBundle\Controller\DashboardLaunchController;
use TheBenBenJ\TicketPilotBundle\Controller\DashboardRenderer;
use TheBenBenJ\TicketPilotBundle\Model\Pipeline;
use TheBenBenJ\TicketPilotBundle\Run\RunRecord;
use TheBenBenJ\TicketPilotBundle\Run\RunStoreInterface;

final class DashboardLaunchControllerTest extends TestCase
{
    private function controller(RunStoreInterface $store, ?PipelineTriggerInterface $trigger): DashboardLaunchController
    {
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/ia/dashboard');

        return new DashboardLaunchController(new DashboardRenderer(), $urls, 'main', 'jira', 'cursor', $trigger, $store);
    }

    public function testLaunchTriggersPipelineAndRecordsAQueuedRun(): void
    {
        $store = new LaunchCapturingStore();
        $response = $this->controller($store, new FakeTrigger())($this->request(['action' => 'iterate', 'ticket' => 'PROJ-1', 'branch' => 'feat/PROJ-1']));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $store->records);
        self::assertSame(RunRecord::STATUS_QUEUED, $store->records[0]->status);
        self::assertSame('iterate', $store->records[0]->type);
        self::assertSame('PROJ-1', $store->records[0]->ticketKey);
        self::assertSame('feat/PROJ-1', $store->records[0]->branch);
        self::assertSame('http://ci/42', $store->records[0]->url);
    }

    public function testNoTriggerConfiguredRecordsNothing(): void
    {
        $store = new LaunchCapturingStore();
        $response = $this->controller($store, null)($this->request(['action' => 'auto-dev', 'ticket' => 'PROJ-2']));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([], $store->records);
    }

    public function testMissingTicketAndInstructionsRecordsNothing(): void
    {
        $store = new LaunchCapturingStore();
        $response = $this->controller($store, new FakeTrigger())($this->request(['action' => 'auto-dev', 'ticket' => '', 'instructions' => '']));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([], $store->records);
    }

    public function testTicketlessLaunchWithInstructionsUsesAdhocKeyAndForwardsInstructions(): void
    {
        $store = new LaunchCapturingStore();
        $trigger = new FakeTrigger();
        $response = $this->controller($store, $trigger)($this->request([
            'action' => 'review',
            'ticket' => '',
            'url' => 'https://app.test',
            'instructions' => "Login then open the planning\nCheck the alerts",
        ]));

        self::assertSame(200, $response->getStatusCode());
        // Instructions forwarded to CI; no ticket variable.
        self::assertSame("Login then open the planning\nCheck the alerts", $trigger->lastVariables['IA_INSTRUCTIONS']);
        self::assertArrayNotHasKey('IA_TICKET', $trigger->lastVariables);
        self::assertSame('https://app.test', $trigger->lastVariables['IA_URL']);
        // Queued record keyed by the deterministic ad-hoc key (matches the CI command).
        self::assertCount(1, $store->records);
        self::assertStringStartsWith('adhoc-', $store->records[0]->ticketKey);
    }

    /**
     * @param array<string, string> $body
     */
    private function request(array $body): Request
    {
        return Request::create('/ia/dashboard/launch', 'POST', $body);
    }
}

final class FakeTrigger implements PipelineTriggerInterface
{
    /** @var array<string, scalar> */
    public array $lastVariables = [];

    public function triggerPipeline(string $ref, array $variables): Pipeline
    {
        $this->lastVariables = $variables;

        return new Pipeline(42, 'http://ci/42', 'pending');
    }
}

final class LaunchCapturingStore implements RunStoreInterface
{
    /** @var list<RunRecord> */
    public array $records = [];

    public function record(RunRecord $record): void
    {
        $this->records[] = $record;
    }

    public function recent(int $limit = 50): array
    {
        return $this->records;
    }
}
