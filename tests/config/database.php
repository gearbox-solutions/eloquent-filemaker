<?php

return [
    'connections' => [
        'filemaker' => [
            'driver' => 'filemaker',
            'host' => env('DB_HOST', ''),
            'database' => env('DB_DATABASE', 'tester'),
            'username' => env('DB_USERNAME', 'dapitester'),
            'password' => env('DB_PASSWORD', 'dapitester'),
            'prefix' => env('DB_PREFIX', 'dapi-'),
            'version' => env('DB_VERSION', 'vLatest'),
            'protocol' => env('DB_PROTOCOL', 'https'),
        ],
    ],
];
