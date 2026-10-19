<?php

declare(strict_types=1);

use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\TagsManager;
use RoundlyConsulting\Testing\Arch\ArchPresets;

$src = __DIR__.'/../../src';

ArchPresets::strictTypes('RoundlyConsulting\Tags');

/**
 * The two deliberate extension points: the swappable Tag model (pinned non-final below) and
 * TagsManager, which TagsFake extends so an injected manager still type-checks under the fake.
 */
ArchPresets::finalByDefault('RoundlyConsulting\Tags', [Tag::class, TagsManager::class]);

ArchPresets::swappableModelsAreNotFinal([Tag::class => 'tags.models.tag']);

ArchPresets::noLocalCryptoPrimitives('RoundlyConsulting\Tags');

ArchPresets::modelsResolveThroughSeam($src, 'Support', ['tags.models.tag']);

ArchPresets::factoriesResolveThroughSeam(__DIR__.'/../../database/factories', $src);

ArchPresets::morphColumnsUseTheSeam(__DIR__.'/../../database/migrations');

/**
 * The Dependency Policy as a test. No `alsoAllow`: `require` holds only php, extensions,
 * illuminate and roundly packages.
 */
ArchPresets::runtimeRequireIsWhitelisted(__DIR__.'/../../composer.json');

ArchPresets::noDebuggingLeftovers(srcDir: $src);

ArchPresets::modelsGoThroughTheFacade('RoundlyConsulting\Tags');
