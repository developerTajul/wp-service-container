# WP Service Container

[![Packagist Version](https://img.shields.io/packagist/v/devlinkstudios/wp-service-container.svg)](https://packagist.org/packages/devlinkstudios/wp-service-container)
[![PHP Version](https://img.shields.io/packagist/php-v/devlinkstudios/wp-service-container.svg)](https://packagist.org/packages/devlinkstudios/wp-service-container)
[![License](https://img.shields.io/packagist/l/devlinkstudios/wp-service-container.svg)](LICENSE)

A lightweight, Laravel-inspired [PSR-11](https://www.php-fig.org/psr/psr-11/) service container with
reflection-based auto-wiring, designed for WordPress plugins.

WordPress gives you one global namespace, no bootstrap phase you control, and a hook system that
calls your code at arbitrary times. This package gives your plugin an explicit composition root, so
your classes declare their dependencies in a constructor instead of reaching for globals or
singletons of their own.

- **No WordPress coupling.** The container never calls a WordPress function, so it unit tests
  without a WP bootstrap.
- **PSR-11 compliant.** Interoperates with any library that accepts a standard container.
- **Cycles are exceptions, not crashes.** A dependency loop raises a catchable error naming the
  full chain, rather than exhausting the stack.
- **PHP 7.4 → 8.4.**

## Table of contents

- [Installation](#installation)
- [Quick start](#quick-start)
- [Registering services](#registering-services)
- [Auto-wiring rules](#auto-wiring-rules)
- [Contextual bindings](#contextual-bindings)
- [Injecting into hook callbacks](#injecting-into-hook-callbacks)
- [Decorating services](#decorating-services)
- [Service providers](#service-providers)
- [Recommended plugin bootstrap](#recommended-plugin-bootstrap)
- [Error handling](#error-handling)
- [API reference](#api-reference)
- [Testing](#testing)
- [Requirements](#requirements)
- [License](#license)

## Installation

```bash
composer require devlinkstudios/wp-service-container
```

## Quick start

```php
use DevLinkStudios\WPServiceContainer\Container;

$container = new Container();

// Auto-wiring: no registration needed for a concrete class whose
// dependencies are themselves resolvable.
$repository = $container->resolve(PostRepository::class);
```

Given these classes, the container builds the whole graph from the one call above:

```php
class Logger {}

class Database
{
    private $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }
}

class PostRepository
{
    private $database;

    public function __construct(Database $database)
    {
        $this->database = $database;
    }
}
```

## Registering services

### Transient bindings

A new value on every resolution:

```php
$container->bind(PostRepository::class);                 // auto-wire itself
$container->bind(CacheInterface::class, ObjectCache::class); // map interface -> implementation

$container->bind('mailer', function (Container $c) {     // factory closure
    return new Mailer($c->resolve(Logger::class));
});
```

### Singletons

Resolved once, then shared:

```php
$container->singleton(Database::class);

$container->singleton(SettingsRepository::class, function (Container $c) {
    return new SettingsRepository(get_option('my_plugin_settings', []));
});
```

### Existing objects

For things WordPress hands you rather than things you construct:

```php
global $wpdb;
$container->instance('wpdb', $wpdb);
```

> Note `bind()` accepts a closure, a class-string, or null. Passing an already-constructed object
> throws — use `instance()`, which makes the sharing semantics explicit.

### Aliases

```php
$container->singleton(Database::class);
$container->alias(Database::class, 'db');

$container->resolve('db') === $container->resolve(Database::class); // true
```

## Auto-wiring rules

When the container builds a class it inspects the constructor and resolves each parameter in this
order:

| # | Rule | Example |
|---|------|---------|
| 1 | An explicit runtime parameter, matched by name | `resolve(X::class, ['prefix' => 'wp_'])` |
| 2 | A contextual binding registered for the consuming class | `when(X::class)->needs('$prefix')->give('wp_')` |
| 3 | A class or interface type hint, resolved recursively | `__construct(Database $db)` |
| 4 | The parameter's default value | `string $prefix = 'wp_'` |
| 5 | `null`, if the parameter is nullable | `?CacheInterface $cache` |
| 6 | Otherwise — `BindingResolutionException` | `__construct(string $table)` |

An **unbound interface** is never guessed at. If exactly one implementation exists today, picking it
automatically would silently change behaviour the moment a second one appears, so the container
requires you to bind it.

## Contextual bindings

The same interface can resolve differently depending on who is asking:

```php
$container->when(PostRepository::class)
          ->needs(CacheInterface::class)
          ->give(ObjectCache::class);

$container->when(ReportGenerator::class)
          ->needs(CacheInterface::class)
          ->give(TransientCache::class);

// Primitives are addressed by parameter name, with a `$` prefix:
$container->when(TableGateway::class)
          ->needs('$tableName')
          ->give($wpdb->prefix . 'my_table');
```

## Injecting into hook callbacks

`call()` resolves a callable's parameters the same way it resolves a constructor. This is what lets
a hook callback take its dependencies as arguments:

```php
add_action('init', function () use ($container) {
    $container->call([PluginBootstrapper::class, 'boot']);
});
```

`PluginBootstrapper` is itself resolved through the container, so it gets its own constructor
dependencies, and `boot()` gets its parameters injected. All of these forms work:

```php
$container->call($closure);
$container->call([$object, 'method']);
$container->call([MyClass::class, 'method']);     // class resolved first
$container->call('MyClass@method');
$container->call('MyClass::staticMethod');
$container->call($invokableObject);
$container->call([Handler::class, 'handle'], ['postId' => 42]); // explicit args
```

## Decorating services

`extend()` wraps a binding's result — useful for adding logging or caching to a service you do not
own:

```php
$container->extend(PaymentGateway::class, function ($gateway, Container $c) {
    return new LoggingGateway($gateway, $c->resolve(Logger::class));
});
```

Extenders apply in registration order. Extending an already-resolved singleton wraps it in place.

## Service providers

Providers group related bindings and separate *declaring* services from *using* them:

```php
use DevLinkStudios\WPServiceContainer\ServiceProvider;

final class AdminServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bindings only. Never resolve or touch hooks here.
        $this->container->singleton(SettingsPage::class);
    }

    public function boot(): void
    {
        // Runs after every provider has registered.
        add_action('admin_menu', [$this->container->resolve(SettingsPage::class), 'register']);
    }
}
```

```php
$container->register(AdminServiceProvider::class);
$container->register(FrontendServiceProvider::class);
$container->boot();   // boots all providers, in registration order
```

The split matters because `register()` for every provider runs before `boot()` for any of them, so a
provider may depend on a binding declared by one loaded after it.

## Recommended plugin bootstrap

```php
<?php
/**
 * Plugin Name: My Plugin
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/vendor/autoload.php';

use DevLinkStudios\WPServiceContainer\Container;

function my_plugin_container(): Container
{
    static $container = null;

    if ($container === null) {
        $container = new Container();
        $container->register(CoreServiceProvider::class);
        $container->register(AdminServiceProvider::class);
    }

    return $container;
}

add_action('plugins_loaded', static function () {
    my_plugin_container()->boot();
});
```

Prefer a plugin-scoped accessor like the above over a global static container. Two plugins each
bundling this package get separate `Container` instances and cannot clobber each other's bindings —
which is the whole point of avoiding WordPress globals.

### A note on Composer conflicts

Composer cannot dedupe dependencies across independently installed plugins, so two plugins bundling
different versions of the same package will collide on class names. If you distribute publicly,
scope your vendor directory with [PHP-Scoper](https://github.com/humbug/php-scoper) or
[Mozart](https://github.com/coenjacobs/mozart) as a release step.

## Error handling

All exceptions extend `ContainerException`, which implements
`Psr\Container\ContainerExceptionInterface`.

| Exception | Raised when |
|-----------|-------------|
| `EntryNotFoundException` | The identifier is unregistered and is not an existing class. Also implements PSR-11 `NotFoundExceptionInterface`. |
| `BindingResolutionException` | The target is not instantiable (interface, abstract, private constructor), or a parameter cannot be satisfied. |
| `CircularDependencyException` | Resolution re-entered an identifier already being built. `getChain()` returns the cycle. |

```php
use DevLinkStudios\WPServiceContainer\Exceptions\CircularDependencyException;

try {
    $container->resolve(PostRepository::class);
} catch (CircularDependencyException $e) {
    error_log($e->getMessage());
    // Circular dependency detected while resolving [A]: A -> B -> A
}
```

## API reference

| Method | Purpose |
|--------|---------|
| `bind($abstract, $concrete = null)` | Register a transient binding. |
| `singleton($abstract, $concrete = null)` | Register a shared binding. |
| `instance($abstract, $instance)` | Register an existing object as shared. |
| `resolve($abstract, array $parameters = [])` | Build or fetch a service. |
| `get($id)` / `has($id)` | PSR-11 accessors. |
| `bound($abstract)` | Whether the identifier was explicitly registered. |
| `alias($abstract, $alias)` | Point one identifier at another. |
| `extend($abstract, Closure $extender)` | Decorate a binding's result. |
| `when($concrete)` | Begin a contextual binding. |
| `call($callback, array $parameters = [])` | Invoke a callable with injection. |
| `register($provider)` / `boot()` | Service provider lifecycle. |
| `isBooted()` | Whether `boot()` has run. |
| `bound($abstract)` | Whether the identifier was explicitly registered. |
| `getAlias($abstract)` | Follow an alias chain to its target identifier. |
| `getBindings()` | List every explicitly registered identifier. |
| `forget($abstract)` / `flush()` | Remove one binding / reset the container. |

On the builder returned by `when()`: `needs($abstract)` names the dependency, then `give($implementation)`
supplies a value, identifier, or factory closure — or `giveService($abstract)` for the common
"resolve this identifier" case.

## Testing

```bash
composer install
composer test
```

## Requirements

- PHP 7.4+
- `psr/container` ^2.0

> `psr/container` 1.x declares `get($id)` without a parameter type. Because this container declares
> `get(string $id)`, pairing it with 1.x would be a PHP signature violation (narrowing a parameter
> type is not permitted), so the constraint is 2.0-only. Note that WordPress plugins bundling
> conflicting PSR versions is exactly the collision that vendor-prefixing solves — see the
> [Composer conflicts](#a-note-on-composer-conflicts) note above.

## License

MIT. See [LICENSE](LICENSE).
