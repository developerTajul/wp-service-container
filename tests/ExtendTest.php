<?php

declare(strict_types=1);

namespace DevLinkStudios\WPServiceContainer\Tests;

use DevLinkStudios\WPServiceContainer\Container;
use DevLinkStudios\WPServiceContainer\Tests\Fixtures\Logger;
use DevLinkStudios\WPServiceContainer\Tests\Fixtures\LoggerDecorator;
use PHPUnit\Framework\TestCase;

final class ExtendTest extends TestCase
{
    /** @var Container */
    private $container;

    protected function setUp(): void
    {
        $this->container = new Container();
    }

    public function testExtendWrapsAServiceAfterItIsBuilt(): void
    {
        $this->container->bind(Logger::class);

        $this->container->extend(Logger::class, static function (Logger $logger) {
            return new LoggerDecorator($logger);
        });

        $resolved = $this->container->resolve(Logger::class);

        self::assertInstanceOf(LoggerDecorator::class, $resolved);
        self::assertInstanceOf(Logger::class, $resolved->inner);
    }

    public function testExtendersApplyInRegistrationOrder(): void
    {
        $this->container->bind('tag', static function () {
            return 'base';
        });

        $this->container->extend('tag', static function (string $value) {
            return $value . '-first';
        });

        $this->container->extend('tag', static function (string $value) {
            return $value . '-second';
        });

        self::assertSame('base-first-second', $this->container->resolve('tag'));
    }

    public function testTheExtenderReceivesTheContainer(): void
    {
        $this->container->bind('tag', static function () {
            return 'base';
        });

        $this->container->extend('tag', function ($value, Container $container) {
            self::assertSame($this->container, $container);

            return $value;
        });

        $this->container->resolve('tag');
    }

    public function testExtendAppliesImmediatelyToAnAlreadySharedInstance(): void
    {
        $this->container->instance('tag', 'base');

        $this->container->extend('tag', static function (string $value) {
            return $value . '-wrapped';
        });

        self::assertSame('base-wrapped', $this->container->resolve('tag'));
    }

    public function testAnExtendedSingletonIsWrappedExactlyOnce(): void
    {
        $calls = 0;

        $this->container->singleton(Logger::class);
        $this->container->extend(Logger::class, static function (Logger $logger) use (&$calls) {
            ++$calls;

            return new LoggerDecorator($logger);
        });

        $first = $this->container->resolve(Logger::class);
        $second = $this->container->resolve(Logger::class);

        self::assertSame($first, $second);
        self::assertSame(1, $calls, 'The decorator must not re-wrap the cached instance on later resolutions.');
    }

    public function testExtendersApplyToEveryResolutionOfATransientBinding(): void
    {
        $calls = 0;

        $this->container->bind(Logger::class);
        $this->container->extend(Logger::class, static function (Logger $logger) use (&$calls) {
            ++$calls;

            return new LoggerDecorator($logger);
        });

        $this->container->resolve(Logger::class);
        $this->container->resolve(Logger::class);

        self::assertSame(2, $calls);
    }

    public function testExtendFollowsAliases(): void
    {
        $this->container->singleton(Logger::class);
        $this->container->alias(Logger::class, 'log');

        $this->container->extend('log', static function (Logger $logger) {
            return new LoggerDecorator($logger);
        });

        self::assertInstanceOf(LoggerDecorator::class, $this->container->resolve(Logger::class));
    }
}
