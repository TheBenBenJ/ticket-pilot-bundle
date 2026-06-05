<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Tests\Unit\Review;

use PHPUnit\Framework\TestCase;
use TheBenBenJ\TicketPilotBundle\Review\RecipeFactory;
use TheBenBenJ\TicketPilotBundle\Review\RecipeStep;

final class RecipeFactoryTest extends TestCase
{
    public function testBuildsRecipeFromArray(): void
    {
        $recipe = (new RecipeFactory())->fromArray('LYSI-2098', [
            'description' => 'Check payment delay',
            'steps' => [
                ['action' => 'visit', 'target' => '/admin/facture/1'],
                ['action' => 'assert_see', 'value' => 'Délai'],
            ],
        ]);

        self::assertSame('LYSI-2098', $recipe->key);
        self::assertSame('Check payment delay', $recipe->description);
        self::assertCount(2, $recipe->steps);
        self::assertSame(RecipeStep::VISIT, $recipe->steps[0]->action);
        self::assertSame('/admin/facture/1', $recipe->steps[0]->target);
        self::assertSame('Délai', $recipe->steps[1]->value);
    }

    public function testRejectsUnknownAction(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new RecipeFactory())->fromArray('K', ['steps' => [['action' => 'teleport']]]);
    }

    public function testRejectsEmptyRecipe(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new RecipeFactory())->fromArray('K', ['steps' => []]);
    }
}
