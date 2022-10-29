<?php

function getWildcard($param) {
    return [
        'requirements' => [ $param => '.*' ],
        'defaults' => [ $param => '' ]
    ];
}

return [
    'routes' => [
        // Vue routes for deep links
        ['name' => 'page#main', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'page#folder', 'url' => '/folders/{path}', 'verb' => 'GET', ...getWildcard('path')],
        ['name' => 'page#favorites', 'url' => '/favorites', 'verb' => 'GET'],
        ['name' => 'page#videos', 'url' => '/videos', 'verb' => 'GET'],
        ['name' => 'page#albums', 'url' => '/albums/{id}', 'verb' => 'GET', ...getWildcard('id')],
        ['name' => 'page#archive', 'url' => '/archive', 'verb' => 'GET'],
        ['name' => 'page#thisday', 'url' => '/thisday', 'verb' => 'GET'],
        ['name' => 'page#people', 'url' => '/people/{name}', 'verb' => 'GET', ...getWildcard('name')],
        ['name' => 'page#tags', 'url' => '/tags/{name}', 'verb' => 'GET', ...getWildcard('name')],

        // Public pages
        ['name' => 'page#sharedfolder', 'url' => '/s/{token}', 'verb' => 'GET'],

        // API Routes

        ['name' => 'days#days', 'url' => '/api/days', 'verb' => 'GET'],
        ['name' => 'days#day', 'url' => '/api/days/{id}', 'verb' => 'GET'],
        ['name' => 'days#dayPost', 'url' => '/api/days', 'verb' => 'POST'],

        ['name' => 'tags#tags', 'url' => '/api/tags', 'verb' => 'GET'],
        ['name' => 'tags#previews', 'url' => '/api/tag-previews', 'verb' => 'GET'],

        ['name' => 'albums#albums', 'url' => '/api/albums', 'verb' => 'GET'],

        ['name' => 'faces#faces', 'url' => '/api/faces', 'verb' => 'GET'],
        ['name' => 'faces#preview', 'url' => '/api/faces/preview/{id}', 'verb' => 'GET'],

        ['name' => 'image#info', 'url' => '/api/info/{id}', 'verb' => 'GET'],
        ['name' => 'image#edit', 'url' => '/api/edit/{id}', 'verb' => 'PATCH'],

        ['name' => 'archive#archive', 'url' => '/api/archive/{id}', 'verb' => 'PATCH'],

        // Config API
        ['name' => 'other#setUserConfig', 'url' => '/api/config/{key}', 'verb' => 'PUT'],

        // Service worker
        ['name' => 'other#serviceWorker', 'url' => '/service-worker.js', 'verb' => 'GET'],
    ]
];
