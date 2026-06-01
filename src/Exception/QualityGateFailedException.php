<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Exception;

use TheBenBenJ\TicketPilotBundle\Contract\QualityReport;

/**
 * Thrown when the quality gate fails after the agent ran: the bundle aborts
 * before committing/pushing, so no merge/pull request is opened for broken code.
 */
final class QualityGateFailedException extends \RuntimeException
{
    private const ERROR_EXCERPT_MAX = 2000;

    public function __construct(public readonly QualityReport $report)
    {
        $excerpt = mb_substr(trim($report->errors), 0, self::ERROR_EXCERPT_MAX);
        $message = 'Quality gate failed after the agent ran; no merge request opened.';
        if ('' !== $excerpt) {
            $message .= "\n".$excerpt;
        }

        parent::__construct($message);
    }
}
