<?php

declare(strict_types=1);

namespace DevLinkStudios\WPServiceContainer;

use Closure;
use DevLinkStudios\WPServiceContainer\Contracts\ContainerInterface;

/**
 * Fluent builder for contextual bindings.
 *
 * Returned by {@see Container::when()}; not intended to be constructed directly.
 *
 * @example
 *   $container->when(PostRepository::class)
 *             ->needs('$table')
 *             ->give('wp_posts');
 */
class ContextualBindingBuilder
{
    /** @var Container */
    private $container;

    /** @var list<string> */
    private $concrete;

    /** @var string|null */
    private $needs;

    /**
     * @param list<string> $concrete The consuming class(es) this context applies to.
     */
    public function __construct(Container $container, array $concrete)
    {
        $this->container = $container;
        $this->concrete = $concrete;
    }

    /**
     * The dependency being described: a class/interface name, or a parameter
     * name prefixed with `$` for primitives (e.g. `'$tableName'`).
     */
    public function needs(string $abstract): self
    {
        $this->needs = $abstract;

        return $this;
    }

    /**
     * What to supply for it: a literal value, an identifier to resolve, or a
     * factory closure `fn(ContainerInterface $c): mixed`.
     *
     * @param mixed $implementation
     */
    public function give($implementation): void
    {
        if ($this->needs === null) {
            throw new \LogicException('needs() must be called before give().');
        }

        foreach ($this->concrete as $concrete) {
            $this->container->addContextualBinding($concrete, $this->needs, $implementation);
        }
    }

    /**
     * Convenience for the common "give me this identifier, resolved" case.
     */
    public function giveService(string $abstract): void
    {
        $this->give(static function (ContainerInterface $container) use ($abstract) {
            return $container->resolve($abstract);
        });
    }
}
