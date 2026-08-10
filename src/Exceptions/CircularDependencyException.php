<?php

declare(strict_types=1);

namespace DevLinkStudios\WPServiceContainer\Exceptions;

/**
 * Thrown when resolution re-enters an identifier that is already being built.
 *
 * Without this guard the container would recurse until PHP exhausts the stack,
 * which on a WordPress request means a segfault or a white screen with nothing
 * useful in the log.
 */
class CircularDependencyException extends ContainerException
{
    /** @var list<string> */
    private $chain;

    /**
     * @param list<string> $buildStack Identifiers currently being built, in order.
     */
    public static function forChain(array $buildStack, string $abstract): self
    {
        $full = $buildStack;
        $full[] = $abstract;

        $start = array_search($abstract, $full, true);
        $chain = array_slice($full, $start === false ? 0 : (int) $start);

        $exception = new self(sprintf(
            'Circular dependency detected while resolving [%s]: %s',
            $abstract,
            implode(' -> ', $chain)
        ));

        $exception->chain = array_values($chain);

        return $exception;
    }

    /**
     * The cycle that was detected, starting and ending on the repeated entry.
     *
     * @return list<string>
     */
    public function getChain(): array
    {
        return $this->chain ?? [];
    }
}
