<?php

declare(strict_types=1);

namespace DevLinkStudios\WPServiceContainer;

use DevLinkStudios\WPServiceContainer\Contracts\ContainerInterface;

/**
 * Base class for grouping related bindings.
 *
 * The two-phase register/boot split matters in WordPress: `register()` must only
 * add bindings (it runs while other plugins may still be registering theirs),
 * while `boot()` is where you touch `add_action`, `add_filter`, or anything that
 * resolves services. Registering all providers before booting any of them means a
 * provider can depend on a binding declared by a provider loaded after it.
 *
 * @example
 *   final class AdminServiceProvider extends ServiceProvider
 *   {
 *       public function register(): void
 *       {
 *           $this->container->singleton(SettingsPage::class);
 *       }
 *
 *       public function boot(): void
 *       {
 *           add_action('admin_menu', [$this->container->resolve(SettingsPage::class), 'register']);
 *       }
 *   }
 */
abstract class ServiceProvider
{
    /** @var ContainerInterface */
    protected $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * Declare bindings. Must not resolve anything or touch WordPress hooks.
     */
    abstract public function register(): void;

    /**
     * Wire up hooks and resolve services. Runs after every provider has registered.
     */
    public function boot(): void
    {
        // Optional for subclasses.
    }
}
