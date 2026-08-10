<?php

declare(strict_types=1);

namespace DevLinkStudios\WPServiceContainer\Exceptions;

use Psr\Container\ContainerExceptionInterface;
use RuntimeException;

/**
 * Base class for every error thrown by the container.
 *
 * Implements PSR-11 so consumers can catch container failures generically
 * without coupling to this package.
 */
class ContainerException extends RuntimeException implements ContainerExceptionInterface
{
}
