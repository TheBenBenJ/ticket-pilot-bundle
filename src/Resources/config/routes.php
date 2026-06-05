<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use TheBenBenJ\TicketPilotBundle\Controller\DashboardController;
use TheBenBenJ\TicketPilotBundle\Controller\DashboardLaunchController;
use TheBenBenJ\TicketPilotBundle\Controller\TriggerPipelineController;

/*
 * Opt-in routes for the bundle's HTTP endpoints (pipeline trigger + dashboard).
 *
 * Import them from your application routing only after enabling the matching
 * features (a VCS provider exposing pipelines for the trigger, ticket_pilot.tracking
 * for the dashboard) — and protect them behind your firewall, they start pipelines:
 *
 *     # config/routes/ticket_pilot.yaml
 *     ticket_pilot:
 *         resource: '@TicketPilotBundle/Resources/config/routes.php'
 */
return static function (RoutingConfigurator $routes): void {
    $routes->add('ticket_pilot_trigger_pipeline', '/ia/auto-dev')
        ->controller(TriggerPipelineController::class)
        ->methods(['GET']);

    $routes->add('ticket_pilot_dashboard', '/ia/dashboard')
        ->controller(DashboardController::class)
        ->methods(['GET']);

    $routes->add('ticket_pilot_dashboard_launch', '/ia/dashboard/launch')
        ->controller(DashboardLaunchController::class)
        ->methods(['POST']);
};
