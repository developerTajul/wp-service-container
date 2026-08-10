<?php

declare(strict_types=1);

namespace DevLinkStudios\WPServiceContainer\Tests;

use DevLinkStudios\WPServiceContainer\Container;
use DevLinkStudios\WPServiceContainer\Exceptions\ContainerException;
use DevLinkStudios\WPServiceContainer\Exceptions\EntryNotFoundException;
use DevLinkStudios\WPServiceContainer\Tests\Fixtures\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface as PsrContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * The value of PSR-11 compliance here is interoperability: a consumer that
 * type-hints the PSR interface accepts this container with no adapter.
 */
final class Psr11ComplianceTest extends TestCase
{
    /** @var Container */
    private $container;

    protected function setUp(): void
    {
        $this->container = new Container();
    }

    public function testTheContainerSatisfiesThePsrInterface(): void
    {
        self::assertInstanceOf(PsrContainerInterface::class, $this->container);
    }

    public function testGetReturnsARegisteredEntry(): void
    {
        $logger = new Logger();
        $this->container->instance(Logger::class, $logger);

        self::assertSame($logger, $this->container->get(Logger::class));
    }

    public function testGetAutoWiresAnUnregisteredButExistingClass(): void
    {
        self::assertInstanceOf(Logger::class, $this->container->get(Logger::class));
    }

    public function testGetThrowsANotFoundExceptionForAnUnknownEntry(): void
    {
        $this->expectException(NotFoundExceptionInterface::class);

        $this->container->get('no.such.entry');
    }

    public function testNotFoundIsAlsoAContainerException(): void
    {
        $thrown = null;

        try {
            $this->container->get('no.such.entry');
        } catch (ContainerExceptionInterface $e) {
            $thrown = $e;
        }

        self::assertInstanceOf(EntryNotFoundException::class, $thrown);
        self::assertInstanceOf(ContainerException::class, $thrown);
        self::assertInstanceOf(NotFoundExceptionInterface::class, $thrown);
    }

    public function testHasIsTrueForRegisteredAndAutoWirableEntries(): void
    {
        $this->container->bind('configured', static function () {
            return 'value';
        });

        self::assertTrue($this->container->has('configured'));
        self::assertTrue($this->container->has(Logger::class), 'An existing class is auto-wirable.');
        self::assertFalse($this->container->has('no.such.entry'));
    }

    /**
     * has() answers "can this be resolved?" while bound() answers "was this
     * registered?" — the distinction matters when deciding whether to apply a
     * default binding.
     */
    public function testBoundIsStricterThanHas(): void
    {
        self::assertTrue($this->container->has(Logger::class));
        self::assertFalse($this->container->bound(Logger::class));

        $this->container->bind(Logger::class);

        self::assertTrue($this->container->bound(Logger::class));
    }

    public function testHasIsRepeatableAndSideEffectFree(): void
    {
        $calls = 0;

        $this->container->singleton(Logger::class, static function () use (&$calls) {
            ++$calls;

            return new Logger();
        });

        $this->container->has(Logger::class);
        $this->container->has(Logger::class);

        self::assertSame(0, $calls, 'has() must not build the entry.');
    }
}
