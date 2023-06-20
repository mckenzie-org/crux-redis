<?php

namespace Etlok\Crux\Redis\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class ResetRedis extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'crux:redis:reset';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reloads the redis data for all the models. Useful when model structure has changed.';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        Redis::flushall();
        $models = config('crux_redis.models');
        if($models) {
            foreach ($models as $model_type) {
                $model = $model_type['model'];
                $this->line("Loading ".$model);
                (new $model)->loadAll();
                $this->line('--- Complete ---');
            }
        }
        return 0;
    }
}
