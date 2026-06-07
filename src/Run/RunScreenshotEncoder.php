<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Run;

/**
 * Turns local screenshot files into viewable payloads for the dashboard
 * (data URIs). Already-viewable values are kept as-is.
 */
final class RunScreenshotEncoder
{
    /**
     * @param list<string> $shots Absolute file paths, URLs, served paths or data URIs
     *
     * @return list<string>
     */
    public static function toViewable(array $shots): array
    {
        $out = [];
        foreach ($shots as $shot) {
            if (str_starts_with($shot, 'http') || str_starts_with($shot, 'data:')) {
                $out[] = $shot;
                continue;
            }
            if (str_starts_with($shot, '/') && !self::isLocalFilesystemPath($shot)) {
                $out[] = $shot;
                continue;
            }
            if (is_file($shot) && ($raw = @file_get_contents($shot)) !== false) {
                $out[] = 'data:'.self::mime($shot).';base64,'.base64_encode($raw);
                continue;
            }
            $name = basename($shot);
            if ('' !== $name) {
                $out[] = $name;
            }
        }

        return $out;
    }

    private static function isLocalFilesystemPath(string $path): bool
    {
        return 1 === preg_match('#^/(?:tmp|var|home|usr|opt|root|private)(?:/|$)#', $path) || is_file($path);
    }

    private static function mime(string $path): string
    {
        return match (strtolower(pathinfo($path, \PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'image/png',
        };
    }
}
