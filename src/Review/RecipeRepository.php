<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Review;

use Symfony\Component\Yaml\Yaml;

/**
 * Loads the recipe the agent authored for a ticket, by key, from the recipes
 * directory (`<dir>/<key>.yaml|yml|json`).
 */
final class RecipeRepository
{
    public function __construct(
        private readonly string $recipesDir,
        private readonly RecipeFactory $factory,
    ) {
    }

    public function load(string $key): ?Recipe
    {
        foreach (['yaml', 'yml', 'json'] as $ext) {
            $path = $this->pathFor($key, $ext);
            if (!is_file($path)) {
                continue;
            }

            $raw = (string) file_get_contents($path);
            $data = 'json' === $ext ? json_decode($raw, true) : Yaml::parse($raw);
            if (!\is_array($data)) {
                throw new \RuntimeException(\sprintf('Recipe file "%s" is not a valid mapping', $path));
            }

            return $this->factory->fromArray($key, $data);
        }

        return null;
    }

    public function defaultPath(string $key): string
    {
        return $this->pathFor($key, 'yaml');
    }

    private function pathFor(string $key, string $ext): string
    {
        return \sprintf('%s/%s.%s', rtrim($this->recipesDir, '/'), $key, $ext);
    }
}
