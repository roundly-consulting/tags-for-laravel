<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\PackageToolkit\Enums\DatabaseDriver;
use RoundlyConsulting\Tags\Exceptions\InvalidTagsConfigurationException;
use RoundlyConsulting\Tags\TagsServiceProvider;
use RoundlyConsulting\Testing\Database\DriverMatrix;

$migrations = __DIR__.'/../../database/migrations';

/**
 * Two FK edges: tags.parent_id (a self edge) and taggables.tag_id. Table names are configurable,
 * so the expressions are mapped to their defaults.
 */
it('creates every foreign key target before the table that references it', function () use ($migrations): void {
    expect($migrations)->toHaveRunnableMigrationOrder(foreignKeys: 2, tableResolvers: [
        "TagsConfig::table('tags')" => 'tags',
        "TagsConfig::table('taggables')" => 'taggables',
        "TagsConfig::table('locks')" => 'tag_locks',
    ]);
});

it('never auto-loads its migrations — the host publishes them', function (): void {
    expect(TagsServiceProvider::class)->toNotAutoLoadMigrations();
});

it('publishes its migrations timestamp-injected into the host', function (): void {
    expect(TagsServiceProvider::class)->toPublishMigrationsTimestamped('tags-migrations', 3);
});

it('migrates forward only — no migration defines down()', function () use ($migrations): void {
    $files = glob($migrations.'/*.php') ?: [];

    expect($files)->toHaveCount(3);

    foreach ($files as $file) {
        expect((string) file_get_contents($file))->not->toContain('function down(');
    }
});

it('applies its migrations on postgres', function () use ($migrations): void {
    expect($migrations)->toApplyOnConnection('pgsql', migrations: 3);
})->skip(fn (): bool => ! test()->connectionAvailable('pgsql'), 'no postgres connection available')->group('pgsql');

it('rejects a child-before-parent order on postgres', function () use ($migrations): void {
    expect($migrations)->toRejectBrokenOrderOnConnection(fn (array $files): array => array_reverse($files), 'pgsql');
})->skip(fn (): bool => ! test()->connectionAvailable('pgsql'), 'no postgres connection available')->group('pgsql');

it('applies its migrations on mysql', function () use ($migrations): void {
    expect($migrations)->toApplyOnConnection('mysql', migrations: 3);
})->skip(fn (): bool => ! test()->connectionAvailable('mysql'), 'no mysql connection available')->group('mysql');

it('rejects a child-before-parent order on mysql', function () use ($migrations): void {
    expect($migrations)->toRejectBrokenOrderOnConnection(fn (array $files): array => array_reverse($files), 'mysql');
})->skip(fn (): bool => ! test()->connectionAvailable('mysql'), 'no mysql connection available')->group('mysql');

// Every index name a migration derives from a table name must fit the engine: MySQL refuses one
// past 64 characters, PostgreSQL truncates at 63 and refuses two names that end up equal. The
// migrations refuse such a table name up front, naming its key; the longest accepted one migrates.
test('table_names_whose_index_names_overflow_are_refused', function () use ($migrations): void {
    $longest = match (DriverMatrix::driver()) {
        'mysql', 'mariadb' => ['tags' => 38, 'taggables' => 32, 'locks' => 57],
        'pgsql' => ['tags' => 59, 'taggables' => 60, 'locks' => 62],
        default => ['tags' => 63, 'taggables' => 63, 'locks' => 63],
    };
    $files = glob($migrations.'/*.php') ?: [];
    sort($files);
    $name = static fn (int $length): string => substr(str_repeat('abcdefghij', 7), 0, $length);
    $migrate = static function () use ($files): void {
        Schema::dropAllTables();

        foreach ($files as $file) {
            (require $file)->up();
        }
    };

    expect($files)->toHaveCount(3);

    foreach ($longest as $table => $length) {
        config()->set('tags.tables', ['tags' => 'tags', 'taggables' => 'taggables', 'locks' => 'tag_locks', $table => $name($length)]);
        $migrate();

        expect(Schema::hasTable($name($length)))->toBeTrue();

        if ($length < 63) {
            config()->set('tags.tables.'.$table, $name($length + 1));

            expect($migrate)->toThrow(InvalidTagsConfigurationException::class, 'tags.tables.'.$table);
        }
    }

    Schema::dropAllTables();
});

/**
 * The driver-truth pin: a leg that quietly stayed on SQLite fails here instead of passing as a
 * "postgres" or "mysql" run.
 */
it('runs on the driver the environment declares', function (): void {
    expect(DatabaseDriver::current()->value)->toBe(DriverMatrix::driver());
});
