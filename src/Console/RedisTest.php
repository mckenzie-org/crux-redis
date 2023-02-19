<?php

namespace Etlok\Crux\Redis\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class RedisTest extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'crux:redis:test {command} {arguments}';

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
        $allowed_commands = config('crux_redis.allowed_test_commands');
        $command = $this->argument('cmd');
        if($allowed_commands !== null) {
            if(!in_array($command,$allowed_commands)) {
                return 1;
            }
        }

        $args = explode(",",$this->argument('arguments'));
        $redis = Redis::connection();
        $output = call_user_func_array([$redis,$command],$args);
        var_dump($output);
        return 0;
    }
}
