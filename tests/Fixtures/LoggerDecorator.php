<?php

declare(strict_types=1);

namespace DevLinkStudios\WPServiceContainer\Tests\Fixtures;

class LoggerDecorator extends Logger
{
    /** @var Logger */
    public $inner;

    public function __construct(Logger $inner)
    {
        $this->inner = $inner;
    }
}
