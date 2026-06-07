<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Run;

/**
 * Immutable record of one pipeline activity (a development, an iteration or a
 * review) launched by the bundle, persisted so the CLI and the dashboard can
 * list what the bundle has done.
 */
final readonly class RunRecord
{
    public const TYPE_AUTO_DEV = 'auto-dev';
    public const TYPE_ITERATE = 'iterate';
    public const TYPE_REVIEW = 'review';

    public const STATUS_QUEUED = 'queued';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_PASSED = 'passed';
    public const STATUS_INCONCLUSIVE = 'inconclusive';

    /**
     * @param list<string> $screenshots Names (or URLs) of screenshots produced by a review
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $ticketKey,
        public string $status,
        public string $startedAt,
        public string $branch = '',
        public string $summary = '',
        public string $url = '',
        public string $agent = '',
        public string $source = '',
        public float $duration = 0.0,
        public array $screenshots = [],
    ) {
    }

    /**
     * Builds a record, generating the id and the timestamp.
     *
     * @param list<string> $screenshots
     */
    public static function create(
        string $type,
        string $ticketKey,
        string $status,
        string $branch = '',
        string $summary = '',
        string $url = '',
        string $agent = '',
        string $source = '',
        float $duration = 0.0,
        array $screenshots = [],
    ): self {
        return new self(
            bin2hex(random_bytes(6)),
            $type,
            $ticketKey,
            $status,
            (new \DateTimeImmutable())->format(\DATE_ATOM),
            $branch,
            $summary,
            $url,
            $agent,
            $source,
            $duration,
            $screenshots,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'ticketKey' => $this->ticketKey,
            'status' => $this->status,
            'startedAt' => $this->startedAt,
            'branch' => $this->branch,
            'summary' => $this->summary,
            'url' => $this->url,
            'agent' => $this->agent,
            'source' => $this->source,
            'duration' => $this->duration,
            'screenshots' => $this->screenshots,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['id'] ?? ''),
            (string) ($data['type'] ?? ''),
            (string) ($data['ticketKey'] ?? ''),
            (string) ($data['status'] ?? ''),
            (string) ($data['startedAt'] ?? ''),
            (string) ($data['branch'] ?? ''),
            (string) ($data['summary'] ?? ''),
            (string) ($data['url'] ?? ''),
            (string) ($data['agent'] ?? ''),
            (string) ($data['source'] ?? ''),
            (float) ($data['duration'] ?? 0.0),
            array_values(array_filter(array_map('strval', (array) ($data['screenshots'] ?? [])), static fn (string $s): bool => '' !== $s)),
        );
    }
}
