<?php

namespace App\Commands;

use HnhDigital\CliHelper\SoftwareTrait;
use LaravelZero\Framework\Commands\Command;

class InstallCommand extends Command
{
    use SoftwareTrait;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'install';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Install system requirements';

    /**
     * System requirements.
     *
     * @var array
     */
    protected $packages = [
        'php-ssh2',
        'zip',
        'pv',
    ];

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        foreach ($this->packages as $package) {
            $this->packageInstall($package);
        }
    }
}
