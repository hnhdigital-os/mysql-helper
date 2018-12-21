<?php

namespace App\Commands;

use HnhDigital\CliHelper\CommandInternalsTrait;
use HnhDigital\CliHelper\FileSystemTrait;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class ProfileCommand extends Command
{
    use CommandInternalsTrait, FileSystemTrait;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'profile';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Configure profiles to be used to manage mysql databases';

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
     * Load existing profiles.
     *
     * @return void
     */
    private function loadExistingProfiles()
    {
        $profiles_dir = $this->getDefaultWorkingDirectory('profiles');

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
        $this->getDefaultWorkingDirectory('profiles/'.$name);
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
            $menu[$name] = sprintf('%s / %s / %s', strtoupper($name), array_get($remote_details, 'host'), array_get($remote_details, 'db'));
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
        }
    }

    /**
     * Create remote profile.
     *
     * @return void
     */
    private function createRemoteProfile($profile)
    {
        $remote_profile = array_get($this->profiles, $profile.'.remote', []);

        // Profile name.
        while (true) {
            $name = $this->ask('Set the name of this new remote');

            $name = preg_replace('/[^a-z0-9_-]/', '', strtolower($name));

            if (empty($name)) {
                return $this->configureRemoteProfile($profile);
            }

            if (isset($remote_profile[$name])) {
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

        // Database profile name.
        while (true) {
            $db = $this->ask('Database profile name');

            if (empty($db)) {
                continue;
            }

            break;
        }

        $remote_profile[$name] = [
            'host' => $host,
            'db'   => $db,
        ];

        array_set($this->profiles, $profile.'.remote', $remote_profile);

        $remote_path = $this->getDefaultWorkingDirectory('profiles/'.$profile.'/remote.yml', true);
        $this->saveYamlFile($remote_path, $remote_profile);

        return $this->configureRemoteProfile($profile);
    }

    /**
     * Delete profile.
     *
     * @return void
     */
    private function deleteProfile($profile)
    {
        $path = $this->getDefaultWorkingDirectory('profiles/'.$profile);

        $this->removeDirectory($path);

        clearstatcache();
        $this->loadExistingProfiles();

        return $this->mainMenu();
    }
}
