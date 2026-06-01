<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Quality;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Process\Process;
use TheBenBenJ\TicketPilotBundle\Contract\QualityGateInterface;
use TheBenBenJ\TicketPilotBundle\Contract\QualityReport;

/**
 * Runs a configurable list of shell commands (lint, static analysis, tests) and
 * fails on the first non-zero exit code, returning the combined output.
 */
final class CommandQualityGate implements QualityGateInterface
{
    private readonly LoggerInterface $logger;

    /**
     * @param array<string, list<string>> $commands Ordered map of label => argv (e.g. ['check' => ['make', 'check']])
     */
    public function __construct(
        private readonly string $projectDir,
        private readonly array $commands,
        private readonly int $timeout = 300,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function verify(): QualityReport
    {
        foreach ($this->commands as $label => $argv) {
            $this->logger->info(\sprintf('QualityGate: running "%s"', $label));

            $output = '';
            $process = new Process($argv, $this->projectDir);
            $process->setTimeout($this->timeout);
            $process->run(static function (string $type, string $buffer) use (&$output): void {
                $output .= $buffer;
            });

            if (!$process->isSuccessful()) {
                $this->logger->warning(\sprintf('QualityGate: "%s" failed (exit %d)', $label, (int) $process->getExitCode()));

                return new QualityReport(false, \sprintf("## %s errors\n%s", $label, $output));
            }
        }

        $this->logger->info('QualityGate: all checks passed');

        return new QualityReport(true);
    }
}
