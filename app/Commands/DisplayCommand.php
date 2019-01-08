<?php

namespace App\Commands;

use App\Traits\SharedTrait;
use Artisan;
use DB;
use HnhDigital\CliHelper\CommandInternalsTrait;
use HnhDigital\CliHelper\FileSystemTrait;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Output\BufferedOutput;

class DisplayCommand extends Command
{
    use CommandInternalsTrait, FileSystemTrait, SharedTrait;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'display
                            {source : profiles|connections|databases}
                            {--profile=0 : Profile}
                            {--connection=0 : Connection}
                            {--json : Return as JSON encoded}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'List databases available to this connection.';

    /**
     * Execute the console command.
     *
     * @return int
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function handle()
    {
        $this->loadExistingProfiles();

        switch ($this->argument('source')) {
            case 'profiles':
                return $this->listProfiles();
            case 'connections':
                return $this->listConnections();
            case 'databases':
                return $this->listDatabases();
        }

        return 1;
    }

    /**
     * List profiles.
     *
     * @return int
     */
    private function listProfiles()
    {
        return $this->outputResult(array_keys($this->profiles));
    }

    /**
     * List profiles.
     *
     * @return int
     */
    private function listConnections()
    {
        // Select profile.
        if (empty($profile = $this->option('profile'))) {
            $profile = $this->selectProfile();
        } elseif (!array_has($this->profiles, $profile)) {
            $this->error('Invalid profile supplied.');

            return 1;
        }

        $connections = [];
        foreach (array_keys(array_get($this->profiles, $profile.'.local', [])) as $name) {
            $connections[] = $name;
        }

        return $this->outputResult($connections);
    }

    /**
     * Output JSON.
     *
     * @param array $output
     *
     * @return int
     */
    private function outputResult($output)
    {
        if ($this->option('json')) {
            echo json_encode($output);

            return 0;
        }

        foreach ($output as $value) {
            $this->line($value);
        }

        return 0;
    }

    /**
     * Output databases.
     *
     * @return int
     */
    private function listDatabases()
    {
        // Select profile.
        if (empty($profile = $this->option('profile'))) {
            $profile = $this->selectProfile();
        } elseif (!array_has($this->profiles, $profile)) {
            $this->error('Invalid profile supplied.');

            return 1;
        }

        // Select connection profile.
        if (empty($connection = $this->option('connection'))) {
            $connection = $this->selectConnection($profile);
        } elseif (!array_has($this->profiles, $profile.'.'.$connection)) {
            $this->error('Invalid connection profile supplied.');

            return 1;
        } elseif (!empty($connection)) {
            $this->loadDatabaseConnections($profile);
        }

        $available_databases = DB::connection($connection)
            ->select('SHOW DATABASES');

        $databases = [];
        foreach ($available_databases as $database) {
            $databases[] = $database->Database;
        }

        return $this->outputResult($databases);
    }

    /**
     * Select profile.
     *
     * @return string
     */
    private function selectProfile()
    {
        $menu_options = [];

        foreach (array_keys($this->profiles) as $name) {
            $menu_options[$name] = strtoupper($name);
        }

        $option = $this->menu('Select profile', $menu_options)->open();

        return $option;
    }

    /**
     * Select local connection.
     *
     * @return string
     */
    private function selectConnection($profile)
    {
        $menu_options = [];

        foreach (array_keys(array_get($this->profiles, $profile.'.local', [])) as $name) {
            $menu_options[$name] = strtoupper($name);
        }

        $option = $this->menu('Select local connection', $menu_options)->open();

        $this->loadDatabaseConnections($profile, $option);

        return $option;
    }
}
