<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Review;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Process\Process;
use TheBenBenJ\TicketPilotBundle\Model\Ticket;

/**
 * Builds a single PDF report of an agent review (verdict + summary + the
 * screenshots, embedded inline) so the ticket gets one readable deliverable
 * instead of a pile of loose images.
 *
 * The HTML is rendered to PDF with headless LibreOffice (the same binary the
 * attachment converter already relies on), so no extra dependency is needed.
 * Returns null (and logs) whenever the binary is missing or the conversion
 * fails: the report is a bonus, never a reason to fail the review.
 */
final class ReviewReportRenderer
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly string $outputDir,
        private readonly string $sofficeBinary = 'soffice',
        private readonly int $timeout = 120,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Renders the report and returns the PDF path, or null on failure.
     *
     * @param list<string> $screenshots Absolute paths to the screenshots to embed
     */
    public function render(Ticket $ticket, bool $passed, string $summary, array $screenshots): ?string
    {
        if ('' === $this->outputDir) {
            return null;
        }

        if (!is_dir($this->outputDir) && !mkdir($this->outputDir, 0o777, true) && !is_dir($this->outputDir)) {
            $this->logger->warning(\sprintf('ReviewReportRenderer: cannot create output dir "%s"', $this->outputDir));

            return null;
        }

        // The screenshots are referenced by basename, so the HTML must live in the
        // same directory for LibreOffice to resolve and embed them.
        $stem = 'review-'.$this->slug($ticket->key);
        $htmlPath = rtrim($this->outputDir, '/').'/'.$stem.'.html';
        $pdfPath = rtrim($this->outputDir, '/').'/'.$stem.'.pdf';

        if (false === file_put_contents($htmlPath, $this->html($ticket, $passed, $summary, $screenshots))) {
            $this->logger->warning(\sprintf('ReviewReportRenderer: cannot write "%s"', $htmlPath));

            return null;
        }

        $process = new Process([$this->sofficeBinary, '--headless', '--convert-to', 'pdf', '--outdir', $this->outputDir, $htmlPath]);
        $process->setTimeout((float) $this->timeout);

        try {
            $process->run();
        } catch (\Throwable $e) {
            $this->logger->warning(\sprintf('ReviewReportRenderer: LibreOffice unavailable (%s)', $e->getMessage()));
            @unlink($htmlPath);

            return null;
        }

        @unlink($htmlPath);

        if (!$process->isSuccessful() || !is_file($pdfPath)) {
            $this->logger->warning(\sprintf('ReviewReportRenderer: conversion failed: %s', $process->getErrorOutput()));

            return null;
        }

        return $pdfPath;
    }

    /**
     * @param list<string> $screenshots
     */
    private function html(Ticket $ticket, bool $passed, string $summary, array $screenshots): string
    {
        $verdictClass = $passed ? 'pass' : 'fail';
        $verdictLabel = $passed ? 'PASSED' : 'FAILED';

        $meta = array_filter([
            $ticket->title,
            '' !== (string) $ticket->url ? $ticket->url : null,
            date('Y-m-d H:i'),
        ]);

        $shots = '';
        foreach ($screenshots as $path) {
            if (!is_file($path)) {
                continue;
            }
            $name = $this->esc(basename($path));
            $shots .= '<div class="shot"><img src="'.$name.'" /><div class="caption">'.$name.'</div></div>';
        }
        if ('' === $shots) {
            $shots = '<p class="muted">No screenshot.</p>';
        }

        return '<!doctype html><html><head><meta charset="utf-8"><style>'
            .'body{font-family:Arial,Helvetica,sans-serif;color:#222;font-size:12px;margin:24px;}'
            .'h1{font-size:18px;margin:0 0 4px;}h2{font-size:14px;margin:18px 0 6px;border-bottom:1px solid #ddd;padding-bottom:3px;}'
            .'.meta{color:#666;font-size:11px;margin:0 0 10px;}'
            .'.verdict{font-weight:bold;font-size:13px;}.verdict.pass{color:#1a7f37;}.verdict.fail{color:#b3261e;}'
            .'.summary{border:1px solid #ddd;padding:8px;background:#fafafa;}'
            .'.shot{margin:0 0 14px;}.shot img{width:680px;border:1px solid #ccc;}'
            .'.caption{font-size:10px;color:#777;margin-top:2px;}.muted{color:#999;}'
            .'</style></head><body>'
            .'<h1>Agent review — '.$this->esc($ticket->key).'</h1>'
            .'<p class="meta">'.implode(' · ', array_map([$this, 'esc'], $meta)).'</p>'
            .'<p class="verdict '.$verdictClass.'">Verdict: '.$verdictLabel.'</p>'
            .'<h2>Summary</h2><div class="summary">'.nl2br($this->esc($summary)).'</div>'
            .'<h2>Screenshots</h2>'.$shots
            .'</body></html>';
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
    }

    private function slug(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9._-]+/', '-', $value) ?: 'ticket';
    }
}
