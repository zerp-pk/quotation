<?php

namespace Zerp\Quotation\Providers;

use Illuminate\Support\ServiceProvider;

class QuotationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $routesPath = __DIR__.'/../Routes/web.php';
        if (file_exists($routesPath)) {
            $this->loadRoutesFrom($routesPath);
        }

        $apiRoutesPath = __DIR__.'/../Routes/api.php';
        if (file_exists($apiRoutesPath)) {
            $this->loadRoutesFrom($apiRoutesPath);
        }

        // Scoped Swagger/OpenAPI docs for this module at /docs/quotation. Guarded
        // so the module still boots in a host app that has no Scramble.
        if (class_exists(\Dedoc\Scramble\Scramble::class)) {
            \Dedoc\Scramble\Scramble::registerApi('quotation', [
                'api_path' => 'api/quotation',
                'info' => ['version' => \Composer\InstalledVersions::getPrettyVersion('zerp/quotation') ?? '1.0.0', 'description' => 'Zerp Quotation module REST API for mobile and third-party clients.'],
                'ui' => ['title' => 'Zerp Quotation API'],
            ])->expose(ui: '/docs/quotation', document: '/docs/quotation.json');
        }

        $migrationsPath = __DIR__.'/../Database/Migrations';
        if (is_dir($migrationsPath)) {
            $this->loadMigrationsFrom($migrationsPath);
        }
    }

    public function register(): void
    {
        $this->app->register(EventServiceProvider::class);
    }
}