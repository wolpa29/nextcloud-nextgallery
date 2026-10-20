<?php
/** Public/synthetic disposable instance fixtures; never usable on production. */
declare(strict_types=1);
require '/var/www/html/lib/base.php';
if(!is_file('/test-output/ISOLATED_SYNTHETIC_TEST')||!str_contains((string)\OCP\Server::get(\OCP\IConfig::class)->getSystemValueString('dbhost'),'faces34'))throw new RuntimeException('Isolated People fixture guard failed');
set_exception_handler(function(\Throwable $e){fwrite(STDERR,get_class($e).': '.$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);});
use OCP\Server;
use OCA\Nextgallery\AI\Service\{Settings,State,Faces};
use OCA\Nextgallery\Service\Library;
$phase=$argv[1]??'';$config=Server::get(\OCP\IConfig::class);$db=Server::get(\OCP\IDBConnection::class);
$users=Server::get(\OCP\IUserManager::class);$session=Server::get(\OCP\IUserSession::class);$settings=Server::get(Settings::class);$uid='people-synthetic';
if($phase==='baseline'||$phase==='preserved'){
 if($phase==='baseline'){
  $f=Server::get(\OCP\Files\IRootFolder::class)->getUserFolder('merge-synthetic')->get('MergeSynthetic/dog.png');
  Server::get(\OCA\Nextgallery\AI\Service\Embeddings::class)->publish('merge-synthetic',$f->getId(),$f->getEtag(),str_repeat('b',64),[['frame'=>0,'seconds'=>0,'values'=>array_merge([1.0],array_fill(0,127,0.0))]]);
  Server::get(\OCA\Nextgallery\AI\Service\SharedResults::class)->store('merge-synthetic',str_repeat('e',64),'ocr',str_repeat('f',64),['text'=>'SYNTHETIC PRESERVATION OCR']);
 }
 $out=[];
 foreach(['nextgallery_ai_meta','nextgallery_ai_vectors','nextgallery_ai_shared'] as $table){$h=hash_init('sha256');$n=0;$q=$db->getQueryBuilder();$r=$q->select('*')->from($table)->orderBy('id')->executeQuery();try{while($row=$r->fetch()){ksort($row);hash_update($h,json_encode($row,JSON_THROW_ON_ERROR));$n++;}}finally{$r->closeCursor();}$out[$table]=['sha256'=>hash_final($h),'count'=>$n];}
 $out['settings']=$config->getAppValue('nextgallery','ai_settings');
 if($phase==='baseline')file_put_contents('/test-output/people-baseline.json',json_encode($out,JSON_THROW_ON_ERROR));
 else{if($out!==json_decode(file_get_contents('/test-output/people-baseline.json'),true))throw new RuntimeException('Upgrade changed existing data');if($settings->get()['faces']['enabled'])throw new RuntimeException('Upgrade enabled faces');foreach($settings->get()['folder_stages'] as $stages)if(in_array('faces',$stages,true))throw new RuntimeException('Upgrade opted folders into faces');}
 echo json_encode(['preserved'=>$phase==='preserved','tables'=>array_map(fn($x)=>is_array($x)?$x['count']:strlen($x),$out)])."\n";exit;
}
if($phase==='prepare'){
 $user=$users->get($uid)??$users->createUser($uid,'PeopleSyntheticOnly2026!');$session->setUser($user);
 $home=Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($uid);$folder=$home->nodeExists('PeopleQA')?$home->get('PeopleQA'):$home->newFolder('PeopleQA');$other=$home->nodeExists('OtherScope')?$home->get('OtherScope'):$home->newFolder('OtherScope');
 $files=[];foreach(['obama-2012.jpg','obama-2009.jpg','biden-2013.jpg','biden-2021.jpg'] as $name){$f=$folder->nodeExists($name)?$folder->get($name):$folder->newFile($name,file_get_contents('/test-output/public/'.$name));$files[$name]=['id'=>$f->getId(),'etag'=>$f->getEtag()];}
 $blank=$folder->nodeExists('no-person.png')?$folder->get('no-person.png'):$folder->newFile('no-person.png',file_get_contents('/samples/dog.png'));$files['no-person.png']=['id'=>$blank->getId(),'etag'=>$blank->getEtag()];
 $f=$other->nodeExists('out-of-gallery.jpg')?$other->get('out-of-gallery.jpg'):$other->newFile('out-of-gallery.jpg',file_get_contents('/test-output/public/obama-2012.jpg'));$files['out-of-gallery.jpg']=['id'=>$f->getId(),'etag'=>$f->getEtag()];
 $v=$settings->get();$v['faces']['enabled']=true;$settings->save($v);
 $settings->saveAccount($uid,['folders'=>['/PeopleQA','/OtherScope'],'folder_stages'=>['/PeopleQA'=>['faces'],'/OtherScope'=>['faces']],'enabled'=>false]);
 $state=Server::get(State::class)->forUser($uid);$state->registerAccount();$state->initializePipelines();
 $library=Server::get(Library::class);$library->save($uid,['roots'=>['/PeopleQA'],'mode'=>'filename']);$default=$library->settings($uid)['activeGallery'];
 $second=$library->save($uid,['createGallery'=>'Other test gallery']);$otherId=$second['activeGallery']??($second['settings']['activeGallery']??'');if(!$otherId)$otherId=$library->settings($uid)['activeGallery'];$library->save($uid,['roots'=>['/OtherScope']]);$library->save($uid,['activeGallery'=>$default]);
 do{$step=$library->step($uid,false,100,10);}while($step['running']);
 $data=['uid'=>$uid,'files'=>$files,'gallery'=>$default,'other_gallery'=>$otherId];file_put_contents('/test-output/people-fixture.json',json_encode($data,JSON_THROW_ON_ERROR));echo json_encode($data)."\n";exit;
}
if($phase==='change-file'){
 $session->setUser($users->get($uid));$f=Server::get(Library::class)->file($uid,(int)$argv[2]);$f->putContent(file_get_contents('/samples/bike.png'));echo "changed\n";exit;
}
if($phase==='delete-file'){
 $session->setUser($users->get($uid));Server::get(Library::class)->file($uid,(int)$argv[2])->delete();echo "deleted\n";exit;
}
if($phase==='make-cache') {
 $dir=Server::get(State::class)->forUser($uid)->directory();
 foreach(['faces.sqlite3','faces.sqlite3-wal','faces.sqlite3-shm'] as $name)file_put_contents($dir.'/'.$name,'synthetic public fixture cache');
 echo "cache fixtures created\n";exit;
}
if($phase==='cache-cleared') {
 $dir=Server::get(State::class)->forUser($uid)->directory();
 foreach(['faces.sqlite3','faces.sqlite3-wal','faces.sqlite3-shm'] as $name)if(file_exists($dir.'/'.$name))throw new RuntimeException('Face cache remains after deletion');
 echo "cache cleared\n";exit;
}
if($phase==='delete-account'){$user=$users->get($uid);$user?->delete();echo "account deleted\n";exit;}
if($phase==='recreate-account'){$users->createUser($uid,'PeopleSyntheticOnly2026!');echo "account recreated\n";exit;}
throw new RuntimeException('Unknown People fixture phase');
