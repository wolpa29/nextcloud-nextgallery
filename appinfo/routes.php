<?php
return ['routes' => [
 ['name'=>'page#index', 'url'=>'/', 'verb'=>'GET'],
 ['name'=>'page#offline', 'url'=>'/offline', 'verb'=>'GET'],
 ['name'=>'page#worker', 'url'=>'/sw.js', 'verb'=>'GET'],
 ['name'=>'api#settings', 'url'=>'/api/settings', 'verb'=>'GET'],
 ['name'=>'api#save', 'url'=>'/api/settings', 'verb'=>'PUT'],
 ['name'=>'api#folders', 'url'=>'/api/folders', 'verb'=>'GET'],
 ['name'=>'api#summary', 'url'=>'/api/summary', 'verb'=>'GET'],
 ['name'=>'api#items', 'url'=>'/api/items', 'verb'=>'GET'],
 ['name'=>'api#map', 'url'=>'/api/map', 'verb'=>'GET'],
 ['name'=>'api#scan', 'url'=>'/api/scan', 'verb'=>'POST'],
 ['name'=>'media#preview', 'url'=>'/preview/{id}', 'verb'=>'GET', 'requirements'=>['id'=>'\\d+']],
 ['name'=>'media#original', 'url'=>'/original/{id}', 'verb'=>'GET', 'requirements'=>['id'=>'\\d+']],
]];
