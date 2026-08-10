<?php

declare(strict_types=1);

namespace DevLinkStudios\WPServiceContainer\Tests\Fixtures;

class Database
{
    /** @var Logger */
    public $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }
}
