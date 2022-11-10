<?php

function getWildcard($param) {
    return [
        'requirements' => [ $param => '.*' ],
        'defaults' => [ $param => '' ]
    ];
}

function w($base, $param) {
    return array_merge($base, getWildcard($param));
}

return [
    'routes' => [
        // Vue routes for deep links
        ['name' => 'Page#main', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'Page#favorites', 'url' => '/favorites', 'verb' => 'GET'],
        ['name' => 'Page#videos', 'url' => '/videos', 'verb' => 'GET'],
        ['name' => 'Page#archive', 'url' => '/archive', 'verb' => 'GET'],
        ['name' => 'Page#thisday', 'url' => '/thisday', 'verb' => 'GET'],

        // Routes with params
        w(['name' => 'Page#folder', 'url' => '/folders/{path}', 'verb' => 'GET'], 'path'),
        w(['name' => 'Page#albums', 'url' => '/albums/{id}', 'verb' => 'GET'], 'id'),
        w(['name' => 'Page#people', 'url' => '/people/{name}', 'verb' => 'GET'], 'name'),
        w(['name' => 'Page#tags', 'url' => '/tags/{name}', 'verb' => 'GET'], 'name'),

        // Public folder share
        ['name' => 'Public#showShare', 'url' => '/s/{token}', 'verb' => 'GET'],
        [
			'name' => 'Public#showAuthenticate',
			'url' => '/s/{token}/authenticate/{redirect}',
			'verb' => 'GET',
		],
		[
			'name' => 'Public#authenticate',
			'url' => '/s/{token}/authenticate/{redirect}',
			'verb' => 'POST',
		],

        // API Routes
        ['name' => 'Days#days', 'url' => '/api/days', 'verb' => 'GET'],
        ['name' => 'Days#day', 'url' => '/api/days/{id}', 'verb' => 'GET'],
        ['name' => 'Days#dayPost', 'url' => '/api/days', 'verb' => 'POST'],

        ['name' => 'Tags#tags', 'url' => '/api/tags', 'verb' => 'GET'],
        ['name' => 'Tags#previews', 'url' => '/api/tag-previews', 'verb' => 'GET'],

        ['name' => 'Albums#albums', 'url' => '/api/albums', 'verb' => 'GET'],

        ['name' => 'Faces#faces', 'url' => '/api/faces', 'verb' => 'GET'],
        ['name' => 'Faces#preview', 'url' => '/api/faces/preview/{id}', 'verb' => 'GET'],

        ['name' => 'Image#info', 'url' => '/api/image/info/{id}', 'verb' => 'GET'],
        ['name' => 'Image#edit', 'url' => '/api/image/edit/{id}', 'verb' => 'PATCH'],
        ['name' => 'Image#setExif', 'url' => '/api/image/set-exif/{id}', 'verb' => 'PUT'],

        ['name' => 'Archive#archive', 'url' => '/api/archive/{id}', 'verb' => 'PATCH'],

        ['name' => 'Video#transcode', 'url' => '/api/video/transcode/{fileid}/{profile}', 'verb' => 'GET'],

        // Config API
        ['name' => 'Other#setUserConfig', 'url' => '/api/config/{key}', 'verb' => 'PUT'],

        // Service worker
        ['name' => 'Other#serviceWorker', 'url' => '/service-worker.js', 'verb' => 'GET'],
    ]
];
