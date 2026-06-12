<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use TheBenBenJ\TicketPilotBundle\Controller\RunIngestController;
use TheBenBenJ\TicketPilotBundle\Run\RunRecord;
use TheBenBenJ\TicketPilotBundle\Run\RunScenarioPersister;
use TheBenBenJ\TicketPilotBundle\Run\RunScreenshotPersister;
use TheBenBenJ\TicketPilotBundle\Run\RunStoreInterface;

final class RunIngestControllerTest extends TestCase
{
    private function request(string $token, string $body): Request
    {
        $request = Request::create('/ia/runs', 'POST', [], [], [], [], $body);
        if ('' !== $token) {
            $request->headers->set('X-Ticket-Pilot-Token', $token);
        }

        return $request;
    }

    public function testValidTokenAndPayloadRecordsTheRun(): void
    {
        $store = new CapturingStore();
        $controller = new RunIngestController($store, 'secret', new RunScreenshotPersister('', ''), new RunScenarioPersister('', ''));

        $body = (string) json_encode((new RunRecord('1', 'iterate', 'P-7', 'success', '2026-01-01T10:00:00+00:00'))->toArray());
        $response = $controller($this->request('secret', $body));

        self::assertSame(201, $response->getStatusCode());
        self::assertCount(1, $store->records);
        self::assertSame('P-7', $store->records[0]->ticketKey);
    }

    public function testWrongTokenIsRejected(): void
    {
        $store = new CapturingStore();
        $response = (new RunIngestController($store, 'secret', new RunScreenshotPersister('', ''), new RunScenarioPersister('', '')))($this->request('nope', '{"type":"review","ticketKey":"P-1"}'));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame([], $store->records);
    }

    public function testEmptyConfiguredTokenDisablesIngestion(): void
    {
        $response = (new RunIngestController(new CapturingStore(), '', new RunScreenshotPersister('', ''), new RunScenarioPersister('', '')))($this->request('anything', '{"type":"review","ticketKey":"P-1"}'));

        self::assertSame(401, $response->getStatusCode());
    }

    public function testInvalidPayloadIsRejected(): void
    {
        $response = (new RunIngestController(new CapturingStore(), 'secret', new RunScreenshotPersister('', ''), new RunScenarioPersister('', '')))($this->request('secret', 'not json'));

        self::assertSame(400, $response->getStatusCode());
    }

    public function testFilesAreSavedAndScreenshotsBecomeUrls(): void
    {
        $dir = sys_get_temp_dir().'/tpb-ingest-'.bin2hex(random_bytes(4));
        $store = new CapturingStore();
        $controller = new RunIngestController($store, 'secret', new RunScreenshotPersister($dir, '/ticket-pilot/screenshots'), new RunScenarioPersister('', ''));

        $payload = json_encode([
            'id' => 'abc123',
            'type' => 'review',
            'ticketKey' => 'PROJ-1',
            'status' => 'passed',
            '_files' => [['name' => 'home.png', 'data' => base64_encode('PNGBYTES')]],
        ]);
        $response = $controller($this->request('secret', (string) $payload));

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(['/ticket-pilot/screenshots/abc123/home.png'], $store->records[0]->screenshots);
        self::assertFileExists($dir.'/abc123/home.png');
        self::assertSame('PNGBYTES', file_get_contents($dir.'/abc123/home.png'));

        unlink($dir.'/abc123/home.png');
        @rmdir($dir.'/abc123');
        @rmdir($dir);
    }

    public function testFileDataAsDataUriOrWhitespaceIsToleratedAndSaved(): void
    {
        $dir = sys_get_temp_dir().'/tpb-ingest-'.bin2hex(random_bytes(4));
        $store = new CapturingStore();
        $controller = new RunIngestController($store, 'secret', new RunScreenshotPersister($dir, '/ticket-pilot/screenshots'), new RunScenarioPersister('', ''));

        $payload = json_encode([
            'id' => 'tol01',
            'type' => 'review',
            'ticketKey' => 'PROJ-1',
            'status' => 'passed',
            '_files' => [
                // data: URI prefix (rejected by strict base64_decode → used to create empty dirs)
                ['name' => 'a.png', 'data' => 'data:image/png;base64,'.base64_encode('AAA')],
                // base64 with embedded newlines/whitespace
                ['name' => 'b.png', 'data' => chunk_split(base64_encode('BBBB'), 4, "\n")],
            ],
        ]);
        $response = $controller($this->request('secret', (string) $payload));

        self::assertSame(201, $response->getStatusCode());
        self::assertSame([
            '/ticket-pilot/screenshots/tol01/a.png',
            '/ticket-pilot/screenshots/tol01/b.png',
        ], $store->records[0]->screenshots);
        self::assertSame('AAA', file_get_contents($dir.'/tol01/a.png'));
        self::assertSame('BBBB', file_get_contents($dir.'/tol01/b.png'));

        unlink($dir.'/tol01/a.png');
        unlink($dir.'/tol01/b.png');
        @rmdir($dir.'/tol01');
        @rmdir($dir);
    }

    public function testDataUriScreenshotsAreSavedAndBecomeUrls(): void
    {
        $dir = sys_get_temp_dir().'/tpb-ingest-'.bin2hex(random_bytes(4));
        $store = new CapturingStore();
        $controller = new RunIngestController($store, 'secret', new RunScreenshotPersister($dir, '/ticket-pilot/screenshots'), new RunScenarioPersister('', ''));

        $data = 'data:image/png;base64,'.base64_encode('PNGBYTES');
        $payload = json_encode([
            'id' => 'abc123',
            'type' => 'review',
            'ticketKey' => 'PROJ-1',
            'status' => 'passed',
            'screenshots' => [$data],
        ]);
        $response = $controller($this->request('secret', (string) $payload));

        self::assertSame(201, $response->getStatusCode());
        self::assertCount(1, $store->records[0]->screenshots);
        self::assertStringStartsWith('/ticket-pilot/screenshots/abc123/', $store->records[0]->screenshots[0]);

        @unlink($dir.'/abc123/screenshot.png');
        @rmdir($dir.'/abc123');
        @rmdir($dir);
    }

    public function testFallbackKeepsScreenshotNamesWhenSaveFails(): void
    {
        // A regular file where a directory is expected: mkdir must fail even as root.
        $blocked = sys_get_temp_dir().'/tpb-ingest-blocked-'.bin2hex(random_bytes(4));
        file_put_contents($blocked, 'not a directory');

        $store = new CapturingStore();
        $controller = new RunIngestController($store, 'secret', new RunScreenshotPersister($blocked, '/shots'), new RunScenarioPersister('', ''));

        $payload = json_encode([
            'id' => 'abc123',
            'type' => 'review',
            'ticketKey' => 'PROJ-1',
            'status' => 'passed',
            'screenshots' => ['home.png'],
            '_files' => [['name' => 'home.png', 'data' => base64_encode('PNGBYTES')]],
        ]);
        $response = $controller($this->request('secret', (string) $payload));

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(['home.png'], $store->records[0]->screenshots);

        unlink($blocked);
    }

    public function testScenarioIsSavedAndUrlStoredInRun(): void
    {
        $dir = sys_get_temp_dir().'/tpb-ingest-scenario-'.bin2hex(random_bytes(4));
        $store = new CapturingStore();
        $controller = new RunIngestController(
            $store,
            'secret',
            new RunScreenshotPersister('', ''),
            new RunScenarioPersister($dir, '/ticket-pilot/scenarios'),
        );

        $payload = json_encode([
            'id' => 'rev01',
            'type' => 'review',
            'ticketKey' => 'LYSI-99',
            'status' => 'passed',
            'scenario' => "# Scénario\n1. Ouvrir l'app",
        ]);
        $response = $controller($this->request('secret', (string) $payload));

        self::assertSame(201, $response->getStatusCode());
        self::assertSame("# Scénario\n1. Ouvrir l'app", $store->records[0]->scenario);
        self::assertSame('/ticket-pilot/scenarios/LYSI-99.md', $store->records[0]->scenarioUrl);
        self::assertFileExists($dir.'/LYSI-99.md');

        @unlink($dir.'/LYSI-99.md');
        @rmdir($dir);
    }
}

final class CapturingStore implements RunStoreInterface
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
