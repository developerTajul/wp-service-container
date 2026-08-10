<?php

declare(strict_types=1);

namespace DevLinkStudios\WPServiceContainer\Tests;

use DevLinkStudios\WPServiceContainer\Container;
use DevLinkStudios\WPServiceContainer\Tests\Fixtures\Database;
use DevLinkStudios\WPServiceContainer\Tests\Fixtures\HookHandler;
use DevLinkStudios\WPServiceContainer\Tests\Fixtures\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Container::call() is what makes WordPress hook callbacks injectable:
 *   add_action('init', fn() => $container->call([Handler::class, 'handle']));
 */
final class CallTest extends TestCase
{
    /** @var Container */
    private $container;

    protected function setUp(): void
    {
        $this->container = new Container();
    }

    public function testAClosureGetsItsTypeHintedParametersInjected(): void
    {
        $logger = new Logger();
        $this->container->instance(Logger::class, $logger);

        $result = $this->container->call(static function (Logger $injected) {
            return $injected;
        });

        self::assertSame($logger, $result);
    }

    public function testExplicitParametersTakePrecedenceOverInjection(): void
    {
        $result = $this->container->call(
            static function (string $tag) {
                return $tag;
            },
            ['tag' => 'save_post']
        );

        self::assertSame('save_post', $result);
    }

    public function testAClassStringReceiverIsResolvedThroughTheContainer(): void
    {
        $logger = new Logger();
        $this->container->instance(Logger::class, $logger);

        $result = $this->container->call([HookHandler::class, 'handle']);

        self::assertSame('init', $result);
        self::assertSame(['init'], $logger->lines, 'The injected logger must be the container-managed one.');
    }

    public function testTheAtSignSyntaxIsSupported(): void
    {
        $result = $this->container->call(HookHandler::class . '@handle', ['tag' => 'admin_init']);

        self::assertSame('admin_init', $result);
    }

    public function testAnExistingObjectReceiverIsCalledDirectly(): void
    {
        $handler = new HookHandler(new Database(new Logger()));

        $result = $this->container->call([$handler, 'handle'], ['tag' => 'wp_footer']);

        self::assertSame('wp_footer', $result);
    }

    public function testAStaticMethodIsCalledWithoutInstantiatingTheClass(): void
    {
        $result = $this->container->call(HookHandler::class . '::describe');

        self::assertSame('static:' . Logger::class, $result);
    }

    public function testAnInvokableObjectIsSupported(): void
    {
        $handler = new HookHandler(new Database(new Logger()));

        self::assertSame('invoked:' . Logger::class, $this->container->call($handler));
    }

    public function testTheReceiverItselfGetsItsDependenciesInjected(): void
    {
        $logger = new Logger();
        $this->container->instance(Logger::class, $logger);
        $this->container->singleton(Database::class);

        $this->container->call([HookHandler::class, 'handle']);

        // Proves the receiver was built by the container rather than by `new`.
        self::assertSame($logger, $this->container->resolve(Database::class)->logger);
    }
}
