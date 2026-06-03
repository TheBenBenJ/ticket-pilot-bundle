<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Agent;

use Symfony\Component\Process\Process;

/**
 * Drives the Claude Code CLI in headless print mode (`claude -p`), feeding the
 * prompt on stdin.
 *
 * The process is built as an argument list (no shell), so a malicious ticket can
 * never inject shell commands through the prompt, the binary name or the model.
 */
final class ClaudeAgent extends AbstractCliAgent
{
    public function __construct(
        string $projectDir,
        private readonly string $binary = 'claude',
        private readonly bool $skipPermissions = true,
        int $timeout = 3600,
    ) {
        parent::__construct($projectDir, $timeout);
    }

    public function getName(): string
    {
        return 'claude';
    }

    protected function createProcess(string $prompt, ?string $model): Process
    {
        $args = [$this->binary];
        if ($this->skipPermissions) {
            $args[] = '--dangerously-skip-permissions';
        }
        $args[] = '-p';
        if (null !== $model && '' !== $model) {
            $args[] = '--model';
            $args[] = $model;
        }

        // No shell: the prompt is fed on stdin, never interpolated into a command.
        $process = new Process($args, $this->projectDir);
        $process->setInput($prompt);

        return $process;
    }
}
