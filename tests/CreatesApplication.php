<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\BootProviders;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;
use RuntimeException;

trait CreatesApplication
{
    /**
     * Creates the application.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        // Routes are prefixed with Request::segment(1) when they are registered, so
        // load them under the test locale, as the web server does. This runs after
        // the console kernel's SetRequestForConsole, which would otherwise replace it.
        $app->beforeBootstrapping(BootProviders::class, function ($app) {
            $app->instance('request', Request::create('/' . static::LOCALE));
            Facade::clearResolvedInstance('request');
        });

        $app->make(Kernel::class)->bootstrap();

        $this->refuseNonTestDatabase();

        return $app;
    }

    /**
     * Stop before RefreshDatabase can wipe real data, for example when a cached
     * config means the database set in phpunit.xml is ignored.
     *
     * @return void
     */
    protected function refuseNonTestDatabase()
    {
        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        if (!Str::endsWith($database, '_testing')) {
            throw new RuntimeException(
                "Refusing to run tests against database [{$database}]: the name must end in _testing. "
                . 'Run php artisan config:clear if the config is cached.'
            );
        }
    }
}
