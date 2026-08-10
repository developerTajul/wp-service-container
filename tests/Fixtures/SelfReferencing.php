<?php

declare(strict_types=1);

namespace DevLinkStudios\WPServiceContainer\Tests\Fixtures;

class SelfReferencing
{
    public function __construct(SelfReferencing $self)
    {
    }
}
