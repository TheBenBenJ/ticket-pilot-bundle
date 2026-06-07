<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Controller;

use TheBenBenJ\TicketPilotBundle\Run\RunRecord;

/**
 * Renders the dashboard HTML without pulling in a template engine, so the
 * bundle stays framework-light (no hard Twig dependency).
 */
final class DashboardRenderer
{
    private const STATUS_COLORS = [
        RunRecord::STATUS_SUCCESS => '#1a7f37',
        RunRecord::STATUS_PASSED => '#1a7f37',
        RunRecord::STATUS_FAILED => '#cf222e',
        RunRecord::STATUS_INCONCLUSIVE => '#9a6700',
        RunRecord::STATUS_SKIPPED => '#57606a',
    ];

    /**
     * @param list<RunRecord> $runs
     * @param list<string>    $sources
     * @param list<string>    $agents
     */
    public function page(array $runs, string $launchUrl, bool $canLaunch, array $sources, array $agents, string $defaultSource, string $defaultAgent): string
    {
        $forms = $canLaunch ? $this->launchForms($launchUrl, $sources, $agents, $defaultSource, $defaultAgent) : '<p class="muted">Enable a VCS provider exposing pipelines to launch runs from here.</p>';
        $rows = '' === ($r = $this->rows($runs)) ? '<tr><td colspan="7" class="muted">No run recorded yet.</td></tr>' : $r;

        return $this->layout(
            '<h1>Ticket Pilot</h1>'
            .'<section class="launch"><h2>Launch a run</h2>'.$forms.'</section>'
            .'<section><h2>Recent runs</h2><table>'
            .'<thead><tr><th>Date</th><th>Type</th><th>Ticket</th><th>Status</th><th>Branch</th><th>Summary</th><th></th></tr></thead>'
            .'<tbody>'.$rows.'</tbody></table></section>'
        );
    }

    public function confirmation(string $message, string $backUrl, ?string $pipelineUrl = null): string
    {
        $link = null !== $pipelineUrl && '' !== $pipelineUrl
            ? \sprintf(' <a href="%s" target="_blank" rel="noopener">View pipeline</a>.', $this->e($pipelineUrl))
            : '';

        return $this->layout(\sprintf(
            '<h1>Ticket Pilot</h1><p>%s%s</p><p><a href="%s">&larr; Back to the dashboard</a></p>',
            $this->e($message),
            $link,
            $this->e($backUrl),
        ));
    }

    /**
     * @param list<RunRecord> $runs
     */
    private function rows(array $runs): string
    {
        $html = '';
        foreach ($runs as $run) {
            $color = self::STATUS_COLORS[$run->status] ?? '#57606a';
            $summary = trim(preg_replace('/\s+/', ' ', $run->summary) ?? '');
            $link = '' !== $run->url ? \sprintf('<a href="%s" target="_blank" rel="noopener">link</a>', $this->e($run->url)) : '';
            $html .= \sprintf(
                '<tr><td class="nowrap">%s</td><td>%s</td><td>%s</td>'
                .'<td><span class="badge" style="background:%s">%s</span></td>'
                .'<td class="nowrap">%s</td><td>%s</td><td>%s</td></tr>',
                $this->e($run->startedAt),
                $this->e($run->type),
                $this->e($run->ticketKey),
                $color,
                $this->e($run->status),
                $this->e($run->branch),
                $this->e(mb_substr($summary, 0, 80)),
                $link,
            );
        }

        return $html;
    }

    /**
     * @param list<string> $sources
     * @param list<string> $agents
     */
    private function launchForms(string $launchUrl, array $sources, array $agents, string $defaultSource, string $defaultAgent): string
    {
        $sourceSelect = $this->select('source', $sources, $defaultSource);
        $agentSelect = $this->select('agent', $agents, $defaultAgent);
        $modelInput = '<input name="model" placeholder="Model (optional)">';
        $url = $this->e($launchUrl);

        return <<<HTML
            <div class="forms">
              <form method="post" action="{$url}">
                <input type="hidden" name="action" value="auto-dev">
                <h3>Develop</h3>
                <input name="ticket" placeholder="Ticket key (e.g. PROJ-123)" required>
                {$sourceSelect}{$agentSelect}{$modelInput}
                <button type="submit">Run auto-dev</button>
              </form>
              <form method="post" action="{$url}">
                <input type="hidden" name="action" value="iterate">
                <h3>Iterate</h3>
                <input name="ticket" placeholder="Ticket key" required>
                <input name="branch" placeholder="Branch (optional)">
                {$sourceSelect}{$agentSelect}{$modelInput}
                <button type="submit">Iterate on feedback</button>
              </form>
              <form method="post" action="{$url}">
                <input type="hidden" name="action" value="review">
                <h3>Review</h3>
                <input name="ticket" placeholder="Ticket key" required>
                <input name="url" placeholder="Review app URL (optional)">
                {$sourceSelect}{$agentSelect}{$modelInput}
                <button type="submit">Run review</button>
              </form>
            </div>
            HTML;
    }

    /**
     * @param list<string> $options
     */
    private function select(string $name, array $options, string $selected): string
    {
        if ([] === $options) {
            return '';
        }

        $opts = '';
        foreach ($options as $option) {
            $opts .= \sprintf('<option%s>%s</option>', $option === $selected ? ' selected' : '', $this->e($option));
        }

        return \sprintf('<select name="%s">%s</select>', $this->e($name), $opts);
    }

    private function layout(string $body): string
    {
        return <<<HTML
            <!doctype html>
            <html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
            <title>Ticket Pilot</title>
            <style>
              :root{color-scheme:light dark}
              body{font:14px/1.5 system-ui,sans-serif;margin:0;padding:24px;max-width:1100px;margin:auto}
              h1{font-size:20px}h2{font-size:16px;margin-top:28px}h3{font-size:14px;margin:0 0 8px}
              table{width:100%;border-collapse:collapse;margin-top:8px}
              th,td{text-align:left;padding:6px 8px;border-bottom:1px solid #d0d7de;vertical-align:top}
              th{font-weight:600;color:#57606a}
              .nowrap{white-space:nowrap}.muted{color:#57606a}
              .badge{color:#fff;border-radius:6px;padding:1px 8px;font-size:12px}
              .forms{display:flex;gap:16px;flex-wrap:wrap}
              form{border:1px solid #d0d7de;border-radius:8px;padding:12px;display:flex;flex-direction:column;gap:6px;min-width:220px;flex:1}
              input,select,button{padding:6px 8px;border-radius:6px;border:1px solid #d0d7de;font:inherit}
              button{background:#1f6feb;color:#fff;border:0;cursor:pointer}
            </style></head><body>
            {$body}
            </body></html>
            HTML;
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
