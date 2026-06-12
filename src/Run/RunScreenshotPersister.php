<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Run;

/**
 * Saves review screenshots on the dashboard host under a web-served directory and
 * returns their public URLs (stable, lightweight JSONL — no huge data: URIs).
 */
final class RunScreenshotPersister
{
    public function __construct(
        private readonly string $screenshotsDir,
        private readonly string $screenshotsBaseUrl,
    ) {
    }

    /**
     * Persists screenshots for one run. Accepts local file paths, data: URIs, or
     * pre-built file payloads (name + raw bytes).
     *
     * @param list<string>                                 $shots Absolute paths, data: URIs, or bare names (skipped)
     * @param list<array{name: string, data: string}>|null $files Base64 payloads from remote ingest
     *
     * @return list<string> Public URL paths (/ticket-pilot/screenshots/…) or empty when disabled
     */
    public function persist(string $runId, array $shots, ?array $files = null): array
    {
        if ('' === $this->screenshotsDir || '' === $this->screenshotsBaseUrl) {
            return [];
        }

        $runId = preg_replace('/[^A-Za-z0-9_-]/', '', $runId) ?: 'run';
        $dir = rtrim($this->screenshotsDir, '/').'/'.$runId;
        if (!is_dir($dir) && !@mkdir($dir, 0o777, true) && !is_dir($dir)) {
            return [];
        }

        $urls = [];
        $index = 0;

        if (\is_array($files)) {
            foreach ($files as $file) {
                if (!\is_array($file) || !isset($file['name'], $file['data'])) {
                    continue;
                }
                $url = $this->write($dir, $runId, (string) $file['name'], (string) $file['data'], $index++);
                if (null !== $url) {
                    $urls[] = $url;
                }
            }
        }

        foreach ($shots as $shot) {
            if (str_starts_with($shot, 'http') || (str_starts_with($shot, '/') && !is_file($shot))) {
                $urls[] = $shot;
                continue;
            }

            if (str_starts_with($shot, 'data:')) {
                $decoded = $this->decodeDataUri($shot);
                if (null === $decoded) {
                    continue;
                }
                $url = $this->write($dir, $runId, $decoded['name'], $decoded['data'], $index++);
                if (null !== $url) {
                    $urls[] = $url;
                }
                continue;
            }

            if (!is_file($shot)) {
                continue;
            }

            $raw = file_get_contents($shot);
            if (false === $raw) {
                continue;
            }
            $url = $this->write($dir, $runId, basename($shot), $raw, $index++);
            if (null !== $url) {
                $urls[] = $url;
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * @return array{name: string, data: string}|null
     */
    private function decodeDataUri(string $uri): ?array
    {
        if (1 !== preg_match('#^data:([^;,]+)?(?:;base64)?,(.+)$#s', $uri, $m)) {
            return null;
        }

        $raw = base64_decode($m[2], true);
        if (false === $raw) {
            return null;
        }

        $ext = match (strtolower((string) $m[1])) {
            'image/jpeg' => 'jpg',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'png',
        };

        return ['name' => 'screenshot.'.$ext, 'data' => $raw];
    }

    private function write(string $dir, string $runId, string $name, string $raw, int $index): ?string
    {
        $name = basename($name);
        if ('' === $name) {
            $name = \sprintf('screenshot-%d.png', $index + 1);
        }

        if (false === file_put_contents($dir.'/'.$name, $raw)) {
            return null;
        }

        return rtrim($this->screenshotsBaseUrl, '/').'/'.rawurlencode($runId).'/'.rawurlencode($name);
    }
}
