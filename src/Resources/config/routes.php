<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use TheBenBenJ\TicketPilotBundle\Controller\TriggerPipelineController;

/*
 * Opt-in route for the pipeline-trigger endpoint.
 *
 * Import it from your application routing only if you enabled a VCS provider
 * exposing pipelines (e.g. GitLab):
 *
 *     # config/routes/ticket_pilot.yaml
 *     ticket_pilot:
 *         resource: '@TicketPilotBundle/Resources/config/routes.php'
 */
return static function (RoutingConfigurator $routes): void {
    $routes->add('ticket_pilot_trigger_pipeline', '/ia/auto-dev')
        ->controller(TriggerPipelineController::class)
        ->methods(['GET']);
};
