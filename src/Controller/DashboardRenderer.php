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
        RunRecord::STATUS_QUEUED => '#1f6feb',
        RunRecord::STATUS_SUCCESS => '#02ad72',
        RunRecord::STATUS_PASSED => '#02ad72',
        RunRecord::STATUS_FAILED => '#cf222e',
        RunRecord::STATUS_INCONCLUSIVE => '#9a6700',
        RunRecord::STATUS_SKIPPED => '#57606a',
    ];

    private static ?string $logo = null;

    /**
     * The brand logo (inline SVG), read once from the bundle resource.
     */
    private function logo(): string
    {
        if (null === self::$logo) {
            $file = __DIR__.'/../Resources/logo.svg';
            $svg = is_file($file) ? file_get_contents($file) : false;
            self::$logo = \is_string($svg) ? $svg : '';
        }

        return self::$logo;
    }

    /**
     * @param list<RunRecord> $runs
     * @param list<string>    $sources
     * @param list<string>              $agents
     * @param string                    $detailUrlTemplate URL with a "__TICKET__" placeholder, linked per ticket
     * @param array<string, list<string>> $agentModels     Models per agent for the launch forms
     */
    public function page(array $runs, string $launchUrl, bool $canLaunch, array $sources, array $agents, string $defaultSource, string $defaultAgent, string $detailUrlTemplate = '', array $agentModels = []): string
    {
        $forms = $canLaunch ? $this->launchForms($launchUrl, $sources, $agents, $defaultSource, $defaultAgent, $agentModels) : '<p class="muted">Enable a VCS provider exposing pipelines to launch runs from here.</p>';
        $rows = '' === ($r = $this->rows($runs, $detailUrlTemplate)) ? '<tr><td colspan="7" class="muted">No run recorded yet.</td></tr>' : $r;

        return $this->layout(
            '<section class="launch"><h2>Launch a run</h2>'.$forms.'</section>'
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
            '<p>%s%s</p><p><a href="%s">&larr; Back to the dashboard</a></p>',
            $this->e($message),
            $link,
            $this->e($backUrl),
        ));
    }

    /**
     * @param list<RunRecord> $runs
     */
    private function rows(array $runs, string $detailUrlTemplate = ''): string
    {
        $html = '';
        foreach ($runs as $run) {
            $color = self::STATUS_COLORS[$run->status] ?? '#57606a';
            $summary = trim(preg_replace('/\s+/', ' ', $run->summary) ?? '');
            $link = '' !== $run->url ? \sprintf('<a href="%s" target="_blank" rel="noopener">link</a>', $this->e($run->url)) : '';
            $ticket = '' !== $detailUrlTemplate && '' !== $run->ticketKey
                ? \sprintf('<a href="%s">%s</a>', $this->e(str_replace('__TICKET__', rawurlencode($run->ticketKey), $detailUrlTemplate)), $this->e($run->ticketKey))
                : $this->e($run->ticketKey);
            $html .= \sprintf(
                '<tr><td class="nowrap">%s</td><td>%s</td><td>%s</td>'
                .'<td><span class="badge" style="background:%s">%s</span></td>'
                .'<td class="nowrap">%s</td><td>%s</td><td>%s</td></tr>',
                $this->e($run->startedAt),
                $this->e($run->type),
                $ticket,
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
     * Per-ticket timeline: every step (dev, iterate, review) in chronological
     * order, with the full summary and the review screenshots.
     *
     * @param list<RunRecord> $runs Oldest first
     */
    public function ticketTimeline(string $ticket, array $runs, string $backUrl): string
    {
        if ([] === $runs) {
            $body = \sprintf('<h1>%s</h1><p class="muted">No run recorded for this ticket.</p>', $this->e($ticket));
        } else {
            $cards = '';
            foreach ($runs as $run) {
                $cards .= $this->timelineCard($run);
            }
            $body = \sprintf('<h1>%s <span class="muted">— %d step(s)</span></h1>%s', $this->e($ticket), \count($runs), $cards);
        }

        return $this->layout(
            \sprintf('<p><a href="%s">&larr; All runs</a></p>', $this->e($backUrl)).$body
        );
    }

    private function timelineCard(RunRecord $run): string
    {
        $color = self::STATUS_COLORS[$run->status] ?? '#57606a';

        $meta = [];
        if ('' !== $run->branch) {
            $meta[] = 'branch '.$this->e($run->branch);
        }
        if ('' !== $run->agent) {
            $meta[] = 'agent '.$this->e($run->agent);
        }
        if ($run->duration > 0.0) {
            $meta[] = \sprintf('%ds', (int) round($run->duration));
        }
        $metaLine = [] !== $meta ? '<div class="muted">'.implode(' · ', $meta).'</div>' : '';

        $link = '' !== $run->url ? \sprintf('<div><a href="%s" target="_blank" rel="noopener">%s</a></div>', $this->e($run->url), $this->e($run->url)) : '';
        $summary = '' !== trim($run->summary) ? '<div class="md">'.$this->markdown($run->summary).'</div>' : '';
        $shots = $this->screenshots($run->screenshots);

        return \sprintf(
            '<section class="card"><h3>%s <span class="badge" style="background:%s">%s</span> '
            .'<span class="muted">%s</span></h3>%s%s%s%s</section>',
            $this->e($run->type),
            $color,
            $this->e($run->status),
            $this->e($run->startedAt),
            $metaLine,
            $link,
            $summary,
            $shots,
        );
    }

    /**
     * @param list<string> $screenshots
     */
    private function screenshots(array $screenshots): string
    {
        if ([] === $screenshots) {
            return '';
        }

        // Bare names (older runs, or shots only attached to the ticket and not
        // web-served): we can't display them, so just list them.
        if (!$this->isViewable($screenshots[0])) {
            $items = '';
            foreach ($screenshots as $shot) {
                $items .= '<li>'.$this->e(basename($shot)).'</li>';
            }

            return '<details open><summary>Screenshots (attached to the ticket)</summary><ul>'.$items.'</ul></details>';
        }

        $items = '';
        foreach ($screenshots as $i => $shot) {
            $name = $this->e($this->shotLabel($shot, $i));
            $src = $this->e($shot);
            $items .= '<figure class="shot"><a href="'.$src.'" target="_blank" rel="noopener">'
                .'<img src="'.$src.'" alt="'.$name.'" loading="lazy"></a>'
                .'<figcaption>'.$name.'</figcaption></figure>';
        }

        return '<div class="shots">'.$items.'</div>';
    }

    private function isViewable(string $shot): bool
    {
        return str_starts_with($shot, 'http') || str_starts_with($shot, '/') || str_starts_with($shot, 'data:');
    }

    private function shotLabel(string $shot, int $index): string
    {
        if (str_starts_with($shot, 'data:')) {
            return \sprintf('screenshot-%d.png', $index + 1);
        }

        $name = basename($shot);

        return '' !== $name ? $name : \sprintf('screenshot-%d', $index + 1);
    }

    /**
     * Renders a lightweight Markdown subset (headings, bold/italic/code, bullet
     * and ordered lists, paragraphs) to HTML so review summaries read nicely
     * instead of as a raw block. Everything is escaped before formatting, so the
     * (untrusted) agent text can never inject markup.
     */
    private function markdown(string $text): string
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($text)) ?: [];

        $html = '';
        /** @var list<string> $para */
        $para = [];
        /** @var 'ul'|'ol'|null $list */
        $list = null;

        $flushPara = static function () use (&$para, &$html): void {
            if ([] !== $para) {
                $html .= '<p>'.implode('<br>', $para).'</p>';
                $para = [];
            }
        };
        $closeList = static function () use (&$list, &$html): void {
            if (null !== $list) {
                $html .= '</'.$list.'>';
                $list = null;
            }
        };

        foreach ($lines as $raw) {
            $line = trim($raw);

            if ('' === $line) {
                $flushPara();
                $closeList();
                continue;
            }

            if (1 === preg_match('/^(#{1,6})\s+(.*)$/', $line, $m)) {
                $flushPara();
                $closeList();
                $level = min(6, 3 + \strlen($m[1]));
                $html .= '<h'.$level.'>'.$this->inline($m[2]).'</h'.$level.'>';
                continue;
            }

            if (1 === preg_match('/^[-*+]\s+(.*)$/', $line, $m)) {
                $flushPara();
                if ('ul' !== $list) {
                    $closeList();
                    $html .= '<ul>';
                    $list = 'ul';
                }
                $html .= '<li>'.$this->inline($m[1]).'</li>';
                continue;
            }

            if (1 === preg_match('/^\d+[.)]\s+(.*)$/', $line, $m)) {
                $flushPara();
                if ('ol' !== $list) {
                    $closeList();
                    $html .= '<ol>';
                    $list = 'ol';
                }
                $html .= '<li>'.$this->inline($m[1]).'</li>';
                continue;
            }

            $closeList();
            $para[] = $this->inline($line);
        }

        $flushPara();
        $closeList();

        return $html;
    }

    /**
     * Escapes the text, then applies inline Markdown (code, bold, italic).
     */
    private function inline(string $text): string
    {
        $out = $this->e($text);
        $out = preg_replace('/`([^`]+)`/', '<code>$1</code>', $out) ?? $out;
        $out = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $out) ?? $out;
        $out = preg_replace('/(?<![\w*])[*_]([^*_\s][^*_]*?)[*_](?![\w*])/', '<em>$1</em>', $out) ?? $out;

        return $out;
    }

    /**
     * @param list<string>                $sources
     * @param list<string>                $agents
     * @param array<string, list<string>> $agentModels
     */
    private function launchForms(string $launchUrl, array $sources, array $agents, string $defaultSource, string $defaultAgent, array $agentModels): string
    {
        $sourceSelect = $this->select('source', $sources, $defaultSource);
        $agentSelect = $this->select('agent', $agents, $defaultAgent, 'tp-agent');
        $modelSelect = $this->modelSelect($agentModels[$defaultAgent] ?? []);
        $modelsJson = json_encode($agentModels, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT);
        $url = $this->e($launchUrl);

        return <<<HTML
            <div class="forms">
              <form method="post" action="{$url}">
                <input type="hidden" name="action" value="auto-dev">
                <h3>Develop</h3>
                <input name="ticket" placeholder="Ticket key (e.g. PROJ-123)" required>
                {$sourceSelect}{$agentSelect}{$modelSelect}
                <button type="submit">Run auto-dev</button>
              </form>
              <form method="post" action="{$url}">
                <input type="hidden" name="action" value="iterate">
                <h3>Iterate</h3>
                <input name="ticket" placeholder="Ticket key" required>
                <input name="branch" placeholder="Branch (optional)">
                {$sourceSelect}{$agentSelect}{$modelSelect}
                <button type="submit">Iterate on feedback</button>
              </form>
              <form method="post" action="{$url}">
                <input type="hidden" name="action" value="review">
                <h3>Review</h3>
                <input name="ticket" placeholder="Ticket key" required>
                <input name="url" placeholder="Review app URL (optional)">
                {$sourceSelect}{$agentSelect}{$modelSelect}
                <button type="submit">Run review</button>
              </form>
            </div>
            <script type="application/json" id="tp-agent-models">{$modelsJson}</script>
            <script>
            (function () {
              const raw = document.getElementById('tp-agent-models');
              if (!raw) return;
              const models = JSON.parse(raw.textContent);
              function sync(agentSelect) {
                const form = agentSelect.closest('form');
                const model = form.querySelector('.tp-model');
                const list = models[agentSelect.value] || [];
                const cur = model.value;
                model.innerHTML = '<option value="">(default)</option>' +
                  list.map(function (m) {
                    return '<option value="' + m.replace(/&/g, '&amp;').replace(/"/g, '&quot;') + '">' + m + '</option>';
                  }).join('');
                if (list.indexOf(cur) !== -1) model.value = cur;
              }
              document.querySelectorAll('.tp-agent').forEach(function (sel) {
                sel.addEventListener('change', function () { sync(sel); });
                sync(sel);
              });
            })();
            </script>
            HTML;
    }

    /**
     * @param list<string> $models
     */
    private function modelSelect(array $models): string
    {
        $opts = '<option value="">(default)</option>';
        foreach ($models as $model) {
            $opts .= \sprintf('<option value="%s">%s</option>', $this->e($model), $this->e($model));
        }

        return '<select name="model" class="tp-model">'.$opts.'</select>';
    }

    /**
     * @param list<string> $options
     */
    private function select(string $name, array $options, string $selected, string $class = ''): string
    {
        if ([] === $options) {
            return '';
        }

        $opts = '';
        foreach ($options as $option) {
            $opts .= \sprintf('<option%s>%s</option>', $option === $selected ? ' selected' : '', $this->e($option));
        }

        $classAttr = '' !== $class ? \sprintf(' class="%s"', $this->e($class)) : '';

        return \sprintf('<select name="%s"%s>%s</select>', $this->e($name), $classAttr, $opts);
    }

    private function layout(string $body): string
    {
        $logo = $this->logo();

        return <<<HTML
            <!doctype html>
            <html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
            <title>Ticket Pilot</title>
            <style>
              :root{
                --navy:#011a36;--green:#02ad72;--green-bright:#01d689;
                --bg:#fbfbfc;--surface:#ffffff;--border:#dbe2ea;--muted:#5b6675;
              }
              *{box-sizing:border-box}
              body{font:14px/1.5 system-ui,-apple-system,Segoe UI,sans-serif;color:var(--navy);background:var(--bg);margin:0}
              .wrap{max-width:1100px;margin:auto;padding:24px}
              .brand{display:flex;align-items:center;gap:12px;background:var(--surface);border-bottom:3px solid var(--green);padding:14px 24px}
              .brand svg{height:46px;width:auto;display:block}
              .brand .tag{color:var(--muted);font-size:13px;border-left:1px solid var(--border);padding-left:12px}
              h1{font-size:20px;color:var(--navy)}h2{font-size:16px;margin-top:28px;color:var(--navy)}h3{font-size:14px;margin:0 0 8px}
              a{color:#018a5b}
              table{width:100%;border-collapse:collapse;margin-top:8px}
              th,td{text-align:left;padding:7px 8px;border-bottom:1px solid var(--border);vertical-align:top}
              th{font-weight:600;color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:.03em}
              tr:hover td{background:#f4f9f6}
              .nowrap{white-space:nowrap}.muted{color:var(--muted)}
              .badge{color:#fff;border-radius:6px;padding:1px 8px;font-size:12px;font-weight:600}
              .forms{display:flex;gap:16px;flex-wrap:wrap}
              form{background:var(--surface);border:1px solid var(--border);border-top:3px solid var(--green);border-radius:10px;padding:14px;display:flex;flex-direction:column;gap:7px;min-width:220px;flex:1}
              input,select,button{padding:7px 9px;border-radius:7px;border:1px solid var(--border);font:inherit;color:var(--navy)}
              input:focus,select:focus{outline:2px solid var(--green-bright);border-color:var(--green)}
              button{background:var(--green);color:#fff;border:0;cursor:pointer;font-weight:600}
              button:hover{background:#018a5b}
              .card{background:var(--surface);border:1px solid var(--border);border-left:4px solid var(--green);border-radius:10px;padding:12px 16px;margin:12px 0}
              .card h3{display:flex;align-items:center;gap:8px;margin:0 0 6px;font-size:15px}
              pre{white-space:pre-wrap;word-break:break-word;background:#f4f9f6;padding:10px;border-radius:6px;margin:8px 0;font:12px/1.5 ui-monospace,monospace;border:1px solid var(--border)}
              .md{margin:8px 0}
              .md h4,.md h5,.md h6{margin:12px 0 4px;color:var(--navy)}
              .md h4{font-size:14px}.md h5{font-size:13px}.md h6{font-size:12px}
              .md p{margin:6px 0}
              .md ul,.md ol{margin:6px 0;padding-left:20px}.md li{margin:2px 0}
              .md code{background:#eef4f0;padding:1px 5px;border-radius:4px;font:12px/1.45 ui-monospace,monospace}
              .md strong{color:var(--navy)}
              .shots{display:flex;gap:12px;flex-wrap:wrap;margin-top:10px}
              .shots .shot{margin:0;width:240px}
              .shots img{width:240px;height:150px;object-fit:cover;border:1px solid var(--border);border-radius:8px;display:block;transition:transform .1s ease,border-color .1s ease}
              .shots a:hover img{transform:scale(1.02);border-color:var(--green)}
              .shots figcaption{font-size:11px;color:var(--muted);margin-top:4px;word-break:break-all}
            </style></head><body>
            <header class="brand">{$logo}<span class="tag">tickets → merge requests, automated</span></header>
            <div class="wrap">{$body}</div>
            </body></html>
            HTML;
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
