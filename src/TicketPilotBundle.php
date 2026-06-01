<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Resources live under src/Resources, so the default Bundle::getPath()
 * (the directory of this class) is what we want — `@TicketPilotBundle` resolves
 * to src/, e.g. `@TicketPilotBundle/Resources/config/routes.php`.
 *
 * The container extension (DependencyInjection\TicketPilotExtension) is discovered
 * automatically through the Symfony naming convention.
 */
final class TicketPilotBundle extends Bundle
{
}
