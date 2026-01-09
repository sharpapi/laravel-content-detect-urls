<?php

declare(strict_types=1);

namespace SharpAPI\ContentDetectUrls;

use Illuminate\Support\ServiceProvider;

/**
 * @api
 */
class ContentDetectUrlsProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/sharpapi-content-detect-urls.php' => config_path('sharpapi-content-detect-urls.php'),
            ], 'sharpapi-content-detect-urls');
        }
    }

    /**
     * Register the application services.
     */
    public function register(): void
    {
        // Merge the package configuration with the app configuration.
        $this->mergeConfigFrom(
            __DIR__.'/../config/sharpapi-content-detect-urls.php', 'sharpapi-content-detect-urls'
        );
    }
}