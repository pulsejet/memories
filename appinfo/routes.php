<?php
return [
    'routes' => [
	   ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

       // API
       ['name' => 'api#days', 'url' => '/api/days', 'verb' => 'GET'],
       ['name' => 'api#day', 'url' => '/api/days/{id}', 'verb' => 'GET'],
       ['name' => 'api#shared', 'url' => '/api/shared/{folder}', 'verb' => 'GET'],
       ['name' => 'api#sharedDay', 'url' => '/api/shared/{folder}/{dayId}', 'verb' => 'GET'],
    ]
];
