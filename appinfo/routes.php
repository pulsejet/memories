<?php
return [
    'routes' => [
	   ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

       // API
       ['name' => 'api#days', 'url' => '/api/days', 'verb' => 'GET'],
       ['name' => 'api#day', 'url' => '/api/days/{id}', 'verb' => 'GET'],
    ]
];
