<?php

declare(strict_types=1);

namespace DevLinkStudios\WPServiceContainer\Tests;

use DevLinkStudios\WPServiceContainer\Container;
use DevLinkStudios\WPServiceContainer\Exceptions\BindingResolutionException;
use DevLinkStudios\WPServiceContainer\Exceptions\EntryNotFoundException;
use DevLinkStudios\WPServiceContainer\Tests\Fixtures\AbstractService;
use DevLinkStudios\WPServiceContainer\Tests\Fixtures\CacheInterface;
use DevLinkStudios\WPServiceContainer\Tests\Fixtures\CachingRepository;
use DevLinkStudios\WPServiceContainer\Tests\Fixtures\Database;
use DevLinkStudios\WPServiceContainer\Tests\Fixtures\Logger;
use DevLinkStudios\WPServiceContainer\Tests\Fixtures\ObjectCache;
use DevLinkStudios\WPServiceContainer\Tests\Fixtures\Repository;
use DevLinkStudios\WPServiceContainer\Tests\Fixtures\ServiceWithDefaults;
use DevLinkStudios\WPServiceContainer\Tests\Fixtures\ServiceWithOptionalDependency;
use DevLinkStudios\WPServiceContainer\Tests\Fixtures\ServiceWithRequiredPrimitive;
use DevLinkStudios\WPServiceContainer\Tests\Fixtures\VariadicService;
use PHPUnit\Framework\TestCase;

final class AutowiringTest extends TestCase
{
    /** @var Container */
    private $container;

    protected function setUp(): void
    {
        $this->container = new Container();
    }

    public function testAClassWithNoConstructorIsResolvedWithoutRegistration(): void
    {
        self::assertInstanceOf(Logger::class, $this->container->resolve(Logger::class));
    }

    public function testNestedConstructorDependenciesAreResolvedRecursively(): void
    {
        $repository = $this->container->resolve(Repository::class);

        self::assertInstanceOf(Repository::class, $repository);
        self::assertInstanceOf(Database::class, $repository->database);
        self::assertInstanceOf(Logger::class, $repository->database->logger);
    }

    public function testAutoWiredDependenciesHonourExistingBindings(): void
    {
        $logger = new Logger();
        $this->container->instance(Logger::class, $logger);

        self::assertSame($logger, $this->container->resolve(Repository::class)->database->logger);
    }

    public function testPrimitiveParametersFallBackToTheirDefaultValue(): void
    {
        self::assertSame('wp_', $this->container->resolve(ServiceWithDefaults::class)->prefix);
    }

    public function testRuntimeParametersOverrideAutoWiringByName(): void
    {
        $service = $this->container->resolve(ServiceWithDefaults::class, ['prefix' => 'acme_']);

        self::assertSame('acme_', $service->prefix);
        self::assertInstanceOf(Database::class, $service->database);
    }

    public function testRuntimeParametersCanSupplyAnObjectDependency(): void
    {
        $database = new Database(new Logger());

        $service = $this->container->resolve(ServiceWithDefaults::class, ['database' => $database]);

        self::assertSame($database, $service->database);
    }

    public function testARequiredPrimitiveWithNoDefaultIsReportedClearly(): void
    {
        $this->expectException(BindingResolutionException::class);
        $this->expectExceptionMessage('$tableName');

        $this->container->resolve(ServiceWithRequiredPrimitive::class);
    }

    public function testAnUnresolvableOptionalDependencyBecomesNull(): void
    {
        $service = $this->container->resolve(ServiceWithOptionalDependency::class);

        self::assertNull($service->cache);
    }

    public function testAnOptionalDependencyIsInjectedOnceItIsBound(): void
    {
        $this->container->bind(CacheInterface::class, ObjectCache::class);

        $service = $this->container->resolve(ServiceWithOptionalDependency::class);

        self::assertInstanceOf(ObjectCache::class, $service->cache);
    }

    public function testAnUnboundInterfaceDependencyIsRejectedNotGuessed(): void
    {
        $this->expectException(BindingResolutionException::class);
        $this->expectExceptionMessage('not instantiable');

        $this->container->resolve(CachingRepository::class);
    }

    public function testAnAbstractClassCannotBeBuilt(): void
    {
        $this->expectException(BindingResolutionException::class);
        $this->expectExceptionMessage('not instantiable');

        $this->container->resolve(AbstractService::class);
    }

    public function testAnUnknownIdentifierRaisesNotFound(): void
    {
        $this->expectException(EntryNotFoundException::class);

        $this->container->resolve('DevLinkStudios\\WPServiceContainer\\Tests\\Fixtures\\NoSuchClass');
    }

    public function testTheBuildChainIsIncludedInResolutionFailures(): void
    {
        try {
            $this->container->resolve(CachingRepository::class);
            self::fail('Expected resolution to fail.');
        } catch (BindingResolutionException $e) {
            self::assertStringContainsString('Build chain', $e->getMessage());
            self::assertStringContainsString(CachingRepository::class, $e->getMessage());
        }
    }

    public function testVariadicParametersAreLeftEmptyWhenNothingIsSupplied(): void
    {
        $service = $this->container->resolve(VariadicService::class);

        self::assertSame([], $service->tags);
        self::assertInstanceOf(Logger::class, $service->logger);
    }

    public function testVariadicParametersAcceptAnArrayOverride(): void
    {
        $service = $this->container->resolve(VariadicService::class, ['tags' => ['alpha', 'beta']]);

        self::assertSame(['alpha', 'beta'], $service->tags);
    }
}
