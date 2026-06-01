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
 * GitHub implementation of the VCS contracts (REST API v3).
 *
 * A "merge request" maps to a pull request; triggering a pipeline maps to a
 * `repository_dispatch` event carrying the auto-dev variables as client payload,
 * which a GitHub Actions workflow can react to.
 */
final class GitHubProvider implements VcsProviderInterface, PipelineTriggerInterface
{
    private readonly HttpClientInterface $client;
    private readonly LoggerInterface $logger;
    private readonly string $owner;
    private readonly string $repo;
    private readonly string $htmlBaseUri;

    public function __construct(
        string $baseUri,
        string $token,
        string $repository,
        private readonly string $dispatchEventType,
        HttpClientInterface $httpClient,
        ?LoggerInterface $logger = null,
        string $htmlBaseUri = 'https://github.com',
    ) {
        [$this->owner, $this->repo] = $this->splitRepository($repository);
        $this->htmlBaseUri = rtrim($htmlBaseUri, '/');
        $this->client = $httpClient->withOptions([
            'base_uri' => rtrim($baseUri, '/').'/',
            'auth_bearer' => $token,
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
            ],
        ]);
        $this->logger = $logger ?? new NullLogger();
    }

    public function createMergeRequest(
        string $sourceBranch,
        string $targetBranch,
        string $title,
        string $description,
    ): MergeRequest {
        try {
            $data = $this->client->request(
                'POST',
                \sprintf('repos/%s/%s/pulls', $this->owner, $this->repo),
                ['json' => [
                    'title' => $title,
                    'head' => $sourceBranch,
                    'base' => $targetBranch,
                    'body' => $description,
                ]],
            )->toArray();

            $this->logger->info(\sprintf('GitHub PR #%d created: %s', $data['number'], $data['html_url']));

            return new MergeRequest((int) $data['number'], $data['html_url']);
        } catch (HttpExceptionInterface $e) {
            $this->logger->error(\sprintf('createMergeRequest %s -> %s failed: %s', $sourceBranch, $targetBranch, $e->getMessage()));

            throw new \RuntimeException('Unable to create the pull request', 0, $e);
        }
    }

    public function remoteBranchExists(string $branch): bool
    {
        try {
            $status = $this->client->request(
                'GET',
                \sprintf('repos/%s/%s/branches/%s', $this->owner, $this->repo, rawurlencode($branch)),
            )->getStatusCode();

            return $status >= 200 && $status < 300;
        } catch (HttpExceptionInterface) {
            return false;
        }
    }

    public function triggerPipeline(string $ref, array $variables): Pipeline
    {
        try {
            $status = $this->client->request(
                'POST',
                \sprintf('repos/%s/%s/dispatches', $this->owner, $this->repo),
                ['json' => [
                    'event_type' => $this->dispatchEventType,
                    'client_payload' => ['ref' => $ref] + $variables,
                ]],
            )->getStatusCode();

            if ($status >= 300) {
                throw new \RuntimeException(\sprintf('GitHub dispatch returned HTTP %d', $status));
            }

            $actionsUrl = \sprintf('%s/%s/%s/actions', $this->htmlBaseUri, $this->owner, $this->repo);
            $this->logger->info(\sprintf('GitHub repository_dispatch "%s" sent for ref %s', $this->dispatchEventType, $ref));

            // repository_dispatch returns 204 with no body: there is no pipeline id
            // to report, so we surface the Actions page and a "dispatched" status.
            return new Pipeline(0, $actionsUrl, 'dispatched');
        } catch (HttpExceptionInterface $e) {
            $this->logger->error('triggerPipeline failed: '.$e->getMessage());

            throw new \RuntimeException('Unable to dispatch the GitHub Actions workflow', 0, $e);
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitRepository(string $repository): array
    {
        $parts = explode('/', trim($repository, '/'));
        if (2 !== \count($parts) || '' === $parts[0] || '' === $parts[1]) {
            throw new \InvalidArgumentException(\sprintf('GitHub repository must be "owner/repo", got "%s"', $repository));
        }

        return [$parts[0], $parts[1]];
    }
}
