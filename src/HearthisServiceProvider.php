<?php

namespace Ombabush\Hearthis;

use Illuminate\Support\ServiceProvider;
use Ombabush\Hearthis\Commands\HearthisFetchCommand;

class HearthisServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/hearthis.php', 'hearthis');

        // Bound as a singleton so a request that reads the same profile twice
        // shares one client (and therefore one cache decision).
        $this->app->singleton(Hearthis::class, fn () => new Hearthis);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/hearthis.php' => config_path('hearthis.php'),
            ], 'hearthis-config');

            $this->commands([HearthisFetchCommand::class]);
        }
    }
}
