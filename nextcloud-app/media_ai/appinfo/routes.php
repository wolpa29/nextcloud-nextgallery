<?php
declare(strict_types=1);
return ['routes'=>[
 ['name'=>'page#index','url'=>'/','verb'=>'GET'],
 ['name'=>'admin#config','url'=>'/admin/config','verb'=>'GET'],
 ['name'=>'admin#save','url'=>'/admin/config','verb'=>'POST'],
 ['name'=>'admin#status','url'=>'/admin/status','verb'=>'GET'],
 ['name'=>'admin#control','url'=>'/admin/control','verb'=>'POST'],
 ['name'=>'admin#logs','url'=>'/admin/logs','verb'=>'GET'],
 ['name'=>'admin#test','url'=>'/admin/test','verb'=>'POST'],
 ['name'=>'worker#config','url'=>'/worker/config','verb'=>'GET'],
 ['name'=>'worker#inventory','url'=>'/worker/inventory','verb'=>'GET'],
 ['name'=>'worker#resolve','url'=>'/worker/resolve','verb'=>'GET'],
 ['name'=>'worker#image','url'=>'/worker/image','verb'=>'GET'],
 ['name'=>'worker#publish','url'=>'/worker/publish','verb'=>'POST'],
], 'ocs'=>[
 ['name'=>'metadata#resolve','url'=>'/api/v1/resolve','verb'=>'GET'],
 ['name'=>'metadata#upsert','url'=>'/api/v1/metadata','verb'=>'POST'],
 ['name'=>'metadata#show','url'=>'/api/v1/metadata','verb'=>'GET'],
]];
