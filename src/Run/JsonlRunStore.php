<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Run;

/**
 * Append-only JSONL run store: one JSON object per line.
 *
 * Append-only + a write lock keeps concurrent runs (batch, CI) from corrupting
 * the file. Point {@see ticket_pilot.tracking.path} at a persistent, shared
 * location (e.g. a volume) when the runs happen in throw-away CI containers.
 */
final class JsonlRunStore implements RunStoreInterface
{
    public function __construct(private readonly string $path)
    {
    }

    public function record(RunRecord $record): void
    {
        $dir = \dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o777, true);
        }

        $line = json_encode($record->toArray(), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if (false === $line) {
            return;
        }

        file_put_contents($this->path, $line."\n", \FILE_APPEND | \LOCK_EX);
    }

    public function recent(int $limit = 50): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $lines = file($this->path, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        if (false === $lines) {
            return [];
        }

        $records = [];
        foreach ($lines as $line) {
            $data = json_decode($line, true);
            if (\is_array($data)) {
                /* @var array<string, mixed> $data */
                $records[] = RunRecord::fromArray($data);
            }
        }

        $records = array_reverse($records);

        return $limit > 0 ? \array_slice($records, 0, $limit) : $records;
    }
}
