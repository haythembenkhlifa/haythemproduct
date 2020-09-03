<?php

namespace haythembenkhlifa\haythemproduct;

use Illuminate\Support\ServiceProvider;

class haythemproductServiceProvider extends ServiceProvider
{
    /**
     * Perform post-registration booting of services.
     *
     * @return void
     */
    public function boot()
    {
        // $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'haythembenkhlifa');
        // $this->loadViewsFrom(__DIR__.'/../resources/views', 'haythembenkhlifa');
        // $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        // $this->loadRoutesFrom(__DIR__.'/routes.php');

        // Publishing is only necessary when using the CLI.
        if ($this->app->runningInConsole()) {
            $this->bootForConsole();
        }
    }

    /**
     * Register any package services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/haythemproduct.php', 'haythemproduct');

        // Register the service the package provides.
        $this->app->singleton('haythemproduct', function ($app) {
            return new haythemproduct;
        });
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return ['haythemproduct'];
    }
    
    /**
     * Console-specific booting.
     *
     * @return void
     */
    protected function bootForConsole()
    {
        // Publishing the configuration file.
        $this->publishes([
            __DIR__.'/../config/haythemproduct.php' => config_path('haythemproduct.php'),
        ], 'haythemproduct.config');

        // Publishing the views.
        /*$this->publishes([
            __DIR__.'/../resources/views' => base_path('resources/views/vendor/haythembenkhlifa'),
        ], 'haythemproduct.views');*/

        // Publishing assets.
        /*$this->publishes([
            __DIR__.'/../resources/assets' => public_path('vendor/haythembenkhlifa'),
        ], 'haythemproduct.views');*/

        // Publishing the translation files.
        /*$this->publishes([
            __DIR__.'/../resources/lang' => resource_path('lang/vendor/haythembenkhlifa'),
        ], 'haythemproduct.views');*/

        // Registering package commands.
        // $this->commands([]);
    }
}
