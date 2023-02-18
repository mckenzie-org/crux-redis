<?php

namespace Etlok\Crux\Redis\Console;

use Illuminate\Foundation\Console\ModelMakeCommand;
use Symfony\Component\Console\Input\InputOption;

class BuildRedisModel extends ModelMakeCommand
{
    protected $name = 'build:redis:model';

    protected $description = 'Create a new crux model';

    protected $type = 'Model';

    protected function getStub()
    {
        $stub = '/stubs/crux_redis/model.php.stub';
        return $this->resolveStubPath($stub);
    }

    /**
     * Resolve the fully-qualified path to the stub.
     *
     * @param  string  $stub
     * @return string
     */
    protected function resolveStubPath($stub)
    {
        return file_exists($customPath = $this->laravel->basePath(trim($stub, '/')))
            ? $customPath
            : __DIR__.$stub;
    }

    public function handle()
    {
        parent::handle();
    }
}