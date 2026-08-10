<?php

declare(strict_types=1);

namespace DevLinkStudios\WPServiceContainer\Tests\Fixtures;

class OptionalCycleB
{
    public function __construct(?OptionalCycleA $a = null)
    {
    }
}
