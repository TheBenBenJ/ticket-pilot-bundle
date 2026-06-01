<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Agent;

use Symfony\Component\Process\Process;
use TheBenBenJ\TicketPilotBundle\Model\AgentResult;

/**
 * Drives the Claude Code CLI in headless mode.
 *
 * The prompt is written to a temporary file and passed via `-p "$(cat …)"` to
 * avoid argument-length and shell-escaping limits on large prompts.
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

    public function run(string $prompt, ?string $model = null, ?callable $onOutput = null): AgentResult
    {
        $promptFile = tempnam(sys_get_temp_dir(), 'ia_prompt_');
        if (false === $promptFile) {
            throw new \RuntimeException('Unable to create a temporary prompt file');
        }

        file_put_contents($promptFile, $prompt);

        try {
            return parent::run($promptFile, $model, $onOutput);
        } finally {
            @unlink($promptFile);
        }
    }

    protected function createProcess(string $promptFile, ?string $model): Process
    {
        $flags = $this->skipPermissions ? ' --dangerously-skip-permissions' : '';
        $command = \sprintf('%s%s -p "$(cat %s)"', $this->binary, $flags, escapeshellarg($promptFile));
        if (null !== $model && '' !== $model) {
            $command .= \sprintf(' --model %s', escapeshellarg($model));
        }

        return Process::fromShellCommandline($command, $this->projectDir);
    }
}
