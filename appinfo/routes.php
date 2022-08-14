<?php
return [
    'routes' => [
	   ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

       // API
	   ['name' => 'api#list', 'url' => '/api/list', 'verb' => 'GET'],
    ]
];
