<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags;

use RoundlyConsulting\PackageToolkit\Concerns\RegistersBlueprintMacros;
use RoundlyConsulting\PackageToolkit\Package;
use RoundlyConsulting\PackageToolkit\PackageServiceProvider;
use RoundlyConsulting\Tags\Commands\TypesCommand;
use RoundlyConsulting\Tags\Support\TagsConfig;
use RoundlyConsulting\Tags\Types\TagTypeRegistry;

final class TagsServiceProvider extends PackageServiceProvider
{
    use RegistersBlueprintMacros;

    public function configurePackage(Package $package): void
    {
        $package
            ->name('tags')
            ->hasConfigFile()
            ->hasMigrations()
            ->hasTranslations()
            ->hasCommands([
                TypesCommand::class,
            ])
            ->contributesToAbout($this->aboutSection(...));
    }

    public function register(): void
    {
        parent::register();

        $this->app->singleton(TagTypeRegistry::class);
        $this->app->singleton(TagsManager::class);
    }

    public function boot(): void
    {
        parent::boot();

        // The toolkit's morphKey()/ownerKey() macros the migrations use.
        $this->registerBlueprintMacros();
    }

    /**
     * Shape, never content a host would keep private: key types, the default type, how many
     * types are defined and their names (truncated), and whether the API is on. Names are read,
     * never resolved, so a misdefined type cannot break `about`.
     *
     * @return array<string, string>
     */
    private function aboutSection(): array
    {
        $names = $this->app->make(TagTypeRegistry::class)->names();
        $shown = array_slice($names, 0, 5);

        return [
            'Key types' => TagsConfig::primaryKeyType()->value.' (tags), '.TagsConfig::keyType()->value.' (taggables, scopes)',
            'Default type' => TagsConfig::defaultType() ?? 'NONE',
            'Types' => count($names).($shown === [] ? '' : ' ('.implode(', ', $shown).(count($names) > 5 ? ', …' : '').')'),
            'Routes' => TagsConfig::routesEnabled() ? 'ON' : 'OFF',
        ];
    }
}
