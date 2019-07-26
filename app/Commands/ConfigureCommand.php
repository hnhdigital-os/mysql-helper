<?php

namespace App\Commands;

use App\Traits\SharedTrait;
use DB;
use HnhDigital\CliHelper\CommandInternalsTrait;
use HnhDigital\CliHelper\FileSystemTrait;
use LaravelZero\Framework\Commands\Command;

/**
 * This will suppress all the PMD warnings in
 * this class.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
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

        if (!$this->checkInstalledPackages()) {
            return;
        }

        $this->loadExistingProfiles();

        foreach (array_keys($this->profiles) as $name) {
            $profiles[$name] = strtoupper($name);
        }

        $profiles[0] = 'Create new profile';

        $option = $this->menu('Configure profiles', $profiles)->open();

        if ($option === 0) {
            return $this->createProfile();
        }

        // Exit invoked.
        if (is_null($option)) {
            return 0;
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

        return $this->configureProfile($name);
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
     * @param string $profile
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
     * @param string $profile
     * @param string $name
     *
     * @return void
     */
    private function updateRemoteProfile($profile, $name)
    {
        $data = array_get($this->profiles, $profile.'.remote.'.$name, []);

        $public_key = array_get($data, 'public_key', $this->getUserHome('.ssh/id_rsa.pub'));
        $private_key = array_get($data, 'private_key', $this->getUserHome('.ssh/id_rsa'));

        $menu = [
            'name'        => 'Name: '.$name,
            'host'        => 'Host: '.array_get($data, 'host', ''),
            'port'        => 'Port: '.array_get($data, 'port', '22'),
            'username'    => 'Username: '.array_get($data, 'username', ''),
            'method'      => 'Method: '.array_get($data, 'method', 'N/A').'',
            'methods'     => 'Available methods: '.implode(', ', array_get($data, 'methods', [])).' (enter to reload)',
        ];

        switch (array_get($data, 'method', '')) {
            case 'none':
                break;
            case 'password':
                $menu['password'] = 'Password: ******';
            break;
            case 'publickey':
                $menu['public_key'] = sprintf('Public Key: %s %s', $public_key, file_exists($public_key) ? '✔️' : '❌');
                $menu['private_key'] = sprintf('Private Key: %s %s', $private_key, file_exists($private_key) ? '✔️' : '❌');
            break;
        }

        $menu['test'] = sprintf('Test connection %s', array_get($data, 'working', false) ? '✔️' : '❌');
        $menu['exit'] = 'Back';

        $menu_title = sprintf(
            'Configuring %s / %s',
            strtoupper($name),
            strtoupper($profile)
        );

        $option = $this->menu($menu_title, $menu)
            ->disableDefaultItems()
            ->open();

        switch ($option) {
            case 'exit':
                return $this->configureRemoteProfile($profile);
            case 'methods':
                $methods = $this->sshAcceptedMethods(
                    array_get($data, 'host', ''),
                    array_get($data, 'port', 22),
                    array_get($data, 'username', '')
                );

                if ($methods === false) {
                    $methods = [];
                }

                array_set($this->profiles, $profile.'.remote.'.$name.'.methods', $methods);

                return $this->updateRemoteProfile($profile, $name);
            case 'method':
                $method = $this->askConnectionMethod(array_get($data, 'methods', []));

                array_set($this->profiles, $profile.'.remote.'.$name.'.method', $method);
                $this->saveProfile($profile, 'remote');

                return $this->updateRemoteProfile($profile, $name);
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
     * @param string $profile
     * @param string $name
     *
     * @return void
     */
    private function testRemoteProfile($profile, $name)
    {
        $connection_works = false;

        $data = array_get($this->profiles, $profile.'.remote.'.$name, []);

        $method = array_get($data, 'method', '');
        $settings = [];

        switch ($method) {
            case 'none':
                break;
            case 'agent':

                $settings = [
                    'username' => array_get($data, 'username', ''),
                ];
                break;
            case 'publickey':
                $public_key = array_get($data, 'public_key', $this->getUserHome('.ssh/id_rsa.pub'));
                $private_key = array_get($data, 'private_key', $this->getUserHome('.ssh/id_rsa'));

                if (!file_exists($public_key) || !file_exists($private_key)) {
                    $this->error(' ❌ Public/private key does not exist.');

                    // Force pause to show errors.
                    $this->ask('Press any key to continue');

                    return $this->updateRemoteProfile($profile, $name);
                }

                $settings = [
                    'username'    => array_get($data, 'username', ''),
                    'public_key'  => $public_key,
                    'private_key' => $private_key,
                ];

                break;
            case 'password':

                $settings = [
                    'username' => array_get($data, 'username', ''),
                    'password' => array_get($data, 'password', ''),
                ];
                break;
        }

        $this->line('');
        $this->line(sprintf(' Host: %s', array_get($data, 'host', '')));

        // Test connenction.
        $connection_works = $this->sshConnect(
            array_get($data, 'host', ''),
            array_get($data, 'port', 22),
            $method,
            $settings,
        );

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
     * Make the SSH connection and test the binary exists.
     *
     * @param string $host
     * @param string $port
     * @param string $method
     * @param array  $settings
     *
     * @return bool
     */
    private function sshConnect($host, $port, $method, $settings)
    {
        try {
            $session = ssh2_connect($host, $port);
        } catch (\Exception $e) {
            $this->error(sprintf('%s.', $e->getMessage()));

            return;
        }

        $ssh2_method = '';
        $method_args = [];

        switch ($method) {
            case 'none':
                $ssh2_method = 'ssh2_auth_none';
                break;
            case 'agent':
                $ssh2_method = 'ssh2_auth_agent';
                $method_args = [$settings['username']];
                break;
            case 'publickey':
                $ssh2_method = 'ssh2_auth_pubkey_file';
                $method_args = [$settings['username'], $settings['public_key'], $settings['private_key'], $settings['password'] ?? null];
                break;
            case 'password':
                $ssh2_method = 'ssh2_auth_password';
                $method_args = [$settings['username'], $settings['password']];
                break;
        }

        try {
            $ssh2_method($session, ...$method_args);

            // Connection failed.
            if (!$session) {
                $this->error(' Connection %s failed ❌');

                return false;
            }

            $this->info(' ✔️ Connection successful');

            // Check binary exists.
            $stream = ssh2_exec($session, 'command -v "mysql-helper" >/dev/null 2>&1; echo $?');
            stream_set_blocking($stream, true);

            $binary_exists = !(bool) intval(stream_get_contents($stream));

            fclose($stream);
            ssh2_disconnect($session);

            if (!$binary_exists) {
                $this->error(' ❌ mysql-helper binary does not exist on remote');
                $this->line('Please install to successfully establish connection');

                return false;
            }

            $this->info(' ✔ mysql-helper binary exists');

            return true;
        } catch (\Exception $e) {
            $this->error(sprintf('%s.', $e->getMessage()));
        }
    }

    /**
     * Update remote profile key.
     *
     * @param string $profile
     * @param string $name
     * @param string $new_name
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
        array_set(
            $this->profiles,
            $profile.'.remote.'.$new_name,
            array_get($this->profiles, $profile.'.remote.'.$name)
        );

        // Remove old entry.
        unset($this->profiles[$profile]['remote'][$name]);

        // Save to disk.
        $this->saveProfile($profile, 'remote');

        return $this->updateRemoteProfile($profile, $new_name);
    }

    /**
     * Update remote profile key.
     *
     * @param string $profile
     * @param string $name
     * @param string $key
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

        while (true) {
            $new_value = $this->ask(sprintf('%s [%s]', ucfirst($key), $value));

            if (empty($new_value)) {

                if (!file_exists($default_value)) {
                    $this->error(sprintf(' ❌ %s does not exist.', $default_value));
                    $this->ask('Press any key to continue');
                }

                return $this->updateRemoteProfile($profile, $name);
            }

            // Expand tilde.
            if (substr($new_value, 0, 1) == '~') {
                $new_value = $this->getUserHome(substr($new_value, 2));
            }

            if (!file_exists($new_value)) {
                $this->error(sprintf(' ❌ %s does not exist.', $new_value));
                continue;
            }

            break;
        }

        array_set($this->profiles, $profile.'.remote.'.$name.'.'.$key, $new_value);

        $this->saveProfile($profile, 'remote');

        return $this->updateRemoteProfile($profile, $name);
    }

    /**
     * Create remote profile.
     *
     * @param string $profile
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

        // Port.
        while (true) {
            $port = $this->ask('Port [22]');

            if (empty($port)) {
                $port = 22;
            }

            if (!is_int($port)) {
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

        $available_methods = $this->sshAcceptedMethods($host, $port ?? 22, $username);

        if ($available_methods === false) {
            $this->ask('Press any key to continue');

            return $this->createRemoteProfile($profile);
        }

        $method = $this->askConnectionMethod($methods);

        $profile_data = [
            'host'        => $host,
            'port'        => $port ?? 22,
            'username'    => $username,
            'method'      => $method,
            'methods'     => $available_methods,
            'working'     => false,
        ];

        switch ($connection) {
            case 'none':
                break;
            case 'agent':
                break;
            case 'publickey':
                $profile_data['public_key'] = $this->getUserHome('.ssh/id_rsa.pub');
                $profile_data['private_key'] = $this->getUserHome('.ssh/id_rsa');
                break;
            case 'password':
                while (true) {
                    $password = $this->ask('Password');

                    break;
                }
                $profile_data['password'] = $password;
                break;
        }

        array_set($this->profiles, $profile.'.remote.'.$name, $profile_data);

        $this->saveProfile($profile, 'remote');

        return $this->configureRemoteProfile($profile);
    }

    /**
     * Ask what connection method to use.
     *
     * @param array $methods
     *
     * @return array
     */
    private function askConnectionMethod($methods)
    {
        $accepted_methods = [];

        foreach ($methods as $value) {
            $accepted_methods[$value] = strtoupper($value);
        }

        return $this->menu('Select accepted method', $accepted_methods)->disableDefaultItems()->open();
    }

    /**
     * Check SSH accepted methods.
     *
     * @param string $host
     * @param int    $port
     * @param string $username
     *
     * @return array
     */
    private function sshAcceptedMethods($host, $port, $username)
    {
        try {
            $session = ssh2_connect($host, $port);
        } catch (\Exception $e) {
            $this->error(sprintf('%s.', $e->getMessage()));

            return false;
        }

        if (($mthods = ssh2_auth_none($session, $username)) === true) {
            return ['none'];
        }

        $mthods[] = 'agent';

        return $mthods;
    }

    /**
     * Configure local profile.
     *
     * @param string $profile
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
     * @param string $profile
     * @param string $name
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
     * @param string $profile
     * @param string $name
     * @param string $new_name
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
     * @param string $profile
     * @param string $name
     * @param string $key
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
     * @param string $profile
     * @param string $name
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
     * @param string $profile
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
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
     * @param string $profile
     * @param string $file
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
     * @param string $profile
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
