<?php

declare(strict_types=1);

namespace DevLinkStudios\WPServiceContainer\Tests;

use DevLinkStudios\WPServiceContainer\Container;
use DevLinkStudios\WPServiceContainer\Exceptions\ContainerException;
use DevLinkStudios\WPServiceContainer\Tests\Fixtures\CacheInterface;
use DevLinkStudios\WPServiceContainer\Tests\Fixtures\Database;
use DevLinkStudios\WPServiceContainer\Tests\Fixtures\Logger;
use DevLinkStudios\WPServiceContainer\Tests\Fixtures\ObjectCache;
use DevLinkStudios\WPServiceContainer\Tests\Fixtures\Repository;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BindingTest extends TestCase
{
    /** @var Container */
    private $container;

    protected function setUp(): void
    {
        $this->container = new Container();
    }

    public function testBindResolvesThroughTheFactoryClosure(): void
    {
        $expected = new Logger();

        $this->container->bind(Logger::class, static function () use ($expected) {
            return $expected;
        });

        self::assertSame($expected, $this->container->resolve(Logger::class));
    }

    public function testBindProducesAFreshValueOnEveryResolution(): void
    {
        $this->container->bind(Logger::class, static function () {
            return new Logger();
        });

        self::assertNotSame(
            $this->container->resolve(Logger::class),
            $this->container->resolve(Logger::class)
        );
    }

    public function testBindWithoutConcreteAutoWiresTheAbstractItself(): void
    {
        $this->container->bind(Repository::class);

        self::assertInstanceOf(Repository::class, $this->container->resolve(Repository::class));
    }

    public function testBindMapsAnInterfaceToAConcreteClassString(): void
    {
        $this->container->bind(CacheInterface::class, ObjectCache::class);

        self::assertInstanceOf(ObjectCache::class, $this->container->resolve(CacheInterface::class));
    }

    public function testBindNormalisesLeadingBackslashes(): void
    {
        $this->container->bind('\\' . Logger::class);

        self::assertTrue($this->container->bound(Logger::class));
        self::assertInstanceOf(Logger::class, $this->container->resolve('\\' . Logger::class));
    }

    public function testBindRejectsAnAlreadyConstructedObject(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('instance()');

        /** @psalm-suppress InvalidArgument — deliberate misuse. */
        $this->container->bind(Logger::class, new Logger());
    }

    /**
     * Regression: the original implementation used the presence of a key in
     * $instances as the "is shared" marker, so re-binding an identifier that had
     * previously been registered as a singleton kept serving the stale object.
     */
    public function testRebindingASingletonAsTransientClearsTheSharedInstance(): void
    {
        $this->container->singleton(Logger::class, static function () {
            return new Logger();
        });

        $shared = $this->container->resolve(Logger::class);
        self::assertSame($shared, $this->container->resolve(Logger::class));

        $this->container->bind(Logger::class, static function () {
            return new Logger();
        });

        $first = $this->container->resolve(Logger::class);
        $second = $this->container->resolve(Logger::class);

        self::assertNotSame($shared, $first, 'Re-binding must discard the previous shared instance.');
        self::assertNotSame($first, $second, 'Re-binding must clear the shared flag, not just the value.');
    }

    public function testInstanceRegistersAPreBuiltObjectAsShared(): void
    {
        $logger = new Logger();

        self::assertSame($logger, $this->container->instance(Logger::class, $logger));
        self::assertSame($logger, $this->container->resolve(Logger::class));
        self::assertSame($logger, $this->container->resolve(Logger::class));
    }

    public function testAliasResolvesToTheSameBinding(): void
    {
        $this->container->singleton(Database::class);
        $this->container->alias(Database::class, 'db');

        self::assertSame(
            $this->container->resolve(Database::class),
            $this->container->resolve('db')
        );
        self::assertTrue($this->container->bound('db'));
    }

    public function testAliasChainsAreFollowedToTheFinalTarget(): void
    {
        $this->container->singleton(Database::class);
        $this->container->alias(Database::class, 'db');
        $this->container->alias('db', 'wpdb');

        self::assertSame(Database::class, $this->container->getAlias('wpdb'));
        self::assertSame(
            $this->container->resolve(Database::class),
            $this->container->resolve('wpdb')
        );
    }

    public function testAnIdentifierCannotBeAliasedToItself(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('cannot be aliased to itself');

        $this->container->alias(Logger::class, Logger::class);
    }

    public function testAnAliasLoopIsReportedRatherThanHanging(): void
    {
        $this->container->alias('a', 'b');

        try {
            $this->container->alias('b', 'a');
            self::fail('Expected an alias loop to be rejected.');
        } catch (ContainerException $e) {
            self::assertStringContainsString('Alias loop detected', $e->getMessage());
        }

        // The rejected alias must not be left registered.
        self::assertSame('a', $this->container->getAlias('b'));
    }

    public function testForgetDropsTheBindingItsInstanceAndItsAliases(): void
    {
        $this->container->singleton(Logger::class);
        $this->container->alias(Logger::class, 'log');

        $first = $this->container->resolve(Logger::class);
        $this->container->forget(Logger::class);

        self::assertFalse($this->container->bound(Logger::class));
        self::assertFalse($this->container->bound('log'));
        self::assertNotSame($first, $this->container->resolve(Logger::class));
    }

    public function testFlushEmptiesTheContainer(): void
    {
        $this->container->singleton(Logger::class);
        $this->container->alias(Logger::class, 'log');
        $this->container->resolve(Logger::class);

        $this->container->flush();

        self::assertSame([], $this->container->getBindings());
        self::assertFalse($this->container->bound(Logger::class));
    }

    public function testGetBindingsListsRegisteredIdentifiers(): void
    {
        $this->container->bind(Logger::class);
        $this->container->instance(Database::class, new Database(new Logger()));
        $this->container->alias(Logger::class, 'log');

        $bindings = $this->container->getBindings();

        self::assertContains(Logger::class, $bindings);
        self::assertContains(Database::class, $bindings);
        self::assertContains('log', $bindings);
    }
}
