<?php

namespace Etlok\Crux\Redis\Jobs;

use Illuminate\Support\Facades\Redis;

class SyncRedis
{
    public function __invoke()
    {
        for ($i = 0; $i < 100; $i++) {
            $this->write();
        }

    }

    public function write()
    {
        $count = Redis::llen("jobs:actions");
        if($count <= 0) {
            return;
        }
        $repo = Redis::rpop("jobs:actions");
        echo $repo.PHP_EOL;

        $repo_parts = explode('|',$repo);
        $element_parts = explode(':',$repo_parts[0]);
        $function_name = 'execute'.$element_parts[1];
        $class_name = $element_parts[0];
        (new $class_name)->$function_name($repo_parts[1]);
    }
}
