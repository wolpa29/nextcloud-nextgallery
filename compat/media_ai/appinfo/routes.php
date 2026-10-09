<?php
declare(strict_types=1);
return ['routes'=>[
 ['name'=>'preferences#get','url'=>'/preferences','verb'=>'GET'],
 ['name'=>'preferences#save','url'=>'/preferences','verb'=>'POST'],
 ['name'=>'page#index','url'=>'/','verb'=>'GET'],
 ['name'=>'admin#config','url'=>'/admin/config','verb'=>'GET'],
 ['name'=>'admin#save','url'=>'/admin/config','verb'=>'POST'],
 ['name'=>'admin#folders','url'=>'/admin/folders','verb'=>'GET'],
 ['name'=>'admin#library','url'=>'/admin/library','verb'=>'GET'],
 ['name'=>'admin#status','url'=>'/admin/status','verb'=>'GET'],
 ['name'=>'admin#control','url'=>'/admin/control','verb'=>'POST'],
 ['name'=>'admin#logs','url'=>'/admin/logs','verb'=>'GET'],
 ['name'=>'admin#result','url'=>'/admin/result','verb'=>'GET'],
 ['name'=>'admin#maintenance','url'=>'/admin/maintenance','verb'=>'POST'],
 ['name'=>'admin#test','url'=>'/admin/test','verb'=>'POST'],
 ['name'=>'worker#config','url'=>'/worker/config','verb'=>'GET'],
 ['name'=>'worker#inventory','url'=>'/worker/inventory','verb'=>'GET'],
 ['name'=>'worker#resolve','url'=>'/worker/resolve','verb'=>'GET'],
 ['name'=>'worker#image','url'=>'/worker/image','verb'=>'GET'],
 ['name'=>'worker#reset','url'=>'/worker/reset','verb'=>'POST'],
 ['name'=>'worker#publish','url'=>'/worker/publish','verb'=>'POST'],
], 'ocs'=>[
 ['name'=>'metadata#resolve','url'=>'/api/v1/resolve','verb'=>'GET'],
 ['name'=>'metadata#upsert','url'=>'/api/v1/metadata','verb'=>'POST'],
 ['name'=>'metadata#statuses','url'=>'/api/v1/statuses','verb'=>'POST'],
 ['name'=>'metadata#show','url'=>'/api/v1/metadata','verb'=>'GET'],
]];
