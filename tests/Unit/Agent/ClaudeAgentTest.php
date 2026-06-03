<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Tests\Unit\Agent;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use TheBenBenJ\TicketPilotBundle\Agent\ClaudeAgent;

final class ClaudeAgentTest extends TestCase
{
    public function testPromptIsPassedOnStdinNotInterpolatedIntoAShellCommand(): void
    {
        $agent = new ClaudeAgent('/tmp', 'claude', true);
        $malicious = 'fix it"; rm -rf / #';

        $process = $this->createProcess($agent, $malicious, 'sonnet');

        // The prompt travels on stdin — never on the command line — so it cannot
        // be interpreted by a shell (regression guard for the injection fix).
        self::assertSame($malicious, $process->getInput());
        self::assertStringNotContainsString('rm -rf', $process->getCommandLine());
    }

    public function testCommandIsArgvWithExpectedFlags(): void
    {
        $process = $this->createProcess(new ClaudeAgent('/tmp', 'claude', true), 'prompt', 'sonnet');
        $cmd = $process->getCommandLine();

        self::assertStringContainsString('claude', $cmd);
        self::assertStringContainsString('--dangerously-skip-permissions', $cmd);
        self::assertStringContainsString('-p', $cmd);
        self::assertStringContainsString('--model', $cmd);
    }

    public function testSkipPermissionsDisabledOmitsTheFlag(): void
    {
        $process = $this->createProcess(new ClaudeAgent('/tmp', 'claude', false), 'prompt', null);

        self::assertStringNotContainsString('--dangerously-skip-permissions', $process->getCommandLine());
    }

    private function createProcess(ClaudeAgent $agent, string $prompt, ?string $model): Process
    {
        $method = new \ReflectionMethod($agent, 'createProcess');

        return $method->invoke($agent, $prompt, $model);
    }
}
