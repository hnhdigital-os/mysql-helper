<?php

namespace App\Traits;

trait SharedTrait
{
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

    /**
     * Execute remote  command.
     *
     * @return
     */
    private function execRemoteConnection($profile, $name, $command)
    {
        if (!array_get($this->profiles, $profile.'.remote.'.$name.'.working', false)) {
            $this->error('Remote profile has not been tested. Use configure command to fix.');

            return false;
        }

        $data = array_get($this->profiles, $profile.'.remote.'.$name, []);
        $public_key = array_get($data, 'public_key', $this->getUserHome('.ssh/id_rsa.pub'));
        $private_key = array_get($data, 'private_key', $this->getUserHome('.ssh/id_rsa'));

        try {
            $connection = ssh2_connect(array_get($data, 'host', ''), array_get($data, 'port', ''));

            ssh2_auth_pubkey_file(
                $connection,
                array_get($data, 'username', ''),
                $public_key,
                $private_key
            );

            // Check binary exists.
            $stream = ssh2_exec($connection, $command);
            $err_stream = ssh2_fetch_stream($stream, SSH2_STREAM_STDERR);

            stream_set_blocking($stream, true);
            stream_set_blocking($err_stream, true);

            $result = stream_get_contents($stream);
            $result_err = stream_get_contents($err_stream);

            if (!empty($result_err)) {
                $this->error($result_err);
            }

            fclose($stream);
            fclose($err_stream);
            ssh2_disconnect($connection);

            return $result;
        } catch (\Exception $e) {
            $this->error(sprintf('%s.', $e->getMessage()));

            return 1;
        }
    }
}
