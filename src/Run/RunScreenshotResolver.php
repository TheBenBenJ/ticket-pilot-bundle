<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Run;

/**
 * Turns stored screenshot references (bare names, disk paths, data: URIs) into
 * values the dashboard can render as <img> previews.
 */
final class RunScreenshotResolver
{
    public function __construct(
        private readonly string $screenshotsDir,
        private readonly string $screenshotsBaseUrl,
    ) {
    }

    /**
     * @return list<string>
     */
    public function resolve(RunRecord $run): array
    {
        if ([] === $run->screenshots) {
            return [];
        }

        $out = [];
        foreach ($run->screenshots as $shot) {
            $resolved = $this->resolveOne($run->id, $shot);
            if ('' !== $resolved) {
                $out[] = $resolved;
            }
        }

        return $out;
    }

    private function resolveOne(string $runId, string $shot): string
    {
        if (str_starts_with($shot, 'http') || str_starts_with($shot, 'data:')) {
            return $shot;
        }

        if (str_starts_with($shot, '/') && !is_file($shot)) {
            return $shot;
        }

        $name = basename($shot);
        $runId = preg_replace('/[^A-Za-z0-9_-]/', '', $runId) ?: 'run';

        if ('' !== $this->screenshotsDir && '' !== $name) {
            $onDisk = rtrim($this->screenshotsDir, '/').'/'.$runId.'/'.$name;
            if (is_file($onDisk) && '' !== $this->screenshotsBaseUrl) {
                return rtrim($this->screenshotsBaseUrl, '/').'/'.rawurlencode($runId).'/'.rawurlencode($name);
            }
        }

        if (is_file($shot)) {
            $encoded = RunScreenshotEncoder::toViewable([$shot]);

            return $encoded[0] ?? $shot;
        }

        return $name;
    }
}
