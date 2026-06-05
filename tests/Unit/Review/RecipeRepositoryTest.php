<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Tests\Unit\Review;

use PHPUnit\Framework\TestCase;
use TheBenBenJ\TicketPilotBundle\Review\RecipeFactory;
use TheBenBenJ\TicketPilotBundle\Review\RecipeRepository;
use TheBenBenJ\TicketPilotBundle\Review\RecipeStep;

final class RecipeRepositoryTest extends TestCase
{
    public function testLoadsAYamlRecipeByKey(): void
    {
        $dir = sys_get_temp_dir().'/tpb_recipes_'.uniqid();
        mkdir($dir, 0o777, true);
        file_put_contents($dir.'/LYSI-2098.yaml', <<<YAML
            description: Check payment delay
            steps:
                - { action: visit, target: /admin/facture/1 }
                - { action: assert_see, value: "Délai" }
            YAML);

        try {
            $recipe = (new RecipeRepository($dir, new RecipeFactory()))->load('LYSI-2098');

            self::assertNotNull($recipe);
            self::assertCount(2, $recipe->steps);
            self::assertSame(RecipeStep::VISIT, $recipe->steps[0]->action);
        } finally {
            @unlink($dir.'/LYSI-2098.yaml');
            @rmdir($dir);
        }
    }

    public function testReturnsNullWhenNoRecipeExists(): void
    {
        self::assertNull((new RecipeRepository(sys_get_temp_dir(), new RecipeFactory()))->load('NOPE-404'));
    }
}
