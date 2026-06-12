<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Agent;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Process\Process;

/**
 * Lists the models each coding-agent CLI accepts (--model), for the dashboard
 * launch forms.
 */
final class AgentModelCatalog
{
    /** @var list<string> */
    private const CLAUDE_DEFAULT = [
        'default',
        'best',
        'opus',
        'sonnet',
        'haiku',
        'opusplan',
        'sonnet[1m]',
        'opus[1m]',
    ];

    /** @var list<string> */
    private const CURSOR_KNOWN = [
        'auto',
        'composer-1',
        'claude-4-sonnet',
        'claude-4-sonnet-thinking',
        'claude-4-opus',
        'claude-4-opus-thinking',
        'gpt-4o',
        'gpt-4.1',
        'o3',
        'o4-mini',
        'gemini-2.5-pro',
        'gemini-2.5-flash',
    ];

    private readonly LoggerInterface $logger;

    /**
     * @param array<string, list<string>> $configured Per-agent model lists from config (fallback)
     */
    public function __construct(
        private readonly array $configured = [],
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @param list<string>          $agentNames
     * @param array<string, string> $agentBinaries agent name => binary
     *
     * @return array<string, list<string>> agent name => model ids
     */
    public function all(array $agentNames, array $agentBinaries = []): array
    {
        $map = [];
        foreach ($agentNames as $name) {
            $map[$name] = $this->forAgent($name, (string) ($agentBinaries[$name] ?? ''));
        }

        return $map;
    }

    /**
     * @return list<string>
     */
    public function forAgent(string $agent, string $binary = ''): array
    {
        if ('cursor' === $agent) {
            return $this->mergeUnique(
                $this->cursorModels($binary),
                $this->configured['cursor'] ?? [],
                self::CURSOR_KNOWN,
            );
        }

        if ('claude' === $agent) {
            return $this->mergeUnique($this->configured['claude'] ?? [], self::CLAUDE_DEFAULT);
        }

        return $this->configured[$agent] ?? [];
    }

    /**
     * @param list<string> ...$lists
     *
     * @return list<string>
     */
    private function mergeUnique(array ...$lists): array
    {
        $merged = [];
        foreach ($lists as $list) {
            foreach ($list as $item) {
                if ('' !== $item && !\in_array($item, $merged, true)) {
                    $merged[] = $item;
                }
            }
        }

        return $merged;
    }

    /**
     * @return list<string>
     */
    private function cursorModels(string $binary): array
    {
        if ('' === $binary) {
            $binary = 'agent';
        }

        foreach ([
            [$binary, 'models', '--print', '--output-format', 'json'],
            [$binary, 'models'],
            [$binary, '--list-models', '--print', '--output-format', 'json'],
            [$binary, '--list-models'],
        ] as $command) {
            $models = $this->runCursorList($command);
            if ([] !== $models) {
                return $models;
            }
        }

        return [];
    }

    /**
     * @param list<string> $command
     *
     * @return list<string>
     */
    private function runCursorList(array $command): array
    {
        $process = new Process($command);
        $process->setTimeout(15.0);

        try {
            $process->run();
        } catch (\Throwable $e) {
            $this->logger->debug('AgentModelCatalog: cursor models command failed: '.$e->getMessage());

            return [];
        }

        if (!$process->isSuccessful()) {
            return [];
        }

        $output = trim($process->getOutput());
        if ('' === $output) {
            return [];
        }

        $json = json_decode($output, true);
        if (\is_array($json)) {
            return $this->parseCursorJson($json);
        }

        return $this->parseCursorText($output);
    }

    /**
     * @param array<mixed> $json
     *
     * @return list<string>
     */
    private function parseCursorJson(array $json): array
    {
        $models = [];
        $candidates = $json['models'] ?? $json;
        if (!\is_array($candidates)) {
            return [];
        }
        foreach ($candidates as $entry) {
            if (\is_string($entry) && '' !== $entry) {
                $models[] = $entry;
                continue;
            }
            if (\is_array($entry)) {
                $id = $entry['id'] ?? $entry['model'] ?? $entry['name'] ?? null;
                if (\is_string($id) && '' !== $id) {
                    $models[] = $id;
                }
            }
        }

        return array_values(array_unique($models));
    }

    /**
     * @return list<string>
     */
    private function parseCursorText(string $output): array
    {
        $models = [];
        foreach (preg_split('/\r\n|\r|\n/', $output) ?: [] as $line) {
            $line = trim($line);
            if ('' === $line || str_starts_with($line, '#')) {
                continue;
            }
            if (1 === preg_match('/^[\w][\w.\-+]*$/', $line)) {
                $models[] = $line;
            }
        }

        return array_values(array_unique($models));
    }
}
