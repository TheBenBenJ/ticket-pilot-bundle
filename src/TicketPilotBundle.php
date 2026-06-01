<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * The container extension (TheBenBenJ\TicketPilotBundle\DependencyInjection\TicketPilotExtension)
 * is discovered automatically through the Symfony naming convention.
 */
final class TicketPilotBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
