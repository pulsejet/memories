<?php
return [
    'routes' => [
        // Days and folder API
        ['name' => 'page#main', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'page#folder', 'url' => '/folders/{path}', 'verb' => 'GET',
            'requirements' => [
                'path' => '.*',
            ],
            'defaults' => [
                'path' => '',
            ]
        ],
        ['name' => 'page#favorites', 'url' => '/favorites', 'verb' => 'GET'],
        ['name' => 'page#videos', 'url' => '/videos', 'verb' => 'GET'],

        // API
        ['name' => 'api#days', 'url' => '/api/days', 'verb' => 'GET'],
        ['name' => 'api#day', 'url' => '/api/days/{id}', 'verb' => 'GET'],

        // Config API
        ['name' => 'api#setUserConfig', 'url' => '/api/config/{key}', 'verb' => 'PUT'],
    ]
];
