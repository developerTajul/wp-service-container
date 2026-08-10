<?php

declare(strict_types=1);

namespace DevLinkStudios\WPServiceContainer\Tests;

use DevLinkStudios\WPServiceContainer\Container;
use DevLinkStudios\WPServiceContainer\Exceptions\CircularDependencyException;
use DevLinkStudios\WPServiceContainer\Tests\Fixtures\CycleA;
use DevLinkStudios\WPServiceContainer\Tests\Fixtures\CycleB;
use DevLinkStudios\WPServiceContainer\Tests\Fixtures\Logger;
use DevLinkStudios\WPServiceContainer\Tests\Fixtures\OptionalCycleA;
use DevLinkStudios\WPServiceContainer\Tests\Fixtures\SelfReferencing;
use PHPUnit\Framework\TestCase;

/**
 * Without the build-stack guard each of these cases recurses until PHP exhausts
 * the stack, which in a WordPress request means a segfault or a blank page with
 * nothing useful in debug.log.
 */
final class CircularDependencyTest extends TestCase
{
    /** @var Container */
    private $container;

    protected function setUp(): void
    {
        $this->container = new Container();
    }

    public function testAutoWiredCycleThrowsInsteadOfRecursing(): void
    {
        $this->expectException(CircularDependencyException::class);

        $this->container->resolve(CycleA::class);
    }

    public function testTheReportedChainNamesEveryLinkInTheCycle(): void
    {
        try {
            $this->container->resolve(CycleA::class);
            self::fail('Expected a circular dependency error.');
        } catch (CircularDependencyException $e) {
            self::assertSame([CycleA::class, CycleB::class, CycleA::class], $e->getChain());
            self::assertStringContainsString('Circular dependency detected', $e->getMessage());
            self::assertStringContainsString(CycleB::class, $e->getMessage());
        }
    }

    public function testASelfReferencingConstructorIsDetected(): void
    {
        try {
            $this->container->resolve(SelfReferencing::class);
            self::fail('Expected a circular dependency error.');
        } catch (CircularDependencyException $e) {
            self::assertSame([SelfReferencing::class, SelfReferencing::class], $e->getChain());
        }
    }

    public function testACycleBetweenFactoryClosuresIsDetected(): void
    {
        $this->container->bind('service.a', static function (Container $container) {
            return $container->resolve('service.b');
        });

        $this->container->bind('service.b', static function (Container $container) {
            return $container->resolve('service.a');
        });

        try {
            $this->container->resolve('service.a');
            self::fail('Expected a circular dependency error.');
        } catch (CircularDependencyException $e) {
            self::assertSame(['service.a', 'service.b', 'service.a'], $e->getChain());
        }
    }

    public function testACycleThroughDelegatedBindingsIsDetected(): void
    {
        $this->container->bind('alpha', 'beta');
        $this->container->bind('beta', 'alpha');

        $this->expectException(CircularDependencyException::class);

        $this->container->resolve('alpha');
    }

    /**
     * A cycle must never be swallowed by the optional-dependency fallback that
     * turns an unresolvable parameter into its default value or null. Both
     * parameters here are nullable with a default, so the fallback would happily
     * hide the design error if the cycle were treated as an ordinary failure.
     */
    public function testACycleIsNotDowngradedToADefaultValue(): void
    {
        $this->expectException(CircularDependencyException::class);

        $this->container->resolve(OptionalCycleA::class);
    }

    public function testTheContainerRemainsUsableAfterACycleIsCaught(): void
    {
        try {
            $this->container->resolve(CycleA::class);
        } catch (CircularDependencyException $e) {
            // Expected.
        }

        // If the build stack were not unwound in a finally block, this second,
        // unrelated resolution would fail or falsely report a cycle.
        self::assertInstanceOf(Logger::class, $this->container->resolve(Logger::class));

        try {
            $this->container->resolve(CycleA::class);
            self::fail('Expected a circular dependency error on the second attempt too.');
        } catch (CircularDependencyException $e) {
            self::assertSame([CycleA::class, CycleB::class, CycleA::class], $e->getChain());
        }
    }

    public function testResolvingTheSameTransientTwiceAsSiblingsIsNotACycle(): void
    {
        $this->container->bind('pair', static function (Container $container) {
            return [
                $container->resolve(Logger::class),
                $container->resolve(Logger::class),
            ];
        });

        $pair = $this->container->resolve('pair');

        self::assertNotSame($pair[0], $pair[1]);
    }
}
