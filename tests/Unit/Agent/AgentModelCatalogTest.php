<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Tests\Unit\Agent;

use PHPUnit\Framework\TestCase;
use TheBenBenJ\TicketPilotBundle\Agent\AgentModelCatalog;

final class AgentModelCatalogTest extends TestCase
{
    public function testClaudeUsesConfiguredModels(): void
    {
        $catalog = new AgentModelCatalog(['claude' => ['opus', 'sonnet']]);

        self::assertSame(['opus', 'sonnet'], $catalog->forAgent('claude'));
    }

    public function testCursorUsesConfiguredFallbackWhenBinaryMissing(): void
    {
        $catalog = new AgentModelCatalog(['cursor' => ['auto', 'gpt-4']]);

        self::assertSame(['auto', 'gpt-4'], $catalog->forAgent('cursor', ''));
    }

    public function testAllBuildsMapPerAgent(): void
    {
        $catalog = new AgentModelCatalog([
            'cursor' => ['auto'],
            'claude' => ['default', 'opus'],
        ]);

        self::assertSame([
            'cursor' => ['auto'],
            'claude' => ['default', 'opus'],
        ], $catalog->all(['cursor', 'claude'], []));
    }
}
