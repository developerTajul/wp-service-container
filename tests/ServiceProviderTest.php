<?php

declare(strict_types=1);

namespace DevLinkStudios\WPServiceContainer\Tests;

use DevLinkStudios\WPServiceContainer\Container;
use DevLinkStudios\WPServiceContainer\ServiceProvider;
use DevLinkStudios\WPServiceContainer\Tests\Fixtures\Logger;
use PHPUnit\Framework\TestCase;

final class ServiceProviderTest extends TestCase
{
    /** @var Container */
    private $container;

    protected function setUp(): void
    {
        $this->container = new Container();
        RecordingProvider::$events = [];
    }

    public function testRegisterRunsImmediatelyAndBootIsDeferred(): void
    {
        $this->container->register(RecordingProvider::class);

        self::assertSame(['register'], RecordingProvider::$events);
        self::assertTrue($this->container->bound('recorded'));

        $this->container->boot();

        self::assertSame(['register', 'boot'], RecordingProvider::$events);
    }

    public function testEveryProviderRegistersBeforeAnyProviderBoots(): void
    {
        $this->container->register(FirstProvider::class);
        $this->container->register(SecondProvider::class);

        self::assertSame(
            ['first:register', 'second:register'],
            RecordingProvider::$events,
            'Both providers must register before either boots.'
        );

        $this->container->boot();

        self::assertSame(
            ['first:register', 'second:register', 'first:boot', 'second:boot'],
            RecordingProvider::$events
        );
    }

    /**
     * This ordering is why the two-phase split exists: a provider may boot
     * against a binding declared by a provider registered after it.
     */
    public function testAProviderCanBootAgainstALaterProvidersBinding(): void
    {
        $this->container->register(ConsumerProvider::class);
        $this->container->register(ProviderOfDependency::class);
        $this->container->boot();

        self::assertInstanceOf(Logger::class, ConsumerProvider::$resolved);
    }

    public function testBootIsIdempotent(): void
    {
        $this->container->register(RecordingProvider::class);
        $this->container->boot();
        $this->container->boot();

        self::assertSame(['register', 'boot'], RecordingProvider::$events);
    }

    public function testRegisteringTheSameProviderTwiceIsANoOp(): void
    {
        $this->container->register(RecordingProvider::class);
        $this->container->register(RecordingProvider::class);

        self::assertSame(['register'], RecordingProvider::$events);
    }

    public function testAProviderRegisteredAfterBootIsBootedImmediately(): void
    {
        $this->container->boot();
        $this->container->register(RecordingProvider::class);

        self::assertSame(['register', 'boot'], RecordingProvider::$events);
    }

    public function testAProviderInstanceCanBeRegisteredDirectly(): void
    {
        $provider = new RecordingProvider($this->container);

        self::assertSame($provider, $this->container->register($provider));
        self::assertSame(['register'], RecordingProvider::$events);
    }
}

class RecordingProvider extends ServiceProvider
{
    /** @var list<string> */
    public static $events = [];

    public function register(): void
    {
        self::$events[] = 'register';
        $this->container->singleton('recorded', static function () {
            return new Logger();
        });
    }

    public function boot(): void
    {
        self::$events[] = 'boot';
    }
}

final class FirstProvider extends ServiceProvider
{
    public function register(): void
    {
        RecordingProvider::$events[] = 'first:register';
    }

    public function boot(): void
    {
        RecordingProvider::$events[] = 'first:boot';
    }
}

final class SecondProvider extends ServiceProvider
{
    public function register(): void
    {
        RecordingProvider::$events[] = 'second:register';
    }

    public function boot(): void
    {
        RecordingProvider::$events[] = 'second:boot';
    }
}

final class ProviderOfDependency extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton('late.dependency', static function () {
            return new Logger();
        });
    }
}

final class ConsumerProvider extends ServiceProvider
{
    /** @var mixed */
    public static $resolved;

    public function register(): void
    {
        // Deliberately empty: resolving here would be the bug this split prevents.
    }

    public function boot(): void
    {
        self::$resolved = $this->container->resolve('late.dependency');
    }
}
