<?php
return [
    'routes' => [
        ['name' => 'page#main', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'page#album', 'url' => '/albusms/{path}/{path1}', 'verb' => 'GET',
			'requirements' => [
				'path' => '.*',
                'path1' => '.*',
			],
			'defaults' => [
				'path' => '',
                'path1' => '',
			]
	    ],

        // API
        ['name' => 'api#days', 'url' => '/api/days', 'verb' => 'GET'],
        ['name' => 'api#day', 'url' => '/api/days/{id}', 'verb' => 'GET'],
        ['name' => 'api#folder', 'url' => '/api/folder/{folder}', 'verb' => 'GET'],
        ['name' => 'api#folderDay', 'url' => '/api/folder/{folder}/{dayId}', 'verb' => 'GET'],
    ]
];
