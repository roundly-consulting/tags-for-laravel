<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;

/**
 * Host tables for the suite. Their keys follow `tags.key_type`, as a host's taggables and scopes
 * must; `uuid_articles` is always uuid-keyed (the mismatch fixture).
 */
return new class extends Migration
{
    public function up(): void
    {
        $type = KeyType::fromConfig('tags.key_type');

        $key = static function (Blueprint $table, string $column = 'id', bool $nullable = false) use ($type): void {
            if ($column === 'id') {
                match ($type) {
                    KeyType::BigInt => $table->id(),
                    KeyType::Uuid => $table->uuid('id')->primary(),
                    KeyType::Ulid => $table->ulid('id')->primary(),
                };

                return;
            }

            $definition = match ($type) {
                KeyType::BigInt => $table->unsignedBigInteger($column),
                KeyType::Uuid => $table->uuid($column),
                KeyType::Ulid => $table->ulid($column),
            };

            if ($nullable) {
                $definition->nullable();
            }
        };

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('is_admin')->default(false);
            $table->unsignedBigInteger('team_id')->nullable();
            $table->timestamps();
        });

        Schema::create('teams', function (Blueprint $table) use ($key): void {
            $key($table);
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('articles', function (Blueprint $table) use ($key): void {
            $key($table);
            $table->string('title')->default('');
            $key($table, 'team_id', true);
            $table->boolean('listed')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('notes', function (Blueprint $table) use ($key): void {
            $key($table);
            $table->string('body')->default('');
            $table->timestamps();
        });

        Schema::create('pages', function (Blueprint $table) use ($key): void {
            $key($table);
            $table->jsonb('name')->nullable();
            $table->jsonb('slug')->nullable();
            $table->timestamps();
        });

        Schema::create('uuid_articles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('title')->default('');
            $table->timestamps();
        });
    }
};
