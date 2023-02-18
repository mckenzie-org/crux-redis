<?php

namespace Etlok\Crux\Redis\Jobs;

use Illuminate\Support\Facades\Redis;

class ExecuteTasks
{
    public function __invoke()
    {
        $time = time();
        $tasks = Redis::zrangebyscore("external_tasks",0,$time);
        if($tasks) {
            foreach ($tasks as $task) {
                $this->executeTask($task);
                Redis::zrem('external_tasks',$task);
            }
        }

    }

    public function executeTask($task)
    {
        $repo_parts = explode('|',$task);
        $element_parts = explode(':',$repo_parts[0]);
        $function_name = 'execute'.$element_parts[1];
        $class_name = $element_parts[0];
        (new $class_name)->$function_name($repo_parts[1]);
        $exists = Redis::exists($repo_parts[1]);
        if(intval($exists) !== 1) {
            Redis::del($repo_parts[1]);
        }
    }
}
