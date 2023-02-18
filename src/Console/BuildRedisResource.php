<?php
namespace Etlok\Crux\Redis\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class BuildRedisResource extends Command {
    protected $signature = 'build:redis:resource {name}';

    protected $description = 'Install the Crux package';

    public function __construct()
    {
        parent::__construct();
    }
    public function handle()
    {
        $model = strtolower($this->argument('name'));
        $pl_model = Str::plural($model);
        $model_name = Str::ucfirst(Str::camel($model));

        $this->call('build:redis:model',[
            'name'=>$model_name,
            '--migration'=>true
        ]);
        $this->call('build:redis:controller',[
            'name'=>$model_name."Controller",
            '--model'=>$model_name,
            '--requests'=>'default'
        ]);
        $this->call('build:redis:definition',[
            'name'=>$model
        ]);

        $this->info('Resource built successfully!');
    }
}