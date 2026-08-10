<?php

declare(strict_types=1);

namespace DevLinkStudios\WPServiceContainer;

use Closure;
use DevLinkStudios\WPServiceContainer\Contracts\ContainerInterface;
use DevLinkStudios\WPServiceContainer\Exceptions\BindingResolutionException;
use DevLinkStudios\WPServiceContainer\Exceptions\CircularDependencyException;
use DevLinkStudios\WPServiceContainer\Exceptions\ContainerException;
use DevLinkStudios\WPServiceContainer\Exceptions\EntryNotFoundException;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * A PSR-11 service container with reflection-based auto-wiring.
 *
 * Deliberately has no WordPress coupling: it is safe to unit test without a
 * WordPress bootstrap, and safe to use outside of a request lifecycle.
 */
class Container implements ContainerInterface
{
    /** @var array<string, Closure|string> abstract => factory or delegate identifier */
    protected $bindings = [];

    /** @var array<string, mixed> abstract => resolved shared value */
    protected $instances = [];

    /** @var array<string, true> abstract => registered as shared */
    protected $singletons = [];

    /** @var array<string, string> alias => abstract */
    protected $aliases = [];

    /** @var array<string, list<Closure>> abstract => decorators */
    protected $extenders = [];

    /** @var array<string, array<string, mixed>> consumer => needs => implementation */
    protected $contextual = [];

    /**
     * Identifiers currently mid-resolution, innermost last.
     *
     * This is what makes cycles a catchable exception instead of a stack
     * overflow. It is popped in a `finally` so a failed resolve never leaves
     * the container poisoned for subsequent calls.
     *
     * @var list<string>
     */
    protected $buildStack = [];

    /** @var array<class-string<ServiceProvider>, ServiceProvider> */
    protected $providers = [];

    /** @var array<class-string<ServiceProvider>, true> */
    protected $bootedProviders = [];

    /** @var bool */
    protected $booted = false;

    // ---------------------------------------------------------------- binding

    public function bind(string $abstract, $concrete = null): void
    {
        $abstract = $this->normalize($abstract);

        $this->bindings[$abstract] = $this->normalizeConcrete($abstract, $concrete);

        // Re-binding must invalidate anything cached under the old definition,
        // including a previous singleton registration. Binding a name that is
        // currently an alias promotes it to a real binding, so that resolve()
        // does not silently follow the alias past it.
        unset($this->instances[$abstract], $this->singletons[$abstract], $this->aliases[$abstract]);
    }

    public function singleton(string $abstract, $concrete = null): void
    {
        $abstract = $this->normalize($abstract);

        $this->bindings[$abstract] = $this->normalizeConcrete($abstract, $concrete);
        unset($this->instances[$abstract], $this->aliases[$abstract]);
        $this->singletons[$abstract] = true;
    }

    public function instance(string $abstract, $instance)
    {
        $abstract = $this->normalize($abstract);

        foreach ($this->extenders[$abstract] ?? [] as $extender) {
            $instance = $extender($instance, $this);
        }

        unset($this->bindings[$abstract], $this->aliases[$abstract]);
        $this->singletons[$abstract] = true;
        $this->instances[$abstract] = $instance;

        return $instance;
    }

    public function alias(string $abstract, string $alias): void
    {
        $abstract = $this->normalize($abstract);
        $alias = $this->normalize($alias);

        if ($abstract === $alias) {
            throw new ContainerException(sprintf('[%s] cannot be aliased to itself.', $abstract));
        }

        $this->aliases[$alias] = $abstract;

        try {
            $this->getAlias($alias);
        } catch (ContainerException $e) {
            unset($this->aliases[$alias]);

            throw $e;
        }
    }

    public function extend(string $abstract, Closure $extender): void
    {
        $abstract = $this->getAlias($this->normalize($abstract));

        // Already built — decorate in place so existing holders of the
        // identifier see the wrapped service on their next resolve.
        if (array_key_exists($abstract, $this->instances)) {
            $this->instances[$abstract] = $extender($this->instances[$abstract], $this);

            return;
        }

        $this->extenders[$abstract][] = $extender;
    }

    public function when(string $abstract): ContextualBindingBuilder
    {
        return new ContextualBindingBuilder($this, [$this->normalize($abstract)]);
    }

    /**
     * @internal Called by {@see ContextualBindingBuilder::give()}.
     *
     * @param mixed $implementation
     */
    public function addContextualBinding(string $concrete, string $needs, $implementation): void
    {
        $this->contextual[$this->normalize($concrete)][$this->normalize($needs)] = $implementation;
    }

    // -------------------------------------------------------------- resolving

    public function resolve(string $abstract, array $parameters = [])
    {
        $abstract = $this->getAlias($this->normalize($abstract));

        // Runtime overrides make the result call-specific, so a cached shared
        // value must not be returned for — nor overwritten by — such a call.
        $isContextual = $parameters !== [];

        if (!$isContextual && array_key_exists($abstract, $this->instances)) {
            return $this->instances[$abstract];
        }

        if (in_array($abstract, $this->buildStack, true)) {
            throw CircularDependencyException::forChain($this->buildStack, $abstract);
        }

        $this->buildStack[] = $abstract;

        try {
            $object = $this->resolveConcrete($abstract, $parameters);

            foreach ($this->extenders[$abstract] ?? [] as $extender) {
                $object = $extender($object, $this);
            }
        } finally {
            array_pop($this->buildStack);
        }

        if (!$isContextual && isset($this->singletons[$abstract])) {
            $this->instances[$abstract] = $object;
        }

        return $object;
    }

    /**
     * PSR-11 accessor. Semantically identical to {@see resolve()} except that an
     * unknown identifier is reported as a PSR-11 NotFoundExceptionInterface.
     *
     * @return mixed
     */
    public function get(string $id)
    {
        if (!$this->has($id)) {
            throw EntryNotFoundException::forEntry($id);
        }

        return $this->resolve($id);
    }

    /**
     * PSR-11 probe: true when {@see get()} would not raise a not-found error.
     *
     * Note this returns true for any existing concrete class, because such a
     * class is auto-wirable even without an explicit binding. Use {@see bound()}
     * for the stricter "was this registered?" question.
     */
    public function has(string $id): bool
    {
        $abstract = $this->getAlias($this->normalize($id));

        return $this->bound($abstract) || class_exists($abstract);
    }

    public function bound(string $abstract): bool
    {
        $abstract = $this->getAlias($this->normalize($abstract));

        return isset($this->bindings[$abstract])
            || isset($this->singletons[$abstract])
            || array_key_exists($abstract, $this->instances);
    }

    public function call($callback, array $parameters = [])
    {
        if (is_string($callback) && strpos($callback, '@') !== false) {
            $callback = explode('@', $callback, 2);
        }

        if (is_string($callback) && strpos($callback, '::') !== false) {
            $callback = explode('::', $callback, 2);
        }

        // A class *name* as receiver means "resolve it, then call on it" — so the
        // receiver gets its own constructor dependencies injected too.
        if (is_array($callback) && is_string($callback[0]) && !$this->isStaticMethod($callback[0], $callback[1])) {
            $callback = [$this->resolve($callback[0]), $callback[1]];
        }

        if (is_object($callback) && !$callback instanceof Closure && method_exists($callback, '__invoke')) {
            $callback = [$callback, '__invoke'];
        }

        $reflector = $this->reflectCallback($callback);
        $arguments = $this->resolveDependencies($reflector, $parameters, $this->describeCallback($callback));

        if ($reflector instanceof ReflectionMethod) {
            return $reflector->invokeArgs(
                $reflector->isStatic() ? null : $callback[0],
                $arguments
            );
        }

        return $reflector->invokeArgs($arguments);
    }

    // -------------------------------------------------------------- providers

    /**
     * Register a service provider. Its register() runs immediately; its boot()
     * is deferred until {@see boot()} is called.
     *
     * @param ServiceProvider|class-string<ServiceProvider> $provider
     */
    public function register($provider): ServiceProvider
    {
        if (is_string($provider)) {
            if (!class_exists($provider)) {
                throw EntryNotFoundException::forEntry($provider);
            }

            $provider = new $provider($this);
        }

        if (!$provider instanceof ServiceProvider) {
            throw new InvalidArgumentException(sprintf(
                'Provider must be a %s; %s given.',
                ServiceProvider::class,
                is_object($provider) ? get_class($provider) : gettype($provider)
            ));
        }

        $key = get_class($provider);

        if (isset($this->providers[$key])) {
            return $this->providers[$key];
        }

        $this->providers[$key] = $provider;
        $provider->register();

        // Registering after boot() has already run — boot this one immediately
        // rather than leaving it half-initialised.
        if ($this->booted) {
            $provider->boot();
            $this->bootedProviders[$key] = true;
        }

        return $provider;
    }

    /**
     * Boot every registered provider. Safe to call more than once; each provider
     * is booted at most once.
     */
    public function boot(): void
    {
        foreach ($this->providers as $key => $provider) {
            if (!isset($this->bootedProviders[$key])) {
                $this->bootedProviders[$key] = true;
                $provider->boot();
            }
        }

        $this->booted = true;
    }

    public function isBooted(): bool
    {
        return $this->booted;
    }

    // ------------------------------------------------------------- management

    public function forget(string $abstract): void
    {
        $abstract = $this->getAlias($this->normalize($abstract));

        unset(
            $this->bindings[$abstract],
            $this->instances[$abstract],
            $this->singletons[$abstract],
            $this->extenders[$abstract],
            $this->contextual[$abstract]
        );

        foreach ($this->aliases as $alias => $target) {
            if ($target === $abstract) {
                unset($this->aliases[$alias]);
            }
        }
    }

    public function flush(): void
    {
        $this->bindings = [];
        $this->instances = [];
        $this->singletons = [];
        $this->aliases = [];
        $this->extenders = [];
        $this->contextual = [];
        $this->buildStack = [];
        $this->providers = [];
        $this->bootedProviders = [];
        $this->booted = false;
    }

    /**
     * Follow an alias chain to the identifier it ultimately points at.
     *
     * @throws ContainerException on an alias loop.
     */
    public function getAlias(string $abstract): string
    {
        $abstract = $this->normalize($abstract);
        $seen = [];

        while (isset($this->aliases[$abstract])) {
            if (isset($seen[$abstract])) {
                throw new ContainerException(sprintf(
                    'Alias loop detected: %s.',
                    implode(' -> ', array_merge(array_keys($seen), [$abstract]))
                ));
            }

            $seen[$abstract] = true;
            $abstract = $this->aliases[$abstract];
        }

        return $abstract;
    }

    /**
     * Every explicitly registered identifier.
     *
     * @return list<string>
     */
    public function getBindings(): array
    {
        return array_values(array_unique(array_merge(
            array_keys($this->bindings),
            array_keys($this->instances),
            array_keys($this->aliases)
        )));
    }

    // ---------------------------------------------------------------- internal

    /**
     * @param Closure|string|null $concrete
     * @return Closure|string
     */
    private function normalizeConcrete(string $abstract, $concrete)
    {
        if ($concrete === null) {
            return $abstract;
        }

        if ($concrete instanceof Closure || is_string($concrete)) {
            return $concrete instanceof Closure ? $concrete : $this->normalize($concrete);
        }

        throw new InvalidArgumentException(sprintf(
            'Concrete for [%s] must be a Closure, a class-string, or null; %s given. '
            . 'To register an existing object use instance().',
            $abstract,
            is_object($concrete) ? get_class($concrete) : gettype($concrete)
        ));
    }

    /**
     * @param array<string, mixed> $parameters
     * @return mixed
     */
    private function resolveConcrete(string $abstract, array $parameters)
    {
        if (!isset($this->bindings[$abstract])) {
            return $this->build($abstract, $parameters);
        }

        $concrete = $this->bindings[$abstract];

        if ($concrete instanceof Closure) {
            return $concrete($this, $parameters);
        }

        if ($concrete === $abstract) {
            return $this->build($abstract, $parameters);
        }

        // Delegating to another identifier — go back through resolve() so the
        // target's own bindings, extenders and shared flag are honoured.
        return $this->resolve($concrete, $parameters);
    }

    /**
     * @param array<string, mixed> $parameters
     * @return object
     */
    private function build(string $concrete, array $parameters)
    {
        try {
            $reflector = new ReflectionClass($concrete);
        } catch (ReflectionException $e) {
            throw EntryNotFoundException::forEntry($concrete);
        }

        if (!$reflector->isInstantiable()) {
            throw BindingResolutionException::notInstantiable($concrete, $this->buildStack);
        }

        $constructor = $reflector->getConstructor();

        if ($constructor === null) {
            return new $concrete();
        }

        return $reflector->newInstanceArgs(
            $this->resolveDependencies($constructor, $parameters, $concrete)
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return list<mixed>
     */
    private function resolveDependencies(
        ReflectionFunctionAbstract $reflector,
        array $overrides,
        string $context
    ): array {
        $arguments = [];

        foreach ($reflector->getParameters() as $parameter) {
            $name = $parameter->getName();

            if (array_key_exists($name, $overrides)) {
                $value = $overrides[$name];

                if ($parameter->isVariadic() && is_array($value)) {
                    foreach ($value as $item) {
                        $arguments[] = $item;
                    }
                } else {
                    $arguments[] = $value;
                }

                continue;
            }

            if ($this->hasContextualBinding($context, $parameter)) {
                $arguments[] = $this->resolveContextual($context, $parameter);

                continue;
            }

            $className = $this->parameterClassName($parameter);

            if ($className !== null) {
                try {
                    $arguments[] = $this->resolve($className);

                    continue;
                } catch (CircularDependencyException $e) {
                    // A cycle is always a programming error — never paper over
                    // it with a default value or null.
                    throw $e;
                } catch (ContainerException $e) {
                    if ($parameter->isDefaultValueAvailable()) {
                        $arguments[] = $parameter->getDefaultValue();

                        continue;
                    }

                    if ($parameter->allowsNull()) {
                        $arguments[] = null;

                        continue;
                    }

                    throw $e;
                }
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();

                continue;
            }

            if ($parameter->isVariadic()) {
                continue;
            }

            // Only trust allowsNull() when a type is actually declared;
            // an untyped parameter reports true and would silently get null.
            if ($parameter->getType() !== null && $parameter->allowsNull()) {
                $arguments[] = null;

                continue;
            }

            throw BindingResolutionException::unresolvableParameter($name, $context, $this->buildStack);
        }

        return $arguments;
    }

    /**
     * The class/interface name a parameter asks for, or null if it asks for a
     * builtin, a union/intersection type, or nothing at all.
     *
     * self/parent/static are resolved through the declaring function rather than
     * ReflectionParameter::getDeclaringClass(), which only exists on PHP 8.0+.
     */
    private function parameterClassName(ReflectionParameter $parameter): ?string
    {
        $type = $parameter->getType();

        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        $name = $type->getName();

        if ($name === 'self' || $name === 'static' || $name === 'parent') {
            $function = $parameter->getDeclaringFunction();

            if (!$function instanceof ReflectionMethod) {
                return null;
            }

            $class = $function->getDeclaringClass();

            if ($name === 'parent') {
                $class = $class->getParentClass();

                if ($class === false) {
                    return null;
                }
            }

            return $class->getName();
        }

        return $name;
    }

    private function hasContextualBinding(string $context, ReflectionParameter $parameter): bool
    {
        if (!isset($this->contextual[$context])) {
            return false;
        }

        $className = $this->parameterClassName($parameter);

        return ($className !== null && array_key_exists($className, $this->contextual[$context]))
            || array_key_exists('$' . $parameter->getName(), $this->contextual[$context]);
    }

    /**
     * @return mixed
     */
    private function resolveContextual(string $context, ReflectionParameter $parameter)
    {
        $className = $this->parameterClassName($parameter);
        $key = '$' . $parameter->getName();

        if ($className !== null && array_key_exists($className, $this->contextual[$context])) {
            $implementation = $this->contextual[$context][$className];
        } else {
            $implementation = $this->contextual[$context][$key];
        }

        if ($implementation instanceof Closure) {
            return $implementation($this);
        }

        // A string is only treated as an identifier when the parameter wants an
        // object; otherwise it is the literal value the caller asked for.
        if (is_string($implementation) && $className !== null) {
            return $this->resolve($implementation);
        }

        return $implementation;
    }

    /**
     * @param callable|array|string $callback
     */
    private function reflectCallback($callback): ReflectionFunctionAbstract
    {
        if (is_array($callback)) {
            return new ReflectionMethod($callback[0], $callback[1]);
        }

        if ($callback instanceof Closure || is_string($callback)) {
            return new ReflectionFunction($callback);
        }

        throw new InvalidArgumentException('Unsupported callable given to Container::call().');
    }

    /**
     * @param callable|array|string $callback
     */
    private function describeCallback($callback): string
    {
        if (is_array($callback)) {
            $receiver = is_object($callback[0]) ? get_class($callback[0]) : (string) $callback[0];

            return $receiver . '::' . $callback[1];
        }

        return is_string($callback) ? $callback : 'Closure';
    }

    private function isStaticMethod(string $class, string $method): bool
    {
        try {
            return (new ReflectionMethod($class, $method))->isStatic();
        } catch (ReflectionException $e) {
            return false;
        }
    }

    private function normalize(string $abstract): string
    {
        return ltrim($abstract, '\\');
    }
}
