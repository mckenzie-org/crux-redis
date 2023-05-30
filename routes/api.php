<?php
use Illuminate\Support\Facades\Route;

$resources = config('crux_redis.routes.api');

if($resources) {
    foreach ($resources as $resource=>$data) {
        $controller = $data['controller'];
        Route::get('/'.$resource.'/{id}/redis/representation', [$controller,'getRedisData']);
        Route::post('/'.$resource.'/{id}/load', [$controller,'load']);
        Route::post('/'.$resource.'/{id}/unload', [$controller,'load']);
        Route::post('/'.$resource.'/load/all', [$controller,'loadAll']);
        Route::post('/'.$resource.'/unload/all', [$controller,'unloadAll']);
        Route::post('/'.$resource.'/build/indexes', [$controller,'buildIndexes']);
        Route::delete('/'.$resource.'/clear/indexes', [$controller,'clearIndexes']);
    }
}