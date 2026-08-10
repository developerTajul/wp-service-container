<?php

declare(strict_types=1);

namespace DevLinkStudios\WPServiceContainer\Tests\Fixtures;

class ServiceWithOptionalDependency
{
    /** @var CacheInterface|null */
    public $cache;

    public function __construct(?CacheInterface $cache = null)
    {
        $this->cache = $cache;
    }
}
