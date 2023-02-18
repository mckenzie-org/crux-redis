<?php

namespace Etlok\Crux\Redis\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class InstallCruxRedis extends Command
{
    protected $signature = 'crux:redis:install';

    protected $description = 'Install the Crux Redis package';

    public function handle()
    {
        $this->info('Installing Crux Redis...');

        $this->info('Publishing configuration...');

        if (! $this->configExists('crux_redis.php')) {
            $this->publishConfiguration();
            $this->info('Published Configurations');
        } else {
            if ($this->shouldOverwriteConfig()) {
                $this->info('Overwriting Configuration file...');
                $this->publishConfiguration($force = true);
            } else {
                $this->info('Existing Configuration was not overwritten');
            }
        }

        $this->info('Crux Installation Complete!');
    }

    private function configExists($fileName)
    {
        return File::exists(config_path($fileName));
    }

    private function shouldOverwriteConfig()
    {
        return $this->confirm(
            'Config file already exists. Do you want to overwrite it?',
            false
        );
    }

    private function publishConfiguration($forcePublish = false)
    {
        $params = [
            '--provider' => "Etlok\Crux\Redis\CruxRedisServiceProvider",
            '--tag' => "config"
        ];

        if ($forcePublish === true) {
            $params['--force'] = true;
        }

        $this->call('vendor:publish', $params);
    }
}