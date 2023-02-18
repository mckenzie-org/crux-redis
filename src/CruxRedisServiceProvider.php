<?php
namespace Etlok\Crux\Redis;

use Etlok\Crux\Redis\Console\BuildRedisController;
use Etlok\Crux\Redis\Console\BuildRedisDefinition;
use Etlok\Crux\Redis\Console\BuildRedisModel;
use Etlok\Crux\Redis\Console\BuildRedisResource;
use Etlok\Crux\Redis\Console\InstallCruxRedis;
use Etlok\Crux\Redis\Console\ResetRedis;
use Etlok\Crux\Redis\Console\SyncRedis;
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
                BuildRedisResource::class,
                ResetRedis::class,
                SyncRedis::class
            ]);

            $this->publishes([
                __DIR__.'/../config/config.php' => config_path('crux_redis.php'),
            ], 'config');

            $this->publishes([
                __DIR__.'/./Console/stubs' => base_path('stubs'),
            ], 'stubs');
        }

        Route::prefix(config('crux.api.prefix'))->middleware(config('crux.api.middleware'))->group(function() {
            $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        });

    }
}