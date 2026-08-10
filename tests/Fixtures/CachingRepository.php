<?php

declare(strict_types=1);

namespace DevLinkStudios\WPServiceContainer\Tests\Fixtures;

class CachingRepository
{
    /** @var CacheInterface */
    public $cache;

    public function __construct(CacheInterface $cache)
    {
        $this->cache = $cache;
    }
}
