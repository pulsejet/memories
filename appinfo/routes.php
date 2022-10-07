<?php
return [
    'routes' => [
        // Days and folder API
        ['name' => 'page#main', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'page#folder', 'url' => '/folders/{path}', 'verb' => 'GET',
            'requirements' => [ 'path' => '.*' ],
            'defaults' => [ 'path' => '' ]
        ],
        ['name' => 'page#favorites', 'url' => '/favorites', 'verb' => 'GET'],
        ['name' => 'page#videos', 'url' => '/videos', 'verb' => 'GET'],
        ['name' => 'page#archive', 'url' => '/archive', 'verb' => 'GET'],
        ['name' => 'page#thisday', 'url' => '/thisday', 'verb' => 'GET'],
        ['name' => 'page#people', 'url' => '/people/{name}', 'verb' => 'GET',
            'requirements' => [ 'name' => '.*' ],
            'defaults' => [ 'name' => '' ]
        ],
        ['name' => 'page#tags', 'url' => '/tags/{name}', 'verb' => 'GET',
            'requirements' => [ 'name' => '.*' ],
            'defaults' => [ 'name' => '' ]
        ],

        // API
        ['name' => 'api#days', 'url' => '/api/days', 'verb' => 'GET'],
        ['name' => 'api#dayPost', 'url' => '/api/days', 'verb' => 'POST'],
        ['name' => 'api#day', 'url' => '/api/days/{id}', 'verb' => 'GET'],
        ['name' => 'api#tags', 'url' => '/api/tags', 'verb' => 'GET'],
        ['name' => 'api#faces', 'url' => '/api/faces', 'verb' => 'GET'],
        ['name' => 'api#facePreviews', 'url' => '/api/face-previews/{id}', 'verb' => 'GET'],
        ['name' => 'api#imageInfo', 'url' => '/api/info/{id}', 'verb' => 'GET'],
        ['name' => 'api#imageEdit', 'url' => '/api/edit/{id}', 'verb' => 'PATCH'],
        ['name' => 'api#archive', 'url' => '/api/archive/{id}', 'verb' => 'PATCH'],

        // Config API
        ['name' => 'api#setUserConfig', 'url' => '/api/config/{key}', 'verb' => 'PUT'],
    ]
];
