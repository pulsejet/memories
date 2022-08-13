<?php
return [
    'routes' => [
	   ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

       // API
	   ['name' => 'api#list', 'url' => '/api/list', 'verb' => 'GET'],
       ['name' => 'api#listafter', 'url' => '/api/list/after/{time}', 'verb' => 'GET'],
       ['name' => 'api#listbefore', 'url' => '/api/list/before/{time}', 'verb' => 'GET'],
    ]
];
