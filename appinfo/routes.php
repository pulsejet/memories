<?php
return [
    'routes' => [
        // Days and album API
        ['name' => 'page#main', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'page#album', 'url' => '/albums/{path}', 'verb' => 'GET',
			'requirements' => [
				'path' => '.*',
			],
			'defaults' => [
				'path' => '',
			]
	    ],

        // API
        ['name' => 'api#days', 'url' => '/api/days', 'verb' => 'GET'],
        ['name' => 'api#day', 'url' => '/api/days/{id}', 'verb' => 'GET'],
        ['name' => 'api#folder', 'url' => '/api/folder/{folder}', 'verb' => 'GET'],
        ['name' => 'api#folderDay', 'url' => '/api/folder/{folder}/{dayId}', 'verb' => 'GET'],

        // Config API
        ['name' => 'api#setUserConfig', 'url' => '/api/config/{key}', 'verb' => 'PUT'],
    ]
];
