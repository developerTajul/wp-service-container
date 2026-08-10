<?php

declare(strict_types=1);

namespace DevLinkStudios\WPServiceContainer\Tests\Fixtures;

class VariadicService
{
    /** @var Logger */
    public $logger;

    /** @var list<string> */
    public $tags;

    public function __construct(Logger $logger, string ...$tags)
    {
        $this->logger = $logger;
        $this->tags = $tags;
    }
}
