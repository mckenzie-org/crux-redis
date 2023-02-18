<?php
namespace Etlok\Crux\Console;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class BuildRedisDefinition extends Command {

    /**
     * The filesystem instance.
     *
     * @var \Illuminate\Filesystem\Filesystem
     */
    protected $files;

    protected $signature = 'build:redis:definition {name}';

    protected $description = 'Build a definition file for the model';

    /**
     * Create a new controller creator command instance.
     *
     * @param  \Illuminate\Filesystem\Filesystem  $files
     * @return void
     */
    public function __construct(Filesystem $files)
    {
        parent::__construct();
        $this->files = $files;
    }

    protected function getStub()
    {
        $stub = '/stubs/crux_redis/definition.json.stub';
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
        $model = strtolower($this->argument('name'));

        $path = $this->getPath($model);
        $this->makeDirectory($path);
        $this->files->put($path, $this->buildDefinition($model));

        $this->info('Definition Created Successfully!');

    }

    public function buildDefinition($model)
    {
        $stub = $this->files->get($this->getStub());
        return $this->replaceModel($stub,$model);
    }

    /**
     * Replace the namespace for the given stub.
     *
     * @param  string  $stub
     * @param  string  $name
     * @return string
     */
    protected function replaceModel($stub, $model)
    {
        $pl_model = Str::plural($model);
        $model_name = Str::ucfirst(Str::camel($model));
        $searches = [
            ['__model__', '__pl_model__', '__title__']
        ];

        foreach ($searches as $search) {
            $stub = str_replace(
                $search,
                [$model, $pl_model, $model_name],
                $stub
            );
        }

        return $stub;
    }

    /**
     * Get the destination class path.
     *
     * @param  string  $name
     * @return string
     */
    protected function getPath($model)
    {
        $pl_model = Str::plural($model);
        $definitions_path = config('crux.definitions_path');

        return $this->laravel->basePath($definitions_path.'/'.$pl_model.'.json');
    }

    /**
     * Build the directory for the class if necessary.
     *
     * @param  string  $path
     * @return string
     */
    protected function makeDirectory($path)
    {
        if (! $this->files->isDirectory(dirname($path))) {
            $this->files->makeDirectory(dirname($path), 0777, true, true);
        }

        return $path;
    }
}