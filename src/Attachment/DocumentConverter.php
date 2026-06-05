<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Attachment;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Process\Process;

/**
 * Converts office documents (doc/docx/odt/rtf) to PDF via headless LibreOffice,
 * so a PDF-capable coding agent can read them. No-op (returns null) when the
 * binary is unavailable or the conversion fails.
 */
final class DocumentConverter
{
    private const CONVERTIBLE = ['doc', 'docx', 'odt', 'rtf'];

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly string $sofficeBinary = 'soffice',
        private readonly int $timeout = 120,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function supports(string $path): bool
    {
        return \in_array(strtolower(pathinfo($path, \PATHINFO_EXTENSION)), self::CONVERTIBLE, true);
    }

    /**
     * Converts the file to a PDF next to it and returns the PDF path, or null.
     */
    public function toPdf(string $path): ?string
    {
        if (!$this->supports($path) || !is_file($path)) {
            return null;
        }

        $dir = \dirname($path);
        $process = new Process([$this->sofficeBinary, '--headless', '--convert-to', 'pdf', '--outdir', $dir, $path]);
        $process->setTimeout((float) $this->timeout);

        try {
            $process->run();
        } catch (\Throwable $e) {
            $this->logger->warning(\sprintf('DocumentConverter: LibreOffice unavailable (%s)', $e->getMessage()));

            return null;
        }

        if (!$process->isSuccessful()) {
            $this->logger->warning(\sprintf('DocumentConverter: conversion of "%s" failed: %s', $path, $process->getErrorOutput()));

            return null;
        }

        $pdf = $dir.'/'.pathinfo($path, \PATHINFO_FILENAME).'.pdf';

        return is_file($pdf) ? $pdf : null;
    }
}
