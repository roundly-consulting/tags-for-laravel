<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Database\Factories;

use BackedEnum;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Support\ScopeKey;
use RoundlyConsulting\Tags\Support\TagModel;
use RoundlyConsulting\Tags\Support\TagPath;
use RoundlyConsulting\Tags\Types\TagTypeRegistry;

/**
 * Tags for tests and seeders. Each tag gets a unique name in the current locale; states set the
 * type, the scope, the parent (path and depth included) and the name.
 *
 * @extends Factory<Tag>
 */
final class TagFactory extends Factory
{
    protected $model = Tag::class;

    /**
     * The configured tag model, so a host's subclass is built.
     */
    public function modelName(): string
    {
        return TagModel::class();
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => 'tag',
            'name' => [app()->getLocale() => ucfirst($this->faker->word()).' '.$this->faker->unique()->numberBetween(100, 999999)],
            'path' => TagPath::ROOT,
            'depth' => 1,
            'position' => 0,
            'usage_count' => 0,
        ];
    }

    public function ofType(string|BackedEnum $type): self
    {
        return $this->state(['type' => TagTypeRegistry::nameOf($type)]);
    }

    public function within(?Model $scope): self
    {
        return $this->state(fn (): array => [
            'scope_type' => $scope?->getMorphClass(),
            'scope_id' => $scope?->getKey(),
            'scope_key' => ScopeKey::for($scope),
        ]);
    }

    /**
     * A child of `$parent`: same type and scope, the path and depth below it.
     */
    public function under(Tag $parent): self
    {
        return $this->state(fn (): array => [
            'type' => $parent->type,
            'scope_type' => $parent->scope_type,
            'scope_id' => $parent->scope_id,
            'parent_id' => $parent->getKey(),
            'path' => TagPath::under($parent),
            'depth' => TagPath::depthUnder($parent),
        ]);
    }

    /**
     * @param  string|array<string, string>  $name  a string is the current locale
     */
    public function named(string|array $name): self
    {
        return $this->state(['name' => is_string($name) ? [app()->getLocale() => $name] : $name]);
    }
}
