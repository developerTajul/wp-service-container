<?php

declare(strict_types=1);

namespace DevLinkStudios\WPServiceContainer\Tests\Fixtures;

class OptionalCycleA
{
    public function __construct(?OptionalCycleB $b = null)
    {
    }
}
