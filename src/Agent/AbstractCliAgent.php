<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Agent;

use Symfony\Component\Process\Process;
use TheBenBenJ\TicketPilotBundle\Contract\CodingAgentInterface;
use TheBenBenJ\TicketPilotBundle\Model\AgentResult;

/**
 * Shared plumbing for agents driven through a local CLI process: build the
 * argument list, stream the output, return an {@see AgentResult}.
 */
abstract class AbstractCliAgent implements CodingAgentInterface
{
    public function __construct(
        protected readonly string $projectDir,
        protected readonly int $timeout = 3600,
    ) {
    }

    public function run(string $prompt, ?string $model = null, ?callable $onOutput = null): AgentResult
    {
        $process = $this->createProcess($prompt, $model);
        $process->setTimeout($this->timeout);

        $output = '';
        $process->run(static function (string $type, string $buffer) use (&$output, $onOutput): void {
            $output .= $buffer;
            if (null !== $onOutput) {
                $onOutput($buffer);
            }
        });

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(\sprintf('Agent "%s" failed: %s', $this->getName(), $process->getErrorOutput() ?: $output));
        }

        return new AgentResult(true, $output);
    }

    /**
     * Build the process that runs the agent against the working tree, feeding it
     * the given prompt.
     */
    abstract protected function createProcess(string $prompt, ?string $model): Process;
}
