<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags;

use BackedEnum;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;
use RoundlyConsulting\Tags\Enums\TagOperation;
use RoundlyConsulting\Tags\Handles\TypeTags;
use RoundlyConsulting\Tags\Types\TagType;
use RoundlyConsulting\Tags\Types\TagTypeBuilder;
use RoundlyConsulting\Tags\Types\TagTypeRegistry;

/**
 * The package's public API: the root behind the `Tags` facade, injectable by its own
 * class-string and bound as a singleton. Types are declared here (`define`, `extend`), the
 * catalog of one type is reached through `type()`, and a model's tags through `for()` or the flat
 * verbs. Every write funnels through {@see self::perform()}, which resolves the action from the
 * container — so a host override applies and `Tags::fake()` records it.
 *
 * Not final on purpose: `TagsFake` extends it, so an injected manager still type-checks under
 * the fake.
 */
class TagsManager
{
    public function __construct(
        protected readonly Container $container,
        protected readonly TagTypeRegistry $registry,
    ) {}

    /**
     * Declare a tag type, from a service provider's `boot()`. A second definition of the name
     * throws; adjust another package's type with {@see self::extend()} or `tags.overrides`.
     */
    public function define(string|BackedEnum $type): TagTypeBuilder
    {
        return $this->registry->define($type);
    }

    /**
     * Adjust a type from host code, whatever the provider order.
     *
     * @param  Closure(TagTypeBuilder): mixed  $callback
     */
    public function extend(string|BackedEnum $type, Closure $callback): void
    {
        $this->registry->extend($type, $callback);
    }

    /**
     * Every defined type, resolved, keyed by name.
     *
     * @return Collection<string, TagType>
     */
    public function types(): Collection
    {
        return new Collection($this->registry->all());
    }

    /**
     * The catalog of one type: create, find, move, suggest… Unknown names throw.
     */
    public function type(string|BackedEnum $type): TypeTags
    {
        return new TypeTags($this->registry->resolve($type));
    }

    /**
     * Run one state-changing operation. Every flat verb, handle, builder and model method
     * funnels its terminal call through here, resolving the action from the container, so host
     * overrides apply and the fake records the call.
     *
     * @internal
     *
     * @template TAction of object
     * @template TResult
     *
     * @param  class-string<TAction>  $action
     * @param  Closure(TAction): TResult  $execute
     * @param  array<string, mixed>  $context
     * @return TResult
     */
    public function perform(TagOperation $operation, string $action, Closure $execute, array $context = []): mixed
    {
        return $execute($this->container->make($action));
    }

    /**
     * The type registry the manager reads.
     *
     * @internal
     */
    public function registry(): TagTypeRegistry
    {
        return $this->registry;
    }
}
