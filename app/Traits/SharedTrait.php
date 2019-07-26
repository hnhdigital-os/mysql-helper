<?php

namespace App\Traits;

use HnhDigital\CliHelper\SoftwareTrait;

trait SharedTrait
{
    use SoftwareTrait;

    /**
     * Profiles.
     *
     * @var array
     */
    protected $profiles = [];

    /**
     * Load existing profiles.
     *
     * @return void
     */
    private function loadExistingProfiles()
    {
        $profiles_dir = $this->getConfigPath('profiles');

        $this->profiles = [];

        $profiles = array_filter(glob($profiles_dir.'/*'), 'is_dir');

        foreach ($profiles as $path) {
            $name = basename($path);

            $this->profiles[$name] = [
                'remote' => $this->loadYamlFile($path.'/remote.yml'),
                'local'  => $this->loadYamlFile($path.'/local.yml'),
            ];
        }
    }

    /**
     * Check required packages are installed to run this tool.
     *
     * @return bool
     */
    private function checkInstalledPackages()
    {
        $check = true;

        if (!function_exists('ssh2_connect')) {
            $this->error(' ❌ ssh2 is not enabled');
            $check = false;
        }

        if (!$this->binaryExists('zip')) {
            $this->error(' ❌ zip is not installed');
            $check = false;
        }

        if (!$this->binaryExists('pv')) {
            $this->error(' ❌ pv is not installed');
            $check = false;
        }

        if (!$check) {
            $this->line('');
            $this->error('Required packages need to be installed.');
            $this->line('Please run mysql-helper install to fix');
            $this->line('');
        }

        return $check;
    }

    /**
     * Load database connections.
     */
    private function loadDatabaseConnections($profile)
    {
        $settings = array_get($this->profiles, $profile.'.local');

        if (empty($settings)) {
            return;
        }

        foreach ($settings as $name => $connection) {
            $data = [
                'driver'    => array_get($connection, 'driver', 'mysql'),
                'host'      => array_get($connection, 'host', '127.0.0.1'),
                'port'      => array_get($connection, 'port', '3306'),
                'database'  => array_get($connection, 'database', ''),
                'username'  => array_get($connection, 'username', ''),
                'password'  => array_get($connection, 'password', ''),
                'charset'   => array_get($connection, 'charset', 'utf8mb4'),
                'collation' => array_get($connection, 'collation', 'utf8mb4_unicode_ci'),
                'prefix'    => '',
                'strict'    => false,
                'engine'    => null,
            ];

            config(['database.connections.'.$name => $data]);
        }
    }
}
