<?php

declare(strict_types=1);

namespace DevLinkStudios\WPServiceContainer\Exceptions;

use Psr\Container\NotFoundExceptionInterface;

/**
 * Thrown when an identifier is not registered and cannot be auto-wired
 * because no such class exists.
 */
class EntryNotFoundException extends ContainerException implements NotFoundExceptionInterface
{
    public static function forEntry(string $id): self
    {
        return new self(sprintf(
            'Entry [%s] is not registered in the container and is not an instantiable class.',
            $id
        ));
    }
}
