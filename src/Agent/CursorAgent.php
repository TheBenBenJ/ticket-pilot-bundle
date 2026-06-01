<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Agent;

use Symfony\Component\Process\Process;

/**
 * Drives the Cursor CLI ("agent") in non-interactive mode, feeding the prompt
 * on stdin.
 */
final class CursorAgent extends AbstractCliAgent
{
    public function __construct(
        string $projectDir,
        private readonly string $binary = 'agent',
        int $timeout = 3600,
    ) {
        parent::__construct($projectDir, $timeout);
    }

    public function getName(): string
    {
        return 'cursor';
    }

    protected function createProcess(string $prompt, ?string $model): Process
    {
        $args = [$this->binary, '-p', '--force'];
        if (null !== $model && '' !== $model) {
            $args[] = '--model';
            $args[] = $model;
        }

        $process = new Process($args, $this->projectDir);
        $process->setInput($prompt);

        return $process;
    }
}
