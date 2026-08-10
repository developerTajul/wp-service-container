<?php

declare(strict_types=1);

namespace DevLinkStudios\WPServiceContainer\Tests;

use DevLinkStudios\WPServiceContainer\Container;
use DevLinkStudios\WPServiceContainer\Tests\Fixtures\CacheInterface;
use DevLinkStudios\WPServiceContainer\Tests\Fixtures\CachingRepository;
use DevLinkStudios\WPServiceContainer\Tests\Fixtures\ObjectCache;
use DevLinkStudios\WPServiceContainer\Tests\Fixtures\ServiceWithRequiredPrimitive;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ContextualBindingTest extends TestCase
{
    /** @var Container */
    private $container;

    protected function setUp(): void
    {
        $this->container = new Container();
    }

    public function testAPrimitiveCanBeSuppliedByParameterName(): void
    {
        $this->container->when(ServiceWithRequiredPrimitive::class)
            ->needs('$tableName')
            ->give('wp_posts');

        self::assertSame('wp_posts', $this->container->resolve(ServiceWithRequiredPrimitive::class)->tableName);
    }

    public function testAnInterfaceCanBeSatisfiedPerConsumer(): void
    {
        $this->container->when(CachingRepository::class)
            ->needs(CacheInterface::class)
            ->give(ObjectCache::class);

        self::assertInstanceOf(ObjectCache::class, $this->container->resolve(CachingRepository::class)->cache);
    }

    public function testAClosureImplementationReceivesTheContainer(): void
    {
        $cache = new ObjectCache();

        $this->container->when(CachingRepository::class)
            ->needs(CacheInterface::class)
            ->give(static function (Container $container) use ($cache) {
                return $cache;
            });

        self::assertSame($cache, $this->container->resolve(CachingRepository::class)->cache);
    }

    public function testRuntimeParametersOutrankContextualBindings(): void
    {
        $this->container->when(ServiceWithRequiredPrimitive::class)
            ->needs('$tableName')
            ->give('wp_posts');

        self::assertSame('wp_posts', $this->container->resolve(ServiceWithRequiredPrimitive::class)->tableName);
        self::assertSame(
            'wp_users',
            $this->container->resolve(ServiceWithRequiredPrimitive::class, ['tableName' => 'wp_users'])->tableName
        );
    }

    public function testAContextualBindingDoesNotLeakToOtherConsumers(): void
    {
        // Registered against a class that is never built here.
        $this->container->when(CachingRepository::class)
            ->needs('$tableName')
            ->give('wp_posts');

        $this->expectException(\DevLinkStudios\WPServiceContainer\Exceptions\BindingResolutionException::class);

        $this->container->resolve(ServiceWithRequiredPrimitive::class);
    }

    public function testGiveServiceResolvesTheNamedIdentifier(): void
    {
        $this->container->singleton(ObjectCache::class);

        $this->container->when(CachingRepository::class)
            ->needs(CacheInterface::class)
            ->giveService(ObjectCache::class);

        self::assertSame(
            $this->container->resolve(ObjectCache::class),
            $this->container->resolve(CachingRepository::class)->cache
        );
    }

    public function testNeedsMustBeCalledBeforeGive(): void
    {
        $this->expectException(LogicException::class);

        $this->container->when(CachingRepository::class)->give('anything');
    }
}
