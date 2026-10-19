<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Tags\Support\TagsConfig;

return new class extends Migration
{
    public function up(): void
    {
        $table = TagsConfig::table('locks');

        // One row per tree (type, scope): tree writes lock it first.
        Schema::create(TagsConfig::table('locks'), function (Blueprint $blueprint) use ($table): void {
            $blueprint->id();
            $blueprint->string('type', 64);
            $blueprint->string('scope_key', 300);
            $blueprint->timestamps();

            $blueprint->unique(['type', 'scope_key'], $table.'_unique');
        });
    }
};
