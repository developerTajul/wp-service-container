<?php

declare(strict_types=1);

namespace DevLinkStudios\WPServiceContainer\Tests\Fixtures;

class ServiceWithRequiredPrimitive
{
    /** @var string */
    public $tableName;

    public function __construct(string $tableName)
    {
        $this->tableName = $tableName;
    }
}
