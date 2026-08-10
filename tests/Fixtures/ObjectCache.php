<?php

declare(strict_types=1);

namespace DevLinkStudios\WPServiceContainer\Tests\Fixtures;

class ObjectCache implements CacheInterface
{
    public function get(string $key): ?string
    {
        return null;
    }
}
