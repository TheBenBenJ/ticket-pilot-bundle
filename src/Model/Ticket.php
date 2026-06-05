<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Model;

/**
 * Immutable representation of a unit of work to be developed by the agent.
 *
 * A ticket is source-agnostic: it may come from Jira, Sentry, GitHub issues,
 * Linear, etc. Sources are responsible for mapping their payload onto this DTO.
 */
final readonly class Ticket
{
    /**
     * @param string           $key                Unique key (e.g. PROJ-1234, SENTRY-56789)
     * @param string           $title              Short summary
     * @param string           $description        Full description (plain text / markdown)
     * @param string           $type               Free-form type label as exposed by the source (Story, Bug, Task, ...)
     * @param string           $source             Source name that produced this ticket (e.g. "jira", "sentry")
     * @param list<string>     $fixVersions        Target fix versions, most relevant first
     * @param string           $acceptanceCriteria Acceptance criteria, when extractable
     * @param list<string>     $comments           Human-readable comments
     * @param list<string>     $subTasks           Keys of linked sub-tasks
     * @param string           $priority           Priority label as exposed by the source
     * @param list<string>     $components         Affected components
     * @param list<string>     $labels             Labels / tags
     * @param list<string>     $linkedTickets      Keys of linked tickets
     * @param string|null      $sprint             Active sprint name, if any
     * @param string|null      $assignee           Assignee display name
     * @param string|null      $reporter           Reporter display name
     * @param string|null      $url                Direct link to the source ticket
     * @param list<Attachment> $attachments        Files attached to the ticket
     */
    public function __construct(
        public string $key,
        public string $title,
        public string $description,
        public string $type,
        public string $source,
        public array $fixVersions = [],
        public string $acceptanceCriteria = '',
        public array $comments = [],
        public array $subTasks = [],
        public string $priority = 'Medium',
        public array $components = [],
        public array $labels = [],
        public array $linkedTickets = [],
        public ?string $sprint = null,
        public ?string $assignee = null,
        public ?string $reporter = null,
        public ?string $url = null,
        public array $attachments = [],
    ) {
    }

    /**
     * Whether the ticket describes a defect (bug / anomaly) rather than a feature.
     *
     * @param list<string> $bugTypes Lower-cased type labels considered as bugs
     */
    public function isBug(array $bugTypes = ['bug', 'anomalie', 'defect']): bool
    {
        return \in_array(mb_strtolower($this->type), $bugTypes, true);
    }

    public function isFromSource(string $source): bool
    {
        return $this->source === $source;
    }

    public function fixVersion(): ?string
    {
        return $this->fixVersions[0] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
            'description' => $this->description,
            'type' => $this->type,
            'source' => $this->source,
            'fix_versions' => $this->fixVersions,
            'acceptance_criteria' => $this->acceptanceCriteria,
            'priority' => $this->priority,
            'components' => $this->components,
            'labels' => $this->labels,
            'comments' => $this->comments,
            'sub_tasks' => $this->subTasks,
            'linked_tickets' => $this->linkedTickets,
            'sprint' => $this->sprint,
            'assignee' => $this->assignee,
            'reporter' => $this->reporter,
            'url' => $this->url,
            'attachments' => array_map(static fn (Attachment $a): string => $a->filename, $this->attachments),
        ];
    }
}
