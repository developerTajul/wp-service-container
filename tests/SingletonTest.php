<?php

declare(strict_types=1);

namespace DevLinkStudios\WPServiceContainer\Tests;

use DevLinkStudios\WPServiceContainer\Container;
use DevLinkStudios\WPServiceContainer\Tests\Fixtures\Database;
use DevLinkStudios\WPServiceContainer\Tests\Fixtures\Logger;
use DevLinkStudios\WPServiceContainer\Tests\Fixtures\ServiceWithDefaults;
use PHPUnit\Framework\TestCase;

final class SingletonTest extends TestCase
{
    /** @var Container */
    private $container;

    protected function setUp(): void
    {
        $this->container = new Container();
    }

    public function testSingletonReturnsTheSameInstanceEveryTime(): void
    {
        $this->container->singleton(Logger::class, static function () {
            return new Logger();
        });

        self::assertSame(
            $this->container->resolve(Logger::class),
            $this->container->resolve(Logger::class)
        );
    }

    public function testSingletonWithoutConcreteAutoWiresOnce(): void
    {
        $this->container->singleton(Database::class);

        $first = $this->container->resolve(Database::class);

        self::assertInstanceOf(Database::class, $first);
        self::assertSame($first, $this->container->resolve(Database::class));
    }

    public function testTheFactoryRunsExactlyOnceForASingleton(): void
    {
        $calls = 0;

        $this->container->singleton(Logger::class, static function () use (&$calls) {
            ++$calls;

            return new Logger();
        });

        $this->container->resolve(Logger::class);
        $this->container->resolve(Logger::class);
        $this->container->resolve(Logger::class);

        self::assertSame(1, $calls);
    }

    public function testTheContainerIsPassedToTheFactory(): void
    {
        $this->container->singleton(Database::class, static function (Container $container) {
            return new Database($container->resolve(Logger::class));
        });

        self::assertInstanceOf(Database::class, $this->container->resolve(Database::class));
    }

    public function testRuntimeParametersBypassTheSharedCacheWithoutPoisoningIt(): void
    {
        $this->container->singleton(ServiceWithDefaults::class);

        $shared = $this->container->resolve(ServiceWithDefaults::class);
        $oneOff = $this->container->resolve(ServiceWithDefaults::class, ['prefix' => 'custom_']);

        self::assertNotSame($shared, $oneOff, 'A call with overrides must not return the cached instance.');
        self::assertSame('custom_', $oneOff->prefix);
        self::assertSame('wp_', $shared->prefix);
        self::assertSame(
            $shared,
            $this->container->resolve(ServiceWithDefaults::class),
            'A call with overrides must not replace the cached instance.'
        );
    }

    public function testSingletonSharesThroughAnInterfaceBinding(): void
    {
        $this->container->singleton(Database::class);
        $this->container->alias(Database::class, 'db');

        self::assertSame($this->container->resolve('db'), $this->container->resolve(Database::class));
    }
}
