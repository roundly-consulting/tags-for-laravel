<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;
use RoundlyConsulting\Sluggable\DataTransferObjects\SlugIndexSpec;
use RoundlyConsulting\Sluggable\Schema\SlugIndexes;
use RoundlyConsulting\Tags\Support\TagsConfig;

return new class extends Migration
{
    public function up(): void
    {
        $table = TagsConfig::table('tags');
        $primaryKeyType = KeyType::fromConfig('tags.primary_key_type');

        Schema::create(TagsConfig::table('tags'), function (Blueprint $blueprint) use ($table, $primaryKeyType): void {
            // uuid/ulid keys declare their primary key explicitly: PostgreSQL refuses a self
            // foreign key onto a column that is not unique.
            match ($primaryKeyType) {
                KeyType::BigInt => $blueprint->id(),
                KeyType::Uuid => $blueprint->uuid('id')->primary(),
                KeyType::Ulid => $blueprint->ulid('id')->primary(),
            };

            $blueprint->string('type', 64);

            // The owner of a scoped tag. scope_key mirrors it as one NOT NULL string ('' for a
            // global tag), so the unique slug indexes bind global tags too: NULLs never collide.
            $blueprint->morphKey('scope', KeyType::fromConfig('tags.key_type'), nullable: true);
            $blueprint->string('scope_key', 300)->default('');

            $blueprint->ownerKey('parent_id', $primaryKeyType, nullable: true);
            $blueprint->string('path', 360)->default('/');
            $blueprint->unsignedTinyInteger('depth')->default(1);
            $blueprint->unsignedInteger('position')->default(0);

            $blueprint->translatable('name');
            $blueprint->translatable('description')->nullable();
            $blueprint->localizedSlug('slug')->nullable();

            $blueprint->string('color', 7)->nullable();
            $blueprint->string('icon', 64)->nullable();
            $blueprint->unsignedBigInteger('usage_count')->default(0);

            $blueprint->timestamps();
            $blueprint->softDeletes();

            $blueprint->index(['type', 'scope_key', 'parent_id', 'position'], $table.'_children_index');
            $blueprint->index(['type', 'scope_key', 'path'], $table.'_path_index');
            $blueprint->index(['type', 'scope_key', 'usage_count'], $table.'_usage_index');

            $blueprint->foreign('parent_id')->references('id')->on(TagsConfig::table('tags'))->restrictOnDelete();
        });

        // One unique index per supported locale over (type, scope_key, slug->locale), trashed
        // rows included: the shape the slug definition probes. Add a later locale's index with
        // `php artisan sluggable:indexes "RoundlyConsulting\Tags\Models\Tag"`.
        SlugIndexes::ensure(SlugIndexSpec::localeMap(
            $table,
            'slug',
            scope: ['type', 'scope_key'],
            includeTrashed: true,
            maxLength: TagsConfig::slugMaxLength(),
        ));
    }
};
