<?php

declare(strict_types=1);

namespace DevLinkStudios\WPServiceContainer\Tests\Fixtures;

class Logger
{
    /** @var list<string> */
    public $lines = [];

    public function log(string $line): void
    {
        $this->lines[] = $line;
    }
}
