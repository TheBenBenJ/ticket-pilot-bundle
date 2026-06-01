<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Vcs;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use TheBenBenJ\TicketPilotBundle\Contract\PipelineTriggerInterface;
use TheBenBenJ\TicketPilotBundle\Contract\VcsProviderInterface;
use TheBenBenJ\TicketPilotBundle\Model\MergeRequest;
use TheBenBenJ\TicketPilotBundle\Model\Pipeline;

/**
 * GitLab implementation of the VCS contracts (REST API v4).
 */
final class GitlabProvider implements VcsProviderInterface, PipelineTriggerInterface
{
    private readonly HttpClientInterface $client;
    private readonly LoggerInterface $logger;
    private ?int $projectId = null;

    public function __construct(
        string $baseUri,
        private readonly string $token,
        private readonly string $projectPath,
        HttpClientInterface $httpClient,
        ?LoggerInterface $logger = null,
    ) {
        $this->client = $httpClient->withOptions([
            'base_uri' => rtrim($baseUri, '/').'/',
            'headers' => ['PRIVATE-TOKEN' => $this->token],
        ]);
        $this->logger = $logger ?? new NullLogger();
    }

    public function createMergeRequest(
        string $sourceBranch,
        string $targetBranch,
        string $title,
        string $description,
        bool $draft = false,
    ): MergeRequest {
        try {
            $data = $this->client->request(
                'POST',
                \sprintf('api/v4/projects/%d/merge_requests', $this->projectId()),
                ['json' => [
                    'source_branch' => $sourceBranch,
                    'target_branch' => $targetBranch,
                    // GitLab marks a merge request as draft from the "Draft:" title prefix.
                    'title' => $draft ? 'Draft: '.$title : $title,
                    'description' => $description,
                    'remove_source_branch' => true,
                ]],
            )->toArray();

            $this->logger->info(\sprintf('GitLab MR !%d created: %s', $data['iid'], $data['web_url']));

            return new MergeRequest((int) $data['iid'], $data['web_url']);
        } catch (HttpExceptionInterface $e) {
            $this->logger->error(\sprintf('createMergeRequest %s -> %s failed: %s', $sourceBranch, $targetBranch, $e->getMessage()));

            throw new \RuntimeException('Unable to create the merge request', 0, $e);
        }
    }

    public function remoteBranchExists(string $branch): bool
    {
        try {
            $status = $this->client->request(
                'GET',
                \sprintf('api/v4/projects/%d/repository/branches/%s', $this->projectId(), rawurlencode($branch)),
            )->getStatusCode();

            return $status >= 200 && $status < 300;
        } catch (HttpExceptionInterface) {
            return false;
        }
    }

    public function triggerPipeline(string $ref, array $variables): Pipeline
    {
        $payload = [];
        foreach ($variables as $key => $value) {
            $payload[] = ['key' => $key, 'value' => (string) $value];
        }

        try {
            $data = $this->client->request(
                'POST',
                \sprintf('api/v4/projects/%s/pipeline', rawurlencode($this->projectPath)),
                ['json' => ['ref' => $ref, 'variables' => $payload]],
            )->toArray();

            $this->logger->info(\sprintf('GitLab pipeline #%d triggered on %s', $data['id'], $ref));

            return new Pipeline((int) $data['id'], $data['web_url'], $data['status']);
        } catch (HttpExceptionInterface $e) {
            $this->logger->error('triggerPipeline failed: '.$e->getMessage());

            throw new \RuntimeException('Unable to trigger the GitLab pipeline', 0, $e);
        }
    }

    private function projectId(): int
    {
        if (null !== $this->projectId) {
            return $this->projectId;
        }

        try {
            $data = $this->client->request(
                'GET',
                \sprintf('api/v4/projects/%s', rawurlencode($this->projectPath)),
            )->toArray();

            return $this->projectId = (int) $data['id'];
        } catch (HttpExceptionInterface $e) {
            throw new \RuntimeException('Unable to resolve the GitLab project id', 0, $e);
        }
    }
}
