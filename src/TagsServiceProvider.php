<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use RoundlyConsulting\PackageToolkit\Concerns\RegistersBlueprintMacros;
use RoundlyConsulting\PackageToolkit\Package;
use RoundlyConsulting\PackageToolkit\PackageServiceProvider;
use RoundlyConsulting\Tags\Commands\DoctorCommand;
use RoundlyConsulting\Tags\Commands\RepairCommand;
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
            ->hasRoutes('tags.php', 'tags.routes.enabled')
            ->hasCommands([
                TypesCommand::class,
                DoctorCommand::class,
                RepairCommand::class,
            ])
            ->contributesToAbout($this->aboutSection(...));
    }

    public function register(): void
    {
        parent::register();
        self::keepUnsetRoutesSwitchOff();

        $this->app->singleton(TagTypeRegistry::class);
        $this->app->singleton(TagsManager::class);
    }

    public function boot(): void
    {
        // Again before the toolkit reads the switch: config may change between register and boot.
        self::keepUnsetRoutesSwitchOff();

        parent::boot();

        // The toolkit's morphKey()/ownerKey() macros the migrations use.
        $this->registerBlueprintMacros();

        if (TagsConfig::routesEnabled()) {
            RateLimiter::for('tags-api', static fn (Request $request): Limit => Limit::perMinute(TagsConfig::rateLimit())
                ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

            RateLimiter::for('tags-suggest', static fn (Request $request): Limit => Limit::perMinute(TagsConfig::suggestRateLimit())
                ->by('suggest:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        }
    }

    /**
     * The JSON API is opt-in, but the toolkit reads a switch that is not set as on: a published
     * config that overrides only part of `routes` (the merge is shallow), `null`, or a bare
     * `env()` with the variable unset would load every route. Interim guard: replace it with
     * `hasRoutes(..., enabledByDefault: false)` once tags requires that toolkit release.
     */
    private static function keepUnsetRoutesSwitchOff(): void
    {
        $enabled = config('tags.routes.enabled');

        if ($enabled === null || (is_string($enabled) && trim($enabled) === '')) {
            config()->set('tags.routes.enabled', 'off');
        }
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
