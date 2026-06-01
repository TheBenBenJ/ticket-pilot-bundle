<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Prompt;

use TheBenBenJ\TicketPilotBundle\Contract\PromptBuilderInterface;
use TheBenBenJ\TicketPilotBundle\Model\Ticket;

/**
 * Builds an autonomous-execution prompt from a ticket.
 *
 * The prompt instructs the agent to run headless (no questions, no plan
 * validation), to respect the project conventions, to run the configured
 * quality commands before finishing, and to emit a merge-request summary
 * delimited by markers so it can be extracted into the MR body.
 *
 * Everything project-specific (language, quality commands, summary markers,
 * extra instructions) is configurable, so the same builder serves any project.
 */
final class DefaultPromptBuilder implements PromptBuilderInterface
{
    /**
     * @param list<string> $qualityCommands Commands the agent must run before finishing (e.g. ['make check'])
     */
    public function __construct(
        private readonly string $language = 'English',
        private readonly array $qualityCommands = ['make check', 'make test'],
        private readonly string $summaryStartMarker = '<<<MR_SUMMARY',
        private readonly string $summaryEndMarker = 'MR_SUMMARY>>>',
        private readonly string $extraInstructions = '',
    ) {
    }

    public function build(Ticket $ticket): string
    {
        $parts = [$this->preamble()];

        $parts[] = \sprintf("You must implement ticket %s: %s\n", $ticket->key, $ticket->title);

        if ('' !== $ticket->description) {
            $parts[] = \sprintf("## Description\n%s\n", $ticket->description);
        }
        if ('' !== $ticket->acceptanceCriteria) {
            $parts[] = \sprintf("## Acceptance criteria\n%s\n", $ticket->acceptanceCriteria);
        }
        if ([] !== $ticket->comments) {
            $parts[] = "## Ticket comments\n".implode("\n", array_map(static fn (string $c): string => '- '.$c, $ticket->comments))."\n";
        }
        if ([] !== $ticket->subTasks) {
            $parts[] = \sprintf("## Linked sub-tasks\n%s\n", implode(', ', $ticket->subTasks));
        }
        if ([] !== $ticket->components) {
            $parts[] = \sprintf("## Affected components\n%s\n", implode(', ', $ticket->components));
        }

        $parts[] = $this->instructions();

        return implode("\n", array_filter($parts, static fn (string $p): bool => '' !== trim($p)));
    }

    private function preamble(): string
    {
        return <<<PROMPT
            # AUTONOMOUS EXECUTION MODE — READ FIRST

            You are running in a CI pipeline with no human at the keyboard. Any question
            you ask will never be read nor answered: your process will be terminated.

            ABSOLUTE RULES:
            - All of your text output MUST be in {$this->language}. No other language in your
              messages, code comments, test names or summaries.
            - Do NOT expose a plan for validation ("Here is my plan, may I proceed?").
            - Do NOT ask for confirmation ("Should I continue?", "Do you want me to…").
            - Do NOT use meta-commentary ("Great!", "Perfect!", "Let me…", "All tests pass").
              Be factual and direct.
            - Edit the real files in the repository. No simulation, no pseudo-code in the
              answer — real code in the real files.
            - When several options are equivalent, pick the simplest/most conservative one
              and move on.
            PROMPT;
    }

    private function instructions(): string
    {
        $checks = '';
        foreach ($this->qualityCommands as $i => $command) {
            $checks .= \sprintf("%d. `%s`\n", $i + 1, $command);
        }
        $checks = rtrim($checks);

        $extra = '' !== trim($this->extraInstructions)
            ? "\n\n## Project conventions\n".trim($this->extraInstructions)
            : '';

        return <<<PROMPT

            ## Guidelines{$extra}

            ## Quality verification (MANDATORY before finishing)
            You MUST run the following commands yourself and fix the errors that concern the
            files you modified. Iterate until your scope is clean. Ignore out-of-scope errors
            (files or domains you did not touch) but mention them briefly in your summary.

            {$checks}

            ## Merge request summary (MANDATORY at the end)
            End your answer with a summary block delimited EXACTLY by the markers below. Its
            content is inserted verbatim into the merge request — keep it clean, in
            {$this->language}, factual, with no meta-commentary.

            {$this->summaryStartMarker}
            ### Problem
            <1 to 3 sentences describing the bug or request>

            ### Solution
            <2 to 5 bullets describing the changes made>

            ### Changed files
            - `path/to/File.php`: <what changed>

            ### Tests
            <bullets describing the tests added or changed, or "No new test (reason)">
            {$this->summaryEndMarker}

            Write NOTHING after the end marker `{$this->summaryEndMarker}`.
            PROMPT;
    }
}
