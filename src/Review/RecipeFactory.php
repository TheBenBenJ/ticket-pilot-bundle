<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Review;

/**
 * Builds a {@see Recipe} from the decoded YAML/JSON the agent authored.
 */
final class RecipeFactory
{
    /**
     * @param array<string, mixed> $data
     *
     * @throws \InvalidArgumentException on a malformed recipe or unknown action
     */
    public function fromArray(string $key, array $data): Recipe
    {
        $steps = [];
        foreach ($data['steps'] ?? [] as $i => $raw) {
            if (!\is_array($raw) || !isset($raw['action'])) {
                throw new \InvalidArgumentException(\sprintf('Recipe step #%d is missing an "action"', $i));
            }

            $action = (string) $raw['action'];
            if (!\in_array($action, RecipeStep::ACTIONS, true)) {
                throw new \InvalidArgumentException(\sprintf('Unknown recipe action "%s" (allowed: %s)', $action, implode(', ', RecipeStep::ACTIONS)));
            }

            $steps[] = new RecipeStep(
                $action,
                isset($raw['target']) ? (string) $raw['target'] : null,
                isset($raw['value']) ? (string) $raw['value'] : null,
            );
        }

        if ([] === $steps) {
            throw new \InvalidArgumentException('Recipe has no steps');
        }

        return new Recipe($key, (string) ($data['description'] ?? ''), $steps);
    }
}
