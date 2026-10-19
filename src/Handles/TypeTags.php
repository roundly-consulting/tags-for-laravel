<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Handles;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Support\ScopeKey;
use RoundlyConsulting\Tags\Support\TagModel;
use RoundlyConsulting\Tags\Types\TagType;

/**
 * The catalog of one tag type, optionally within one scope: `Tags::type('genre')`.
 */
final readonly class TypeTags
{
    public function __construct(
        private TagType $type,
        private ?Model $scope = null,
        private bool $includeGlobal = true,
    ) {}

    /**
     * The same catalog within `$scope`: reads see the scope's tags plus the global ones (only the
     * scope's with `$includeGlobal: false`); writes touch the scope's own tags only.
     */
    public function within(?Model $scope, bool $includeGlobal = true): self
    {
        return new self($this->type, $scope, $includeGlobal);
    }

    /**
     * The resolved type.
     */
    public function definition(): TagType
    {
        return $this->type;
    }

    /**
     * Live tags of this type in the readable scopes.
     *
     * @return Builder<Tag>
     */
    public function query(): Builder
    {
        return TagModel::query()->where('type', $this->type->name)->whereIn('scope_key', $this->readScopeKeys());
    }

    /**
     * @return Collection<int, Tag>
     */
    public function all(): Collection
    {
        return $this->query()->ordered()->get();
    }

    /**
     * @return list<string>
     */
    private function readScopeKeys(): array
    {
        if ($this->scope === null || $this->type->globalOnly) {
            return [ScopeKey::GLOBAL];
        }

        $key = ScopeKey::for($this->scope);

        return $this->includeGlobal ? [$key, ScopeKey::GLOBAL] : [$key];
    }
}
