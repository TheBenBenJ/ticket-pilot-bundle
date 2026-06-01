<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Service;

use TheBenBenJ\TicketPilotBundle\Model\Ticket;

/**
 * Builds the title, body and commit message for a ticket's merge request,
 * optionally folding in the agent-produced summary block.
 */
final class MergeRequestFactory
{
    private const SUMMARY_MAX_CHARS = 4000;
    private const DESCRIPTION_MAX_CHARS = 500;

    public function __construct(
        private readonly string $summaryStartMarker = '<<<MR_SUMMARY',
        private readonly string $summaryEndMarker = 'MR_SUMMARY>>>',
        private readonly string $commitMessageTemplate = '[{key}] {title}',
    ) {
    }

    public function title(Ticket $ticket): string
    {
        return \sprintf('%s - %s', $ticket->key, $ticket->title);
    }

    public function commitMessage(Ticket $ticket): string
    {
        return strtr($this->commitMessageTemplate, [
            '{key}' => $ticket->key,
            '{title}' => $ticket->title,
        ]);
    }

    public function description(Ticket $ticket, ?string $agentOutput = null): string
    {
        $summary = $this->summarizeDescription($ticket->description);

        $info = \sprintf("- **Type**: %s\n- **Priority**: %s\n- **Source**: %s", $ticket->type, $ticket->priority, $ticket->source);
        if ([] !== $ticket->components) {
            $info .= \sprintf("\n- **Components**: %s", implode(', ', $ticket->components));
        }
        if (null !== $ticket->sprint) {
            $info .= \sprintf("\n- **Sprint**: %s", $ticket->sprint);
        }

        if (null !== $agentOutput) {
            $agentSummary = $this->extractAgentSummary($agentOutput);
            if ('' !== $agentSummary) {
                $info .= "\n\n### Implementation details\n\n".$agentSummary;
            }
        }

        $issueLink = $ticket->url ?? $ticket->key;

        return <<<MD
            ### Description
            {$summary}

            ### Changes Made
            {$info}

            ### Related Issues
            {$issueLink}

            ### Merge Request Checklist
            - [ ] Code follows project coding guidelines.
            - [ ] Documentation reflects the changes made.
            - [ ] Unit testing is covered.
            MD;
    }

    /**
     * Extracts the summary delimited by the configured markers. Returns an empty
     * string when the markers are absent, so agent chatter never leaks into the MR.
     */
    private function extractAgentSummary(string $output): string
    {
        $pattern = \sprintf(
            '/%s\s*(.+?)\s*%s/s',
            preg_quote($this->summaryStartMarker, '/'),
            preg_quote($this->summaryEndMarker, '/'),
        );

        if (1 !== preg_match($pattern, $output, $matches)) {
            return '';
        }

        $summary = trim($matches[1]);

        if (mb_strlen($summary) <= self::SUMMARY_MAX_CHARS) {
            return $summary;
        }

        return mb_substr($summary, 0, self::SUMMARY_MAX_CHARS - 20)."\n\n[...]";
    }

    private function summarizeDescription(string $description): string
    {
        $summary = '';
        foreach (preg_split('/\n{2,}/', trim($description)) ?: [] as $paragraph) {
            $paragraph = trim($paragraph);
            if ('' === $paragraph) {
                continue;
            }

            $summary .= $paragraph."\n\n";
            if (mb_strlen($summary) >= 300) {
                break;
            }
        }

        $summary = trim($summary);

        if (mb_strlen($summary) > self::DESCRIPTION_MAX_CHARS) {
            $summary = mb_substr($summary, 0, self::DESCRIPTION_MAX_CHARS - 3).'...';
        }

        return $summary;
    }
}
