<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Models;

use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\PackageToolkit\Support\LikeEscaper;
use RoundlyConsulting\PackageToolkit\Support\RawExpression;
use RoundlyConsulting\Sluggable\Concerns\HasSlug;
use RoundlyConsulting\Sluggable\Contracts\Sluggable;
use RoundlyConsulting\Sluggable\Definitions\SlugOptions;
use RoundlyConsulting\Tags\Concerns\HasConfigurableKey;
use RoundlyConsulting\Tags\Database\Factories\TagFactory;
use RoundlyConsulting\Tags\Exceptions\InvalidTagException;
use RoundlyConsulting\Tags\Relations\TagsRelation;
use RoundlyConsulting\Tags\Support\ScopeKey;
use RoundlyConsulting\Tags\Support\TagModel;
use RoundlyConsulting\Tags\Support\TagPath;
use RoundlyConsulting\Tags\Support\TagsConfig;
use RoundlyConsulting\Tags\Support\TagSlugs;
use RoundlyConsulting\Tags\Types\TagType;
use RoundlyConsulting\Tags\Types\TagTypeRegistry;
use RoundlyConsulting\Translatable\Concerns\HasTranslations;
use RoundlyConsulting\Translatable\Contracts\Translatable;

/**
 * A tag: one type, global or owned by one scope model, with a translatable name and description
 * and one slug per locale. Swappable through `tags.models.tag` (a host subclass of this class).
 *
 * The model carries reads only. Every write — creating, renaming, moving, deleting, tagging —
 * goes through `Tags::type($type)` and `Tags::for($model)`, which keep slugs, paths, usage counts
 * and events right under concurrent writers.
 *
 * @property int|string $id
 * @property string $type
 * @property string|null $scope_type
 * @property int|string|null $scope_id
 * @property string $scope_key
 * @property int|string|null $parent_id
 * @property string $path
 * @property int $depth
 * @property int $position
 * @property string|null $name
 * @property string|null $description
 * @property string|null $slug
 * @property string|null $color
 * @property string|null $icon
 * @property int $usage_count
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 * @property-read Tag|null $parent
 * @property-read Collection<int, Tag> $children
 * @property-read Model|null $scope
 */
class Tag extends Model implements Sluggable, Translatable
{
    // Both define resolveRouteBindingQuery(); sluggable's keeps the key-shape guard.
    use HasConfigurableKey, HasSlug {
        HasSlug::resolveRouteBindingQuery insteadof HasConfigurableKey;
    }

    /** @use HasFactory<TagFactory> */
    use HasFactory;

    use HasTranslations;
    use SoftDeletes;

    protected $guarded = [];

    /** @var list<string> */
    public array $translatable = ['name', 'description', 'slug'];

    /** @var list<string> */
    protected $hidden = ['scope_type', 'scope_key'];

    public function getTable(): string
    {
        return TagsConfig::table('tags');
    }

    /**
     * Per-locale slugs from the name; a `stableSlugs()` type keeps them on rename. Works on a
     * blank tag (sluggable builds one for lookups and index specs): no type means "follow".
     */
    public function slugOptions(): SlugOptions
    {
        $type = $this->getAttributes()['type'] ?? null;
        $stable = is_string($type) && (app(TagTypeRegistry::class)->find($type)->stableSlugs ?? false);

        return SlugOptions::make(TagSlugs::definition($stable));
    }

    /**
     * The tag's resolved type.
     */
    public function definition(): TagType
    {
        return app(TagTypeRegistry::class)->resolve($this->type);
    }

    /**
     * @return BelongsTo<Tag, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(TagModel::class(), 'parent_id');
    }

    /**
     * @return HasMany<Tag, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(TagModel::class(), 'parent_id')
            ->orderBy($this->qualifyColumn('position'))
            ->orderBy($this->getQualifiedKeyName());
    }

    /**
     * The owner of a scoped tag. Internal plumbing: never rendered as a class name.
     *
     * @return MorphTo<Model, $this>
     */
    public function scope(): MorphTo
    {
        return $this->morphTo('scope', 'scope_type', 'scope_id');
    }

    /**
     * @return HasMany<Tagging, $this>
     */
    public function taggings(): HasMany
    {
        return $this->hasMany(Tagging::class, 'tag_id');
    }

    /**
     * The models of one class carrying this tag — read-only, like `tags()`.
     *
     * @template TTaggable of Model
     *
     * @param  class-string<TTaggable>  $class
     * @return TagsRelation<TTaggable, $this>
     */
    public function taggedModels(string $class): TagsRelation
    {
        $instance = $this->newRelatedInstance($class);

        $relation = new TagsRelation(
            $instance->newQuery(),
            $this,
            'taggable',
            TagsConfig::table('taggables'),
            'tag_id',
            'taggable_id',
            $this->getKeyName(),
            $instance->getKeyName(),
            'taggedModels',
            true,
        );

        return $relation->using(Tagging::class)->withPivot('type', 'created_at');
    }

    /**
     * The ancestors, root first, in one query.
     *
     * @return Collection<int, Tag>
     */
    public function ancestors(): Collection
    {
        $keys = TagPath::ancestorKeys($this->path);

        if ($keys === []) {
            /** @var Collection<int, Tag> $none */
            $none = new Collection;

            return $none;
        }

        return TagModel::query()->whereKey($keys)->orderBy('depth')->get();
    }

    /**
     * The live descendants, as a query.
     *
     * @return Builder<Tag>
     */
    public function descendants(): Builder
    {
        return TagModel::query()->descendantsOf($this);
    }

    /**
     * The live siblings (same parent, type and scope), as a query.
     *
     * @return Builder<Tag>
     */
    public function siblings(): Builder
    {
        $query = TagModel::query()
            ->where('type', $this->type)
            ->where('scope_key', $this->scope_key)
            ->whereKeyNot($this->getKey())
            ->ordered();

        return $this->parent_id === null
            ? $query->whereNull('parent_id')
            : $query->where('parent_id', $this->parent_id);
    }

    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }

    /**
     * @param  Builder<Tag>  $query
     */
    public function scopeOfType(Builder $query, string|BackedEnum|TagType $type): void
    {
        $query->where($this->qualifyColumn('type'), TagTypeRegistry::nameOf($type));
    }

    /**
     * The scope's tags, plus the global ones unless `$includeGlobal` is false. A null scope means
     * global tags only.
     *
     * @param  Builder<Tag>  $query
     */
    public function scopeWithin(Builder $query, ?Model $scope, bool $includeGlobal = true): void
    {
        $keys = $scope === null ? [ScopeKey::GLOBAL] : ($includeGlobal ? [ScopeKey::for($scope), ScopeKey::GLOBAL] : [ScopeKey::for($scope)]);

        $query->whereIn($this->qualifyColumn('scope_key'), $keys);
    }

    /**
     * @param  Builder<Tag>  $query
     */
    public function scopeGlobal(Builder $query): void
    {
        $query->where($this->qualifyColumn('scope_key'), ScopeKey::GLOBAL);
    }

    /**
     * @param  Builder<Tag>  $query
     */
    public function scopeRoots(Builder $query): void
    {
        $query->whereNull($this->qualifyColumn('parent_id'));
    }

    /**
     * Every tag below `$tag` in its tree (and `$tag` itself with `$self`).
     *
     * @param  Builder<Tag>  $query
     */
    public function scopeDescendantsOf(Builder $query, Tag $tag, bool $self = false): void
    {
        $grammar = $query->getQuery()->getGrammar();
        $path = $grammar->wrap($this->qualifyColumn('path'));
        $prefix = LikeEscaper::escape(TagPath::subtreePrefix($tag)).'%';

        $query->where($this->qualifyColumn('type'), $tag->type)
            ->where($this->qualifyColumn('scope_key'), $tag->scope_key)
            ->where(function (Builder $inner) use ($path, $prefix, $tag, $self): void {
                $inner->whereRaw(new RawExpression("{$path} like ? escape ?"), [$prefix, '\\']);

                if ($self) {
                    $inner->orWhere($this->getQualifiedKeyName(), $tag->getKey());
                }
            });
    }

    /**
     * Position, then key: the order of siblings and of a flat list.
     *
     * @param  Builder<Tag>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy($this->qualifyColumn('position'))->orderBy($this->getQualifiedKeyName());
    }

    protected static function booted(): void
    {
        // The scope key is derived, never written by hand: the unique slug index binds it.
        static::saving(static function (Tag $tag): void {
            $tag->setAttribute('scope_key', ScopeKey::fromColumns($tag->scope_type, $tag->scope_id));
        });

        // A tag's type and owner never change: moving a tag between scopes is not supported.
        static::updating(static function (Tag $tag): void {
            foreach (['type', 'scope_type', 'scope_id'] as $attribute) {
                if ($tag->isDirty($attribute)) {
                    throw InvalidTagException::immutable($attribute);
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'depth' => 'integer',
            'position' => 'integer',
            'usage_count' => 'integer',
        ];
    }

    protected static function newFactory(): TagFactory
    {
        return TagFactory::new();
    }
}
