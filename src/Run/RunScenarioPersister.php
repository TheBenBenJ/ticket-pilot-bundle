<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Run;

/**
 * Saves review scenarios on the dashboard host under a web-served directory and
 * returns their public URL (one Markdown file per ticket, overwritten each review).
 */
final class RunScenarioPersister
{
    public function __construct(
        private readonly string $scenariosDir,
        private readonly string $scenariosBaseUrl,
    ) {
    }

    /**
     * @return string Public URL path (/ticket-pilot/scenarios/…) or empty when disabled
     */
    public function persist(string $ticketKey, string $markdown): string
    {
        if ('' === $this->scenariosDir || '' === $this->scenariosBaseUrl || '' === trim($markdown)) {
            return '';
        }

        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $ticketKey) ?: 'ticket';
        $dir = rtrim($this->scenariosDir, '/');
        if (!is_dir($dir) && !@mkdir($dir, 0o777, true) && !is_dir($dir)) {
            return '';
        }

        $filename = $safe.'.md';
        if (false === file_put_contents($dir.'/'.$filename, trim($markdown)."\n")) {
            return '';
        }

        return rtrim($this->scenariosBaseUrl, '/').'/'.rawurlencode($filename);
    }
}
