<?php

namespace App\Commands;

use App\Traits\SharedTrait;
use Artisan;
use DB;
use HnhDigital\CliHelper\CommandInternalsTrait;
use HnhDigital\CliHelper\FileSystemTrait;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Output\BufferedOutput;

class CloneCommand extends Command
{
    use CommandInternalsTrait, FileSystemTrait, SharedTrait;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'clone
                            {source : Local or remote}
                            {--profile=0 : Profile}
                            {--remote-connection=0 : Remote connection}
                            {--src-database=0 : Database}
                            {--connection=0 : Local connection}
                            {--dest-database=0 : Database}
                            {--force=-1 : Disable being interactive}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Clone database from one to another';

    /**
     * Source database type.
     *
     * @var int
     */
    const DATABASE_TYPE_SOURCE = 0;

    /**
     * Destination database type.
     *
     * @var int
     */
    const DATABASE_TYPE_DESTINATION = 1;

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
        if (!$this->checkInstalledPackages()) {
            return;
        }

        $this->loadExistingProfiles();

        // Source mode.
        if (!in_array($source = $this->argument('source'), ['local', 'remote'])) {
            $this->error('Invalid source - local or remote.');

            return 1;
        }
        
        // Select profile.
        if (empty($profile = $this->option('profile'))) {
            $profile = $this->selectProfile();
        } elseif (!array_has($this->profiles, $profile)) {
            $this->error('Invalid profile supplied.');

            return 1;
        }

        if ($source == 'remote') {
            // Select remote connection profile.
            if (empty($remote_connection = $this->option('remote-connection'))) {
                $remote_connection = $this->selectRemoteConnection($profile);
            } elseif (!array_has($this->profiles, $profile.'.'.$connection)) {
                $this->error('Invalid connection profile supplied.');

                return 1;
            } elseif (!empty($remote_connection)) {
                $this->loadDatabaseConnections($profile);
            }
        }

        // Select connection profile.
        if (empty($connection = $this->option('connection'))) {
            $connection = $this->selectLocalConnection($profile);
        } elseif (!array_has($this->profiles, $profile.'.'.$connection)) {
            $this->error('Invalid connection profile supplied.');

            return 1;
        } elseif (!empty($connection)) {
            $this->loadDatabaseConnections($profile);
        }

        // Select source database.
        if (empty($source_database = $this->option('src-database'))) {
            $source_database = $this->selectDatabase($profile, $connection, self::DATABASE_TYPE_SOURCE);
        } elseif (!empty($source_database)) {
            config(['database.connections.'.$connection.'.database' => $source_database]);
            DB::connection($connection)->reconnect();

            try {
                DB::connection($connection)->select('SHOW TABLES');
            } catch (\Exception $e) {
                $this->error($e->getMessage());

                return 1;
            }
        }

        // Select destination database.
        if (empty($destination_database = $this->option('dest-database'))) {
            $destination_database = $this->selectDatabase($profile, $connection, self::DATABASE_TYPE_DESTINATION);
        } elseif (!empty($destination_database) && $source_database === $destination_database) {
            $this->error('Databases can not match.');

            return 1;
        }

        if ($source == 'remote') {
            $this->line('Not implemented.');

            return;
        }

        return $this->cloneLocalDatabases($profile, $connection, $source_database, $destination_database);
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
    private function selectLocalConnection($profile)
    {
        $menu_options = [];

        foreach (array_keys(array_get($this->profiles, $profile.'.local', [])) as $name) {
            $menu_options[$name] = strtoupper($name);
        }

        $option = $this->menu('Select local connection', $menu_options)->open();

        $this->loadDatabaseConnections($profile, $option);

        return $option;
    }

    /**
     * Select database.
     *
     * @return string
     */
    private function selectDatabase($profile, $connection, $type)
    {
        switch ($type) {
            case self::DATABASE_TYPE_DESTINATION:
                $title = 'Select destination database';
                break;
            case self::DATABASE_TYPE_SOURCE:
                $title = 'Select source database';
                break;
        }

        $databases = [];

        // Allow ability to specify new database.
        if ($type == self::DATABASE_TYPE_DESTINATION) {
            $databases['new'] = 'NEW DATABASE';
        }

        $available_databases = DB::connection($connection)
            ->select('SHOW DATABASES');

        foreach ($available_databases as $database) {
            $databases[$database->Database] = $database->Database;
        }

        $option = $this->menu($title, $databases)->open();

        // Specify database.
        if ($option == 'new') {
            $option = $this->createNewDatabase($profile, $connection, $type);
        }

        config(['database.connections.'.$connection.'.database' => $option]);

        return $database;
    }

    /**
     * Create new database.
     *
     * @return void
     */
    private function createNewDatabase($profile, $connection, $type)
    {
        $database = $this->ask('Provide the database name');

        $database = preg_replace('/[^a-z0-9_]/', '', strtolower($database));

        if (empty($database)) {
            return $this->selectDatabase($profile, $connection, $type);
        }

        return $database;
    }

    /**
     * Clone local database.
     *
     * @return int
     */
    private function cloneLocalDatabases($profile, $connection, $source_database, $destination_database)
    {
        // Check we want to proceed.
        if (!$this->option('force')
            && !$this->confirm('Did you want to proceed?')) {
            return 0;
        }

        $this->line('');
        $this->info('Generating restore file...');
        $this->line('');

        $backup_cmd = $this->getBinaryPath();
        $backup_cmd .= ' backup --profile=%s --connection=%s --database=%s';

        $output = [];

        $backup_path = exec(sprintf(
            $backup_cmd,
            $profile,
            $connection,
            $source_database
        ), $output, $exit_code);

        if ($exit_code > 0) {
            return $exit_code;
        }

        $backup_path = trim($backup_path);

        if (!file_exists($backup_path)) {
            $this->error('Backup file not generated.');

            return 1;
        }

        $this->line('');
        $this->info('Restoring...');
        $this->line('');

        // Restore to destination database.
        $exit_code = Artisan::call('restore', [
            '--profile'    => $profile,
            '--sql-path'   => $backup_path,
            '--connection' => $connection,
            '--database'   => $destination_database,
            '--backup'     => false,
            '--force'      => true,
        ]);

        // Remove backup we used to clone.
        unlink($backup_path);

        $this->line('');
        $this->info('Done.');
        $this->line('');
    }

    /**
     * Get binary path.
     *
     * @return string
     *
     * @SuppressWarnings(PHPMD.Superglobals)
     */
    private function getBinaryPath()
    {
        return realpath($_SERVER['argv'][0]) ?: $_SERVER['argv'][0];
    }
}
