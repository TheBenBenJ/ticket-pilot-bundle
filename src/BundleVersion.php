<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle;

/**
 * Resolves the installed bundle version for display (dashboard footer, logs).
 */
final class BundleVersion
{
    private static ?string $cached = null;

    public static function pretty(): string
    {
        if (null !== self::$cached) {
            return self::$cached;
        }

        if (class_exists(\Composer\InstalledVersions::class)) {
            $version = \Composer\InstalledVersions::getPrettyVersion('thebenbenj/ticket-pilot-bundle');
            if (null !== $version && '' !== $version) {
                return self::$cached = $version;
            }
        }

        $composer = \dirname(__DIR__).'/composer.json';
        if (is_file($composer)) {
            $data = json_decode((string) file_get_contents($composer), true);
            if (\is_array($data)) {
                $extra = $data['extra']['version'] ?? null;
                if (\is_string($extra) && '' !== $extra) {
                    return self::$cached = $extra;
                }
            }
        }

        return self::$cached = 'dev';
    }
}
