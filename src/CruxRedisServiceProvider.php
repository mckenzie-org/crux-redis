<?php
namespace Etlok\Crux\Redis;

use Etlok\Crux\Console\BuildRedisController;
use Etlok\Crux\Console\BuildRedisDefinition;
use Etlok\Crux\Console\BuildRedisModel;
use Etlok\Crux\Console\BuildRedisResource;
use Etlok\Crux\Console\InstallCruxRedis;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class CruxRedisServiceProvider extends ServiceProvider
{
    public function register()
    {
        //
    }

    public function boot()
    {
        if ($this->app->runningInConsole()) {

            $this->commands([
                InstallCruxRedis::class,
                BuildRedisController::class,
                BuildRedisModel::class,
                BuildRedisDefinition::class,
                BuildRedisResource::class
            ]);

            $this->publishes([
                __DIR__.'/../config/config.php' => config_path('crux_redis.php'),
            ], 'config');

            $this->publishes([
                __DIR__.'/./Console/stubs' => base_path('stubs'),
            ], 'stubs');

            Route::prefix(config('crux.api.prefix'))->middleware(config('crux.api.middleware'))->group(function() {
                $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
            });

        }

    }
}