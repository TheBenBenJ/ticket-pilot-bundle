<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Tests\Unit\Agent;

use PHPUnit\Framework\TestCase;
use TheBenBenJ\TicketPilotBundle\Agent\AgentModelCatalog;

final class AgentModelCatalogTest extends TestCase
{
    public function testClaudeMergesConfiguredAndDefaultModels(): void
    {
        $catalog = new AgentModelCatalog(['claude' => ['opus', 'sonnet']]);
        $models = $catalog->forAgent('claude');

        self::assertSame(['opus', 'sonnet'], \array_slice($models, 0, 2));
        self::assertContains('default', $models);
    }

    public function testCursorMergesConfiguredAndKnownModelsWhenLiveListIsEmpty(): void
    {
        $catalog = new AgentModelCatalog(['cursor' => ['custom-model']]);
        $models = $catalog->forAgent('cursor', '');

        self::assertContains('auto', $models);
        self::assertContains('custom-model', $models);
        self::assertContains('claude-4-sonnet', $models);
        self::assertSame($models, array_values(array_unique($models)));
    }

    public function testAllBuildsMapPerAgent(): void
    {
        $catalog = new AgentModelCatalog([
            'cursor' => ['auto'],
            'claude' => ['default', 'opus'],
        ]);

        $map = $catalog->all(['cursor', 'claude'], []);

        self::assertContains('auto', $map['cursor']);
        self::assertSame(['default', 'opus'], \array_slice($map['claude'], 0, 2));
    }
}
