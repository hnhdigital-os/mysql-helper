<?php

namespace App\Commands;

use App\Traits\SharedTrait;
use Artisan;
use Carbon\Carbon;
use DB;
use HnhDigital\CliHelper\CommandInternalsTrait;
use HnhDigital\CliHelper\FileSystemTrait;
use LaravelZero\Framework\Commands\Command;
use ZipArchive;

class RestoreCommand extends Command
{
    use CommandInternalsTrait, FileSystemTrait, SharedTrait;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'restore
                            {--profile=0 : Profile}
                            {--sql-path=0 : Path to absolute or relative SQL file (or zip)}
                            {--connection=0 : Local connection}
                            {--database=0 : Database}
                            {--backup=-1 : Backup before restoring}
                            {--force=-1 : Don\'t ask questions}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Restore a specific local database';

    /**
     * Execute the console command.
     *
     * @return mixed
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function handle()
    {
        $this->loadExistingProfiles();

        // Select profile.
        if (empty($profile = $this->option('profile'))) {
            $profile = $this->selectProfile();
        } elseif (!array_has($this->profiles, $profile)) {
            $this->error('Invalid profile supplied.');

            return 1;
        }

        // Select profile.
        if (empty($sql_path = $this->option('sql-path'))) {
            $sql_path = $this->selectSqlFile($profile);
        } elseif (!file_exists($sql_path)) {
            $this->error('Invalid file path.');

            return 1;
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

        // Select database.
        if (empty($database = $this->option('database'))) {
            $database = $this->selectDatabase($profile, $connection);
        } elseif (!empty($database)) {
            config(['database.connections.'.$connection.'.database' => $database]);

            try {
                DB::connection($connection)->select('SHOW TABLES');
            } catch (\Exception $e) {
                $this->selectDatabase($profile, $connection, $database);
            }
        }

        // Check if we need to backup the current database.
        if (($backup_first = $this->option('backup')) == -1) {
            $backup_first = $this->confirm('Backup before restoring?');
        }

        // Backup the current database before restoring.
        if ($backup_first) {
            Artisan::call('backup', [
                '--profile'     => $profile,
                '--connection'  => $connection,
                '--database'    => $database,
            ]);
        }

        // Run the restore.
        return $this->runRestore($profile, $connection, $sql_path, $database);
    }

    /**
     * Select SQL file.
     *
     * @return string
     */
    private function selectSqlFile($profile)
    {
        // Find all databases in this profile.
        $restore_file_path = $this->getConfigPath(sprintf('backups/%s', $profile));

        $restore_databases = glob($restore_file_path);

        $menu_options = [];

        foreach ($restore_databases as $path) {
            $menu_options[basename($path)] = basename($path);
        }

        // Select the source database.
        $selected_database = $this->menu('Select source database', $menu_options)->open();

        $restore_files = glob($restore_file_path.'/'.$selected_database.'/*.zip');

        $menu_options = [];

        foreach ($restore_files as $path) {
            $date = Carbon::createFromFormat('Y-m-d-H-i-s', pathinfo($path, PATHINFO_FILENAME));
            $menu_options[$path] = (string) $date;
        }

        krsort($menu_options);

        // Select the source SQL file.
        $selected_file = $this->menu($selected_database, $menu_options)
            ->disableDefaultItems()
            ->open();

        return $selected_file;
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
    private function selectDatabase($profile, $connection, $database = null)
    {
        config(['database.connections.'.$connection.'.database' => '']);
        DB::connection($connection)->reconnect();

        $databases = [
            'new' => 'NEW DATABASE',
        ];

        $available_databases = DB::connection($connection)
            ->select('SHOW DATABASES');

        foreach ($available_databases as $db) {
            $databases[$db->Database] = $db->Database;
        }

        $option = false;

        if (is_null($database)) {
            $option = $this->menu('Select database', $databases)->open();
        }

        // Use supplied database name.
        if (!is_null($database) && !in_array($database, $available_databases)) {
            $option = $database;
        }

        // Specify database.
        if ($option == 'new') {
            $option = $this->createNewDatabase($profile, $connection);
        }

        // Set the database on the connection.
        config(['database.connections.'.$connection.'.database' => $option]);

        return $option;
    }

    /**
     * Create new database.
     *
     * @return void
     */
    private function createNewDatabase($profile, $connection)
    {
        $database = $this->ask('Provide the database name');

        $database = preg_replace('/[^a-z0-9_]/', '', strtolower($database));

        if (empty($database)) {
            return $this->selectDatabase($profile, $connection);
        }

        return $database;
    }

    /**
     * Run restore.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function runRestore($profile, $connection, $sql_path, $database)
    {
        $this->line(' Source:');
        $this->info(sprintf(' %s', $sql_path));
        $this->line('');
        $this->line(' Database:');
        $this->info(sprintf(' %s', $database));

        // Check we want to proceed.
        if (!$this->option('--force')
            && !$this->confirm('Did you want to proceed?')) {
            return 0;
        }

        // Supplied SQL file path doesn't exist.
        if (!file_exists($sql_path)) {
            $this->error('Supplied SQL path does not exist.');

            return 1;
        }

        // Supplied SQL file is compressed.
        // Unzip the sql file before proceeding.
        if (pathinfo($sql_path, PATHINFO_EXTENSION) == 'zip') {
            $zip = new ZipArchive();
            if (!$zip->open($sql_path)) {
                $this->error('Could not open zip file.');

                return 1;
            }

            // More than one file in ZIP.
            // @todo Get user to select sql file.
            if ($zip->numFiles > 1) {
                $this->error('Source contains multiple files.');

                return 1;
            } elseif ($zip->numFiles == 1) {
                $this->line(' Extracting...');
                $this->line('');
                $sql_path .= '_extracted.sql';
                file_put_contents($sql_path, $zip->getFromIndex(0));
            } elseif ($zip->numFiles == 0) {
                $this->error('No files can be extracted.');

                return 1;
            }

            $zip->close();
        }

        // Drop database (so triggers etc are removed).
        DB::connection($connection)
            ->select(sprintf('DROP DATABASE %s', $database));

        // Re-create database.
        DB::connection($connection)
            ->select(sprintf('CREATE DATABASE %s DEFAULT CHARACTER SET utf8mb4', $database));

        $this->line(' Importing...');
        $this->line('');

        // Execute the import.
        exec(sprintf(
            'export MYSQL_PWD=%s; cat %s | pv --progress --size "%d" | mysql --user=%s --database=%s',
            array_get($this->profiles, $profile.'.local.'.$connection.'.password'),
            $sql_path,
            filesize($sql_path),
            array_get($this->profiles, $profile.'.local.'.$connection.'.username'),
            $database
        ));

        // Remove extracted SQL file.
        if (stripos($sql_path, '_extracted.sql') !== false) {
            unlink($sql_path);
        }

        $this->line('');
        $this->info('Done.');
        $this->line('');
    }
}
