<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;
use RoundlyConsulting\Tags\Support\TagsConfig;

return new class extends Migration
{
    public function up(): void
    {
        $table = TagsConfig::table('taggables');

        Schema::create(TagsConfig::table('taggables'), function (Blueprint $blueprint) use ($table): void {
            $blueprint->ownerKey('tag_id', KeyType::fromConfig('tags.primary_key_type'), index: false);
            $blueprint->morphKey('taggable', KeyType::fromConfig('tags.key_type'));

            // Denormalised from the tag's (immutable) type, so "this model's labels" needs no join.
            $blueprint->string('type', 64);
            $blueprint->timestamp('created_at')->nullable();

            $blueprint->unique(['tag_id', 'taggable_type', 'taggable_id'], $table.'_unique');
            $blueprint->index(['taggable_type', 'taggable_id', 'type'], $table.'_type_index');

            // Fires on a force delete only: a soft-deleted tag is detached by the package.
            $blueprint->foreign('tag_id')->references('id')->on(TagsConfig::table('tags'))->cascadeOnDelete();
        });
    }
};
