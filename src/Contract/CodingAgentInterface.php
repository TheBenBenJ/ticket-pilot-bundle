<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Contract;

use TheBenBenJ\TicketPilotBundle\Model\AgentResult;

/**
 * A coding agent able to autonomously edit the working tree from a prompt
 * (Cursor CLI, Claude Code, Codex, ...).
 *
 * Implementations are registered as services tagged "ticket_pilot.agent" and
 * resolved by {@see \TheBenBenJ\TicketPilotBundle\Registry\AgentRegistry} via
 * {@see self::getName()}.
 */
interface CodingAgentInterface
{
    /**
     * Unique, lower-case identifier used to select this agent (e.g. "claude").
     */
    public function getName(): string;

    /**
     * Run the agent against the working tree.
     *
     * @param string|null                $model    Optional model override
     * @param callable(string):void|null $onOutput Streamed-output callback (live logging)
     */
    public function run(string $prompt, ?string $model = null, ?callable $onOutput = null): AgentResult;
}
