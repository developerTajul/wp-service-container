<?php

declare(strict_types=1);

namespace DevLinkStudios\WPServiceContainer\Contracts;

use Closure;
use Psr\Container\ContainerInterface as PsrContainerInterface;

/**
 * The container contract.
 *
 * Extends PSR-11 so that any consumer able to accept a generic container
 * (loggers, routers, third-party libraries) can accept this one unchanged.
 *
 * @psalm-api
 */
interface ContainerInterface extends PsrContainerInterface
{
    /**
     * Register a binding that produces a new value on every resolution.
     *
     * @param string                     $abstract Identifier — usually an interface or class name.
     * @param Closure|string|null        $concrete A factory closure `fn(Container $c, array $params)`,
     *                                             another identifier to delegate to, or null to bind
     *                                             $abstract to itself (auto-wired).
     */
    public function bind(string $abstract, $concrete = null): void;

    /**
     * Register a binding resolved once and shared for the lifetime of the container.
     *
     * @param Closure|string|null $concrete See {@see bind()}.
     */
    public function singleton(string $abstract, $concrete = null): void;

    /**
     * Register an already-constructed object as a shared binding.
     *
     * @param mixed $instance
     * @return mixed The stored instance, after any registered extenders are applied.
     */
    public function instance(string $abstract, $instance);

    /**
     * Resolve an identifier out of the container.
     *
     * @param array<string, mixed> $parameters Constructor arguments, by parameter name, that take
     *                                         precedence over auto-wiring for this call only.
     * @return mixed
     *
     * @throws \DevLinkStudios\WPServiceContainer\Exceptions\CircularDependencyException
     * @throws \DevLinkStudios\WPServiceContainer\Exceptions\BindingResolutionException
     * @throws \DevLinkStudios\WPServiceContainer\Exceptions\EntryNotFoundException
     */
    public function resolve(string $abstract, array $parameters = []);

    /**
     * Whether $abstract has been explicitly registered (bound, shared, or aliased).
     *
     * Unlike {@see has()} this does not consider auto-wirable concrete classes.
     */
    public function bound(string $abstract): bool;

    /**
     * Alias an identifier to another, so both resolve to the same binding.
     */
    public function alias(string $abstract, string $alias): void;

    /**
     * Wrap the result of an existing binding — the container's decorator hook.
     *
     * @param Closure $extender `fn(mixed $service, Container $c): mixed`
     */
    public function extend(string $abstract, Closure $extender): void;

    /**
     * Invoke a callable, auto-wiring any parameters not supplied in $parameters.
     *
     * @param callable|array|string $callback
     * @param array<string, mixed>  $parameters
     * @return mixed
     */
    public function call($callback, array $parameters = []);

    /**
     * Drop a single binding, its shared instance, its extenders and its aliases.
     */
    public function forget(string $abstract): void;

    /**
     * Reset the container to an empty state.
     */
    public function flush(): void;
}
