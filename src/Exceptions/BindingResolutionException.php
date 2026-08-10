<?php

declare(strict_types=1);

namespace DevLinkStudios\WPServiceContainer\Exceptions;

/**
 * Thrown when an entry exists but cannot be built — an un-instantiable
 * target (interface / abstract / private constructor) or a constructor
 * parameter the container has no way to satisfy.
 */
class BindingResolutionException extends ContainerException
{
    /**
     * @param list<string> $buildStack
     */
    public static function notInstantiable(string $concrete, array $buildStack = []): self
    {
        $message = sprintf('Target [%s] is not instantiable.', $concrete);

        if (count($buildStack) > 1) {
            $message .= ' Build chain: ' . implode(' -> ', $buildStack) . '.';
        }

        return new self($message);
    }

    /**
     * @param list<string> $buildStack
     */
    public static function unresolvableParameter(string $parameter, string $context, array $buildStack = []): self
    {
        $message = sprintf(
            'Unresolvable dependency: parameter $%s of [%s] has no class type hint, no default value, '
            . 'and no runtime override was supplied.',
            $parameter,
            $context
        );

        if (count($buildStack) > 1) {
            $message .= ' Build chain: ' . implode(' -> ', $buildStack) . '.';
        }

        return new self($message);
    }
}
