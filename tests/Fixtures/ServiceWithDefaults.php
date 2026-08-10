<?php

declare(strict_types=1);

namespace DevLinkStudios\WPServiceContainer\Tests\Fixtures;

class ServiceWithDefaults
{
    /** @var Database */
    public $database;

    /** @var string */
    public $prefix;

    public function __construct(Database $database, string $prefix = 'wp_')
    {
        $this->database = $database;
        $this->prefix = $prefix;
    }
}
