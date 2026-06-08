<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Review;

/**
 * Persists the functional scenario an agent executed during ia:review so it can
 * be replayed or refined on the next run ({@see RecipeRepository} for the
 * recipe driver).
 */
final class ScenarioRepository
{
    public function __construct(
        private readonly string $scenariosDir,
    ) {
    }

    /**
     * Writes the scenario for a ticket (overwrites any previous file).
     *
     * @return string Absolute path of the saved file
     */
    public function save(string $ticketKey, string $content): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $ticketKey) ?: 'ticket';
        $dir = rtrim($this->scenariosDir, '/');
        if (!is_dir($dir) && !@mkdir($dir, 0o777, true) && !is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Cannot create scenarios directory "%s"', $dir));
        }

        $path = $dir.'/'.$safe.'.md';
        if (false === file_put_contents($path, trim($content)."\n")) {
            throw new \RuntimeException(\sprintf('Cannot write scenario file "%s"', $path));
        }

        return $path;
    }

    public function pathFor(string $ticketKey): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $ticketKey) ?: 'ticket';

        return rtrim($this->scenariosDir, '/').'/'.$safe.'.md';
    }
}
