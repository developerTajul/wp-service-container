<?php

declare(strict_types=1);

namespace DevLinkStudios\WPServiceContainer\Tests\Fixtures;

class CycleB
{
    public function __construct(CycleA $a)
    {
    }
}
