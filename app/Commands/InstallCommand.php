<?php

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;
use HnhDigital\CliHelper\SoftwareTrait;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

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
