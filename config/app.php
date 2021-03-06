<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application. This value is used when the
    | framework needs to place the application's name in a notification or
    | any other location as required by the application or its packages.
    |
    */

    'name' => 'mysql-helper',

    /*
    |--------------------------------------------------------------------------
    | Workding Directory Name
    |--------------------------------------------------------------------------
    */
    'directory' => 'mysql-helper',

    /*
    |--------------------------------------------------------------------------
    | Application Version
    |--------------------------------------------------------------------------
    */

    'version' => 'RELEASE-VERSION',

    /*
    |--------------------------------------------------------------------------
    | Update URL
    |--------------------------------------------------------------------------
    */

    'update-url' => config('UPDATE_URL', 'https://hnhdigital-os.github.io/mysql-helper'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services your application utilizes. Should be true in production.
    |
    */

    'production' => false,

    /*
    |--------------------------------------------------------------------------
    | Autoloaded Service Providers
    |--------------------------------------------------------------------------
    |
    | The service providers listed here will be automatically loaded on the
    | request to your application. Feel free to add your own services to
    | this array to grant expanded functionality to your applications.
    |
    */

    'providers' => [
        App\Providers\AppServiceProvider::class,
    ],

];
