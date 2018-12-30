<?php

namespace App\Commands;

use App\Traits\SharedTrait;
use DB;
use HnhDigital\CliHelper\CommandInternalsTrait;
use HnhDigital\CliHelper\FileSystemTrait;
use LaravelZero\Framework\Commands\Command;

class ConfigureCommand extends Command
{
    use CommandInternalsTrait, FileSystemTrait, SharedTrait;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'configure';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Configure profiles and connections';

    /**
     * Profiles.
     *
     * @var array
     */
    protected $profiles = [];

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $this->mainMenu();
    }

    /**
     * Main menu.
     *
     * @return void
     */
    private function mainMenu()
    {
        $profiles = [];

        $this->loadExistingProfiles();

        foreach ($this->profiles as $name => $profile_data) {
            $profiles[$name] = strtoupper($name);
        }

        $profiles[0] = 'Create new profile';

        $option = $this->menu('Configure profiles', $profiles)->open();

        if ($option === 0) {
            return $this->createProfile();
        }

        // Exit invoked.
        if (is_null($option)) {
            exit(0);
        }

        return $this->configureProfile($option);
    }

    /**
     * Create profile.
     *
     * @return void
     */
    private function createProfile()
    {
        while (true) {
            $name = $this->ask('Set the name of this new profile');

            $name = preg_replace('/[^a-z0-9_-]/', '', strtolower($name));

            if (empty($name)) {
                return $this->mainMenu();
            }

            if (in_array($name, $this->profiles)) {
                $this->error(sprintf('%s already exists.', $name));
                continue;
            }

            break;
        }

        // Create profile.
        $this->getConfigPath('profiles/'.$name);

        return $this->mainMenu();
    }

    /**
     * Configure profile.
     *
     * @param string $profile
     *
     * @return void
     */
    private function configureProfile($profile)
    {
        $option = $this->menu('Configuring '.strtoupper($profile), [
            'remote' => 'Remote hosts ('.count(array_get($this->profiles, $profile.'.remote', [])).')',
            'local'  => 'Local databases ('.count(array_get($this->profiles, $profile.'.local', [])).')',
            'delete' => 'Remove profile',
            'exit'   => 'Back',
        ])->disableDefaultItems()->open();

        switch ($option) {
            case 'remote':
                return $this->configureRemoteProfile($profile);
            case 'local':
                return $this->configureLocalProfile($profile);
            case 'delete':
                return $this->deleteProfile($profile);
            case 'exit':
                return $this->mainMenu();
        }
    }

    /**
     * Configure remote records for profile.
     *
     * @return void
     */
    private function configureRemoteProfile($profile)
    {
        $menu = [];

        foreach (array_get($this->profiles, $profile.'.remote', []) as $name => $remote_details) {
            $menu[$name] = sprintf(
                '%s / %s@%s %s',
                strtoupper($name),
                array_get($remote_details, 'username', ''),
                array_get($remote_details, 'host'),
                array_get($remote_details, 'working', false) ? '✔️' : '❌'
            );
        }

        $menu['create'] = 'Create new remote';
        $menu['exit'] = 'Back';

        $option = $this->menu('Configuring remote records for '.strtoupper($profile), $menu)
            ->disableDefaultItems()
            ->open();

        switch ($option) {
            case 'create':
                return $this->createRemoteProfile($profile);
            case 'exit':
                return $this->configureProfile($profile);
            default:
                return $this->updateRemoteProfile($profile, $option);
        }
    }

    /**
     * Update a remote profile.
     *
     * @return void
     */
    private function updateRemoteProfile($profile, $name)
    {
        $data = array_get($this->profiles, $profile.'.remote.'.$name, []);

        $public_key = array_get($data, 'public_key', $this->getUserHome('.ssh/id_rsa.pub'));
        $private_key = array_get($data, 'private_key', $this->getUserHome('.ssh/id_rsa'));

        $option = $this->menu(sprintf('Configuring %s / %s', strtoupper($name), strtoupper($profile)), [
            'name'        => 'Name: '.$name,
            'host'        => 'Host: '.array_get($data, 'host', ''),
            'port'        => 'Port: '.array_get($data, 'port', '22'),
            'username'    => 'Username: '.array_get($data, 'username', ''),
            'public_key'  => sprintf('Public Key: %s %s', $public_key, file_exists($public_key) ? '✔️' : '❌'),
            'private_key' => sprintf('Private Key: %s %s', $private_key, file_exists($private_key) ? '✔️' : '❌'),
            'test'        => sprintf('Test connection %s', array_get($data, 'working', false) ? '✔️' : '❌'),
            'exit'        => 'Back',
        ])->disableDefaultItems()->open();

        switch ($option) {
            case 'exit':
                return $this->configureRemoteProfile($profile);
            case 'test':
                return $this->testRemoteProfile($profile, $name);
            case 'name':
                return $this->updateRemoteProfileName($profile, $name, $option);
            default:
                return $this->updateRemoteProfileKey($profile, $name, $option);
        }
    }

    /**
     * Test the remote connection.
     *
     * @return void
     */
    private function testRemoteProfile($profile, $name)
    {
        $connection_works = false;

        $data = array_get($this->profiles, $profile.'.remote.'.$name, []);

        $public_key = array_get($data, 'public_key', $this->getUserHome('.ssh/id_rsa.pub'));
        $private_key = array_get($data, 'private_key', $this->getUserHome('.ssh/id_rsa'));

        $this->line('');
        $this->line(sprintf(' Host: %s', array_get($data, 'host', '')));

        if (!file_exists($public_key) || !file_exists($private_key)) {
            $this->error(' ❌ Public/private key does not exist.');

            // Force pause to show errors.
            $this->ask('Press any key to continue');

            return $this->updateRemoteProfile($profile, $name);
        }

        try {
            $connection = ssh2_connect(array_get($data, 'host', ''), array_get($data, 'port', 22));
            $auth = ssh2_auth_pubkey_file(
                $connection,
                array_get($data, 'username', ''),
                $public_key,
                $private_key
            );

            // Connection failed.
            if (!$connection) {
                $this->error(sprintf(' Connection %s failed ❌', $name));
            } else {
                $this->info(sprintf(' ✔️ Connection successful', $name));

                // Check binary exists.
                $stdio_stream = ssh2_exec($connection, 'command -v "mysql-helper" >/dev/null 2>&1; echo $?');
                stream_set_blocking($stdio_stream, true);
                $binary_exists = !(boolean) stream_get_contents($stdio_stream);
                fclose($stdio_stream);
 
                if (!$binary_exists) {
                    $this->error(sprintf(' ❌ mysql-helper binary does not exist', $name));
                } else {
                    $this->info(sprintf(' ✔ mysql-helper binary exists', $name));
                    $connection_works = true;
                }

                ssh2_disconnect($connection);
            }

        } catch (\Exception $e) {
            $this->error(sprintf('%s.', $e->getMessage()));
        }

        array_set($this->profiles, $profile.'.remote.'.$name.'.working', $connection_works);
        $this->saveProfile($profile, 'remote');

        if (!$connection_works) {

            if ($this->confirm('Test again?')) {
                return $this->testRemoteProfile($profile, $name);
            }

            return $this->updateRemoteProfile($profile, $name);
        }

        $this->ask('Press any key to continue');

        return $this->updateRemoteProfile($profile, $name);
    }

    /**
     * Update remote profile key.
     *
     * @return void
     */
    private function updateRemoteProfileName($profile, $name, $new_name)
    {
        // Profile name.
        while (true) {
            $new_name = $this->ask(sprintf('Profile name [%s]', $name));
            $new_name = preg_replace('/[^a-z0-9_-]/', '', strtolower($new_name));

            // New name is empty or invalid.
            if (empty($new_name)) {
                return $this->updateRemoteProfile($profile, $name);
            }

            // New name already exists.
            if (array_has($this->profiles, $profile.'.remote.'.$new_name)) {
                $this->error(sprintf('%s already exists.', $new_name));

                return $this->updateRemoteProfile($profile, $name);
            }

            break;
        }        

        // Create new entry.
        array_set($this->profiles, $profile.'.remote.'.$new_name, array_get($this->profiles, $profile.'.remote.'.$name));

        // Remove old entry.
        unset($this->profiles[$profile]['remote'][$name]);

        // Save to disk.
        $this->saveProfile($profile, 'remote');

        return $this->updateRemoteProfile($profile, $new_name);
    }

    /**
     * Update remote profile key.
     *
     * @return void
     */
    private function updateRemoteProfileKey($profile, $name, $key)
    {
        $default_value = '';

        switch ($key) {
            case 'public_key':
                $default_value = $this->getUserHome('.ssh/id_rsa.pub');
                break;
            case 'private_key':
                $default_value = $this->getUserHome('.ssh/id_rsa');
                break;
        }

        $value = array_get($this->profiles, $profile.'.remote.'.$name.'.'.$key, $default_value);

        $new_value = $this->ask(sprintf('%s [%s]', ucfirst($key), $value));

        if (empty($new_value)) {
            return $this->updateRemoteProfile($profile, $name);
        }

        // Expand tilde.
        if (substr($new_value, 0, 1) == '~') {
            $new_value = $this->getUserHome(substr($new_value, 2));
        }

        array_set($this->profiles, $profile.'.remote.'.$name.'.'.$key, $new_value);

        $this->saveProfile($profile, 'remote');

        return $this->updateRemoteProfile($profile, $name);
    }

    /**
     * Create remote profile.
     *
     * @return void
     */
    private function createRemoteProfile($profile)
    {
        // Profile name.
        while (true) {
            $name = $this->ask('Set the name of this new remote');

            $name = preg_replace('/[^a-z0-9_-]/', '', strtolower($name));

            if (empty($name)) {
                return $this->configureRemoteProfile($profile);
            }

            if (array_has($this->profiles, $profile.'.remote.'.$name)) {
                $this->error(sprintf('%s already exists.', $name));
                continue;
            }

            break;
        }

        // Host name.
        while (true) {
            $host = $this->ask('Host name');

            if (empty($host)) {
                continue;
            }

            break;
        }

        // Username
        while (true) {
            $username = $this->ask('Username');

            if (empty($username)) {
                continue;
            }

            break;
        }

        array_set($this->profiles, $profile.'.remote.'.$name, [
            'host'        => $host,
            'port'        => 22,
            'username'    => $username,
            'public_key'  => $this->getUserHome('.ssh/id_rsa.pub'),
            'private_key' => $this->getUserHome('.ssh/id_rsa'),
            'working'     => false,
        ]);

        $this->saveProfile($profile, 'remote');

        return $this->configureRemoteProfile($profile);
    }

    /**
     * Configure local profile.
     *
     * @return void
     */
    private function configureLocalProfile($profile)
    {
        $menu = [];

        foreach (array_get($this->profiles, $profile.'.local', []) as $name => $local_details) {
            $menu[$name] = sprintf(
                '%s / %s@%s %s',
                strtoupper($name),
                array_get($local_details, 'username', ''),
                array_get($local_details, 'host'),
                array_get($local_details, 'working', false) ? '✔️' : '❌'
            );
        }

        $menu['create'] = 'Create new local';
        $menu['exit'] = 'Back';

        $option = $this->menu('Configuring local records for '.strtoupper($profile), $menu)
            ->disableDefaultItems()
            ->open();

        switch ($option) {
            case 'create':
                return $this->createLocalProfile($profile);
            case 'exit':
                return $this->configureProfile($profile);
            default:
                return $this->updateLocalProfile($profile, $option);
        }
    }

    /**
     * Update a local profile.
     *
     * @return void
     */
    private function updateLocalProfile($profile, $name)
    {
        $data = array_get($this->profiles, $profile.'.local.'.$name, []);

        $option = $this->menu(sprintf('Configuring %s / %s', strtoupper($name), strtoupper($profile)), [
            'name'        => 'Name: '.$name,
            'host'        => 'Host: '.array_get($data, 'host', ''),
            'username'    => 'Username: '.array_get($data, 'username', ''),
            'password'    => 'Password: ******',
            'test'        => sprintf('Test connection %s', array_get($data, 'working', false) ? '✔️' : '❌'),
            'exit'        => 'Back',
        ])->disableDefaultItems()->open();

        switch ($option) {
            case 'exit':
                return $this->configureLocalProfile($profile);
            case 'test':
                return $this->testLocalProfile($profile, $name);
            case 'name':
                return $this->updateLocalProfileName($profile, $name, $option);
            default:
                return $this->updateLocalProfileKey($profile, $name, $option);
        }
    }

    /**
     * Update local profile key.
     *
     * @return void
     */
    private function updateLocalProfileName($profile, $name, $new_name)
    {
        // Profile name.
        while (true) {
            $new_name = $this->ask(sprintf('Profile name [%s]', $name));
            $new_name = preg_replace('/[^a-z0-9_-]/', '', strtolower($new_name));

            // New name is empty or invalid.
            if (empty($new_name)) {
                return $this->updateLocalProfile($profile, $name);
            }

            // New name already exists.
            if (array_has($this->profiles, $profile.'.local.'.$new_name)) {
                $this->error(sprintf('%s already exists.', $new_name));

                return $this->updateLocalProfile($profile, $name);
            }

            break;
        }        

        // Create new entry.
        array_set($this->profiles, $profile.'.local.'.$new_name, array_get($this->profiles, $profile.'.local.'.$name));

        // Remove old entry.
        unset($this->profiles[$profile]['local'][$name]);

        // Save to disk.
        $this->saveProfile($profile, 'local');

        return $this->updateLocalProfile($profile, $new_name);
    }

    /**
     * Update local profile key.
     *
     * @return void
     */
    private function updateLocalProfileKey($profile, $name, $key)
    {
        $value = array_get($this->profiles, $profile.'.local.'.$name.'.'.$key, '');

        $new_value = $this->ask(sprintf('%s [%s]', ucfirst($key), $value));

        if (empty($new_value)) {
            return $this->updateLocalProfile($profile, $name);
        }

        array_set($this->profiles, $profile.'.local.'.$name.'.'.$key, $new_value);

        $this->saveProfile($profile, 'local');

        return $this->updateLocalProfile($profile, $name);
    }

    /**
     * Update local profile password.
     *
     * @return void
     */
    private function testLocalProfile($profile, $name)
    {
        $this->loadDatabaseConnections($profile);

        try {
            DB::connection($name)->select('show databases');
            $connection_works = true;
            $this->info(sprintf(' ✔️ Connection successful', $name));
        } catch (\Exception $e) {
            $this->error($e->getMessage());
            $connection_works = false;
            $this->error(sprintf(' Connection %s failed ❌', $name));
        }

        array_set($this->profiles, $profile.'.local.'.$name.'.working', $connection_works);
        $this->saveProfile($profile, 'local');

        $this->ask('Press any key to continue');

        return $this->updateLocalProfile($profile, $name);
    }


    /**
     * Create local profile.
     *
     * @return void
     */
    private function createLocalProfile($profile)
    {
        // Profile name.
        while (true) {
            $name = $this->ask('Set the name of this new local');

            $name = preg_replace('/[^a-z0-9_-]/', '', strtolower($name));

            if (empty($name)) {
                return $this->configureLocalProfile($profile);
            }

            if (array_has($this->profiles, $profile.'.local.'.$name)) {
                $this->error(sprintf('%s already exists.', $name));
                continue;
            }

            break;
        }

        // Host name.
        while (true) {
            $host = $this->ask('Host name');

            if (empty($host)) {
                continue;
            }

            break;
        }

        // Username
        while (true) {
            $username = $this->ask('Username');

            if (empty($username)) {
                continue;
            }

            break;
        }

        // Password
        while (true) {
            $password = $this->secret('Password');

            if (empty($password)) {
                continue;
            }

            break;
        }

        array_set($this->profiles, $profile.'.local.'.$name, [
            'host'        => $host,
            'username'    => $username,
            'password'    => $password,
            'working'     => false,
        ]);

        $this->saveProfile($profile, 'local');

        return $this->testLocalProfile($profile, $name);
    }

    /**
     * Save profile.
     *
     * @return void
     */
    private function saveProfile($profile, $file)
    {
        $remote_path = $this->getConfigPath('profiles/'.$profile.'/'.$file.'.yml', true);
        $this->saveYamlFile($remote_path, array_get($this->profiles, $profile.'.'.$file));
    }

    /**
     * Delete profile.
     *
     * @return void
     */
    private function deleteProfile($profile)
    {
        $path = $this->getConfigPath('profiles/'.$profile);

        $this->removeDirectory($path);

        clearstatcache();
        $this->loadExistingProfiles();

        return $this->mainMenu();
    }
}
