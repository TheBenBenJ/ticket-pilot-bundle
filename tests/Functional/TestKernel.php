<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
use TheBenBenJ\TicketPilotBundle\TicketPilotBundle;

/**
 * Minimal application kernel that boots FrameworkBundle + TicketPilotBundle so the
 * full container can be compiled and inspected in functional tests.
 */
final class TestKernel extends Kernel
{
    /**
     * @var array<string, mixed>
     */
    private array $bundleConfig;

    /**
     * @param array<string, mixed> $bundleConfig
     */
    public function __construct(array $bundleConfig = [])
    {
        $this->bundleConfig = $bundleConfig;

        parent::__construct('test', false);
    }

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new TicketPilotBundle()];
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(function (ContainerBuilder $container): void {
            $container->loadFromExtension('framework', [
                'test' => true,
                'http_method_override' => false,
                'secret' => 'test',
                'http_client' => true,
            ]);

            $container->loadFromExtension('ticket_pilot', $this->bundleConfig);
        });
    }

    public function getProjectDir(): string
    {
        return \dirname(__DIR__, 2);
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/ticket_pilot_bundle/cache/'.spl_object_id($this);
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/ticket_pilot_bundle/log';
    }
}
