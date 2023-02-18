<?php

namespace Etlok\Crux\Redis\Console;

use Illuminate\Console\Command;

class SyncRedis extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:redis';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

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
        $sync = new \Etlok\Crux\Redis\Jobs\SyncRedis;
        $sync();
        return 0;
    }
}
