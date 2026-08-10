<?php

declare(strict_types=1);

namespace DevLinkStudios\WPServiceContainer\Tests\Fixtures;

class CycleA
{
    public function __construct(CycleB $b)
    {
    }
}
