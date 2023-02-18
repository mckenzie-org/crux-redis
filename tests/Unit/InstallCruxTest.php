<?php


namespace Etlok\Crux\Redis\Tests\Unit;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Etlok\Crux\Redis\Tests\TestCase;

class InstallCruxTest extends TestCase
{
    /** @test */
    function the_install_command_copies_the_configuration()
    {
        // make sure we're starting from a clean state
        if (File::exists(config_path('crux_redis.php'))) {
            unlink(config_path('crux_redis.php'));
        }

        $this->assertFalse(File::exists(config_path('crux_redis.php')));

        Artisan::call('crux:redis:install');

        $this->assertTrue(File::exists(config_path('crux_redis.php')));
    }

    public function when_a_config_file_is_present_users_can_choose_to_not_overwrite_it()
    {
        // Given we have already have an existing config file
        File::put(config_path('crux_redis.php'), 'test contents');
        $this->assertTrue(File::exists(config_path('crux_redis.php')));

        // When we run the install command
        $command = $this->artisan('crux:redis:install');

        // We expect a warning that our configuration file exists
        $command->expectsConfirmation(
            'Config file already exists. Do you want to overwrite it?',
            // When answered with "no"
            'no'
        );

        // We should see a message that our file was not overwritten
        $command->expectsOutput('Existing Configuration was not overwritten');

        // Assert that the original contents of the config file remain
        $this->assertEquals('test contents', file_get_contents(config_path('crux_redis.php')));

        // Clean up
        unlink(config_path('crux_redis.php'));
    }

    /** @test */
    public function when_a_config_file_is_present_users_can_choose_to_do_overwrite_it()
    {
        // Given we have already have an existing config file
        File::put(config_path('crux_redis.php'), 'test contents');
        $this->assertTrue(File::exists(config_path('crux_redis.php')));

        // When we run the install command
        $command = $this->artisan('crux:redis:install');

        // We expect a warning that our configuration file exists
        $command->expectsConfirmation(
            'Config file already exists. Do you want to overwrite it?',
            // When answered with "yes"
            'yes'
        );

        // execute the command to force override
        $command->execute();

        $command->expectsOutput('Overwriting Configuration file...');

        // Assert that the original contents are overwritten
        $this->assertEquals(
            file_get_contents(__DIR__.'/../../config/config.php'),
            file_get_contents(config_path('crux_redis.php'))
        );

        // Clean up
        unlink(config_path('crux_redis.php'));
    }
}