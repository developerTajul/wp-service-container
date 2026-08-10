<?php

declare(strict_types=1);

namespace DevLinkStudios\WPServiceContainer\Tests\Fixtures;

/**
 * Stands in for a typical WordPress hook callback object.
 */
class HookHandler
{
    /** @var Database */
    public $database;

    public function __construct(Database $database)
    {
        $this->database = $database;
    }

    public function handle(Logger $logger, string $tag = 'init'): string
    {
        $logger->log($tag);

        return $tag;
    }

    public static function describe(Logger $logger): string
    {
        return 'static:' . get_class($logger);
    }

    public function __invoke(Logger $logger): string
    {
        return 'invoked:' . get_class($logger);
    }
}
