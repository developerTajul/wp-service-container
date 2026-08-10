<?php

declare(strict_types=1);

namespace DevLinkStudios\WPServiceContainer\Tests\Fixtures;

class Repository
{
    /** @var Database */
    public $database;

    public function __construct(Database $database)
    {
        $this->database = $database;
    }
}
