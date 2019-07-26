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
                            {--profile=0 : Local Profile}
                            {--remote=0 : Remote connection}
                            {--remote-profile=0 : Remote profile}
                            {--remote-connection=0 : Remote connection}
                            {--src-database=0 : Database}
                            {--connection=0 : Local connection}
                            {--dest-database=0 : Database}';

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
            $profile = intval($this->option('no-interaction')) ? false : $this->selectProfile();
        } elseif (!array_has($this->profiles, $profile)) {
            $this->error('Invalid profile supplied.');

            return 1;
        }

        // Select remote connection profile.
        if ($source == 'remote') {
            if (empty($remote = $this->option('remote'))) {
                $remote = intval($this->option('no-interaction')) ? false : $this->selectConnection($profile, 'remote');
            } elseif (!array_has($this->profiles, $profile.'.remote.'.$remote)) {
                $this->error('Invalid remote connection profile supplied.');

                return 1;
            }

            if (empty($remote_profile = $this->option('remote-profile'))) {
                if (empty($remote_profile = intval($this->option('no-interaction'))
                    ? false : $this->selectRemoteProfile($profile, $remote))) {
                    return 1;
                }
            } elseif (!empty($remote_profile)) {
                // Check if this is a existing profile at remote.
            }

            if (empty($remote_connection = $this->option('remote-connection'))) {
                if (empty($remote_connection = intval($this->option('no-interaction'))
                    ? false : $this->selectRemoteConnection($profile, $remote, $remote_profile))) {
                    return 1;
                }
            } elseif (!empty($remote_connection)) {
                // Check if this is a existing connection at remote.
            }

            if (empty($source_database = $this->option('src-database'))) {
                if (empty($source_database = intval($this->option('no-interaction'))
                    ? false : $this->selectRemoteDatabase($profile, $remote, $remote_profile, $remote_connection))) {
                    return 1;
                }
            } elseif (!empty($source_database)) {
                // Check if this is an existing database at remote.
            }
        }

        // Select local connection profile.
        if (empty($local_connection = $this->option('connection'))) {
            if (empty($local_connection = intval($this->option('no-interaction'))
                ? false : $this->selectConnection($profile, 'local'))) {
                return 1;
            }
        } elseif (!array_has($this->profiles, $profile.'.local.'.$local_connection)) {
            $this->error('Invalid local connection profile supplied.');

            return 1;
        } elseif (!empty($local_connection)) {
            $this->loadDatabaseConnections($profile);
        }

        // Select source database.
        if ($source == 'local') {
            if (empty($source_database = $this->option('src-database'))) {
                $source_database = intval($this->option('no-interaction'))
                ? false : $this->selectLocalDatabase($profile, $local_connection, self::DATABASE_TYPE_SOURCE);
            } elseif (!empty($source_database)) {
                config(['database.connections.'.$local_connection.'.database' => $source_database]);
                DB::connection($local_connection)->reconnect();

                try {
                    DB::connection($local_connection)->select('SHOW TABLES');
                } catch (\Exception $e) {
                    $this->error($e->getMessage());

                    return 1;
                }
            }
        }

        // Select destination database.
        if (empty($destination_database = $this->option('dest-database'))) {
            $destination_database = intval($this->option('no-interaction'))
            ? false : $this->selectLocalDatabase($profile, $local_connection, self::DATABASE_TYPE_DESTINATION);
        } elseif (!empty($destination_database) && $source_database === $destination_database) {
            $this->error('Databases can not match.');

            return 1;
        }

        if ($source == 'remote') {
            return $this->cloneRemoteDatabases(
                $profile,
                $remote,
                $remote_profile,
                $remote_connection,
                $local_connection,
                $source_database,
                $destination_database
            );
        }

        return $this->cloneLocalDatabases($profile, $local_connection, $source_database, $destination_database);
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
    private function selectConnection($profile, $type)
    {
        $menu_options = [];

        foreach (array_keys(array_get($this->profiles, $profile.'.'.$type, [])) as $name) {
            $menu_options[$name] = strtoupper($name);
        }

        $option = $this->menu(sprintf('Select %s connection', $type), $menu_options)->open();

        $this->loadDatabaseConnections($profile, $option);

        return $option;
    }

    /**
     * Select remote profile.
     *
     * @return string
     */
    private function selectRemoteProfile($profile, $remote)
    {
        // Request profiles list from remote.
        $result = $this->execRemoteConnection(
            $profile,
            $remote,
            'mysql-helper display profiles --json'
        );

        $result = json_decode($result);

        $profiles = [];
        foreach ($result as $profile) {
            $profiles[$profile] = strtoupper($profile);
        }

        $option = $this->menu('Select remote profile', $profiles)->open();

        return $option;
    }

    /**
     * Select remote connections.
     *
     * @return string
     */
    private function selectRemoteConnection($profile, $remote, $remote_profile)
    {
        $connections_list_cmd = sprintf(
            'mysql-helper display connections --profile=%s --json',
            $remote_profile
        );

        // Request connections list from remote.
        $result = $this->execRemoteConnection(
            $profile,
            $remote,
            $connections_list_cmd
        );

        $result = json_decode($result);

        $connections = [];
        foreach ($result as $connection) {
            $connections[$connection] = strtoupper($connection);
        }

        $option = $this->menu('Select remote connection', $connections)->open();

        return $option;
    }

    /**
     * Select database.
     *
     * @return string
     */
    private function selectRemoteDatabase($profile, $connection, $remote_profile, $remote_connection)
    {
        $databases_list_cmd = sprintf(
            'mysql-helper display databases --profile=%s --connection=%s --json',
            $remote_profile,
            $remote_connection
        );

        // Request database list from remote.
        $result = $this->execRemoteConnection(
            $profile,
            $connection,
            $databases_list_cmd
        );

        $result = json_decode($result);

        $databases = [];
        foreach ($result as $database) {
            $databases[$database] = $database;
        }

        $option = $this->menu('Select source database', $databases)->open();

        return $option;
    }

    /**
     * Select database.
     *
     * @return string
     */
    private function selectLocalDatabase($profile, $connection, $type)
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
     * Clone remote database.
     *
     * @return int
     */
    private function cloneRemoteDatabases($profile, $remote, $remote_profile, $remote_connection, $local_connection, $source_database, $destination_database)
    {
        // Check we want to proceed.
        if (intval($this->option('no-interaction')) < 1
            && !$this->confirm('Did you want to proceed?')) {
            return 0;
        }

        $this->line('');
        $this->info('Generating restore file...');
        $this->line('');

        $backup_cmd = sprintf(
            'mysql-helper backup --profile=%s --connection=%s --database=%s --no-progress=1',
            $remote_profile,
            $remote_connection,
            $source_database
        );

        $result = $this->execRemoteConnection(
            $profile,
            $remote,
            $backup_cmd
        );

        if (empty($result)) {
            $this->error('Remote did not return a path to the backup file.');

            return 1;
        }

        $backup_path = trim($result);

        $this->line($backup_path);


        $this->line('');
        $this->info('Done.');
        $this->line('');
    }

    /**
     * Clone local database.
     *
     * @return int
     */
    private function cloneLocalDatabases($profile, $connection, $source_database, $destination_database)
    {
        // Check we want to proceed.
        if (intval($this->option('no-interaction')) < 1
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
}
