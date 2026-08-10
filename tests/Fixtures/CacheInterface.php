<?php

declare(strict_types=1);

namespace DevLinkStudios\WPServiceContainer\Tests\Fixtures;

interface CacheInterface
{
    public function get(string $key): ?string;
}
