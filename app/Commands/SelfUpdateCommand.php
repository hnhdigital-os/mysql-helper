<?php

namespace App\Commands;

use HnhDigital\LaravelConsoleSelfUpdate\SelfUpdateTrait;
use LaravelZero\Framework\Commands\Command;

class SelfUpdateCommand extends Command
{
    use SelfUpdateTrait;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'self-update
                            {--tag=0 : Set a specific tag to install}
                            {--check-version : Return the version of the binary}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Self-update this binary';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->parseVersion();

        $url = config('app.update-url');

        if ($this->release !== 'stable') {
            $url .= '/'.$this->release;
        }

        $this->setUrl($url);

        $this->runSelfUpdate();
    }
}
