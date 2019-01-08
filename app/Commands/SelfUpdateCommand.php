<?php

namespace App\Commands;

use HnhDigital\LaravelConsoleSelfUpdate\SelfUpdateInterface;
use HnhDigital\LaravelConsoleSelfUpdate\SelfUpdateTrait;
use LaravelZero\Framework\Commands\Command;

class SelfUpdateCommand extends Command implements SelfUpdateInterface
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
        $this->setVersionsTagKey('path');
        $this->setHashSource(SelfUpdateInterface::CHECKSUM_TOP_LEVEL);
        $this->setHashPath('checksum');

        list($release, $tag) = $this->parseVersion(config('app.version'));

        $url = config('app.update-url');

        if ($release !== 'stable' && $release !== 'RELEASE') {
            $url .= '/'.$release;
        }

        $this->setUrl($url);

        $this->runSelfUpdate();
    }
}
