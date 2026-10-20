<?php
/** Runs only inside the disposable Nextcloud created by scripts/test-integration.py. */
declare(strict_types=1);
require '/var/www/html/lib/base.php';
set_exception_handler(function(\Throwable $e):void {fwrite(STDERR,get_class($e).': '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine()."\n");exit(1);});
use OCP\Server;
use OCP\{IUserManager,IUserSession,IConfig,IDBConnection};
use OCP\Files\IRootFolder;
$phase=$argv[1]??'';$uid='merge-synthetic';$config=Server::get(IConfig::class);$db=Server::get(IDBConnection::class);
if ($config->getSystemValueString('instanceid')==='' || !is_file('/test-output/ISOLATED_SYNTHETIC_TEST')) throw new RuntimeException('Disposable test guard missing');
$users=Server::get(IUserManager::class);$user=$users->get($uid)??$users->createUser($uid,'SyntheticMergeOnly2026!');Server::get(IUserSession::class)->setUser($user);
$home=Server::get(IRootFolder::class)->getUserFolder($uid);
$folder=$home->nodeExists('MergeSynthetic')?$home->get('MergeSynthetic'):$home->newFolder('MergeSynthetic');
$excluded=$home->nodeExists('NotSelected')?$home->get('NotSelected'):$home->newFolder('NotSelected');
$names=['dog.png','bike.png','invoice.png'];$files=[];
foreach ($names as $name) $files[]=$folder->nodeExists($name)?$folder->get($name):$folder->newFile($name,file_get_contents('/samples/'.$name));
$hidden=$excluded->nodeExists('hidden.png')?$excluded->get('hidden.png'):$excluded->newFile('hidden.png',file_get_contents('/samples/dog.png'));
$legacy=$phase==='seed'||$phase==='unified';$galleryApp=$legacy?'chronicle':'nextgallery';
$library=Server::get($legacy?'OCA\\Chronicle\\Service\\Library':'OCA\\Nextgallery\\Service\\Library');
$checks=0;function ok($value,string $message):void {global $checks;$checks++;if(!$value)throw new RuntimeException($message);}
function data($file,string $description,string $ocr):array {return \OCA\Nextgallery\AI\Service\Metadata::validate($file->getId(),$file->getEtag(),hash('sha256',$file->getContent()),str_repeat('b',64),['vision'=>['type'=>'foto','description'=>$description,'tags'=>['synthetisch','Test'],'objects'=>['Hund'],'uncertain_details'=>[]],'ocr'=>['text'=>$ocr]],[]);}
$metaClass=$phase==='seed'?\OCA\MediaAI\Service\Metadata::class:($phase==='unified'?'OCA\\Chronicle\\AI\\Service\\Metadata':\OCA\Nextgallery\AI\Service\Metadata::class);
$metadata=Server::get($metaClass);
if($legacy || $phase==='fresh') {
 $library->save($uid,['roots'=>['/MergeSynthetic'],'mode'=>'filename','grouping'=>'year','order'=>'asc']);
 do {$scan=$library->step($uid,false,100,10);} while($scan['running']);
 ok($scan['errors']===0,'Synthetic gallery indexing failed');
 foreach ($files as $i=>$file) {
  $args=[$file->getId(),$file->getEtag(),hash('sha256',$file->getContent()),str_repeat('b',64),['vision'=>['type'=>'foto','description'=>['Hund am Strand','Fahrrad im Park','Rechnung im Test'][$i],'tags'=>['synthetisch','Test'],'objects'=>['Hund'],'uncertain_details'=>[]],'ocr'=>['text'=>['STALEROCR','ZX987','RECHNUNG 12345'][$i]]],[]];
  $metadata->upsert($uid,$metaClass::validate(...$args));
 }
 $metadata->upsert($uid,$metaClass::validate($hidden->getId(),$hidden->getEtag(),str_repeat('a',64),str_repeat('b',64),['vision'=>['type'=>'foto','description'=>'SECRETNOTSELECTED','tags'=>[],'objects'=>[],'uncertain_details'=>[]],'ocr'=>['text'=>'SECRETNOTSELECTED']],[]));
 $app=$phase==='seed'?'media_ai':$galleryApp;$prefix=$phase==='seed'?'':'ai_';
 $settings=Server::get($phase==='seed'?\OCA\MediaAI\Service\Settings::class:($phase==='unified'?'OCA\\Chronicle\\AI\\Service\\Settings':\OCA\Nextgallery\AI\Service\Settings::class));$value=$settings->defaults();$value['user_id']=$uid;$value['folders']=['/MergeSynthetic'];$value['enabled']=false;
 foreach(['vision','ocr'] as $stage){$value[$stage]['url']='http://127.0.0.1:9/v1';$value[$stage]['api_key']='synthetic-test-key';}
 $settings->save($value);$settings->workerToken();
 $state=Server::get($phase==='seed'?\OCA\MediaAI\Service\State::class:($phase==='unified'?'OCA\\Chronicle\\AI\\Service\\State':\OCA\Nextgallery\AI\Service\State::class));$state->atomic('control.json',['paused'=>true,'requested'=>false,'pause_reason'=>'Synthetic preserved pause']);
 $state->atomic('bootstrap.json',['url'=>'https://synthetic.example.invalid','app_id'=>$app,'token'=>$settings->workerToken()]);
 $queue=new \PDO('sqlite:'.$state->directory().'/native.sqlite3');$queue->exec('CREATE TABLE files(locator,etag,status,error,retry_at,analyzed_at,publish_error,active,scope)');$queue->exec("INSERT INTO files VALUES('synthetic','etag','done','',0,1,'',1,'synthetic-scope')");$queue=null;
 $config->setUserValue($uid,$galleryApp,'synthetic_preference','preserved');$config->setUserValue($uid,$app,$prefix.'ui_language','en');
 $before=['settings'=>$settings->get(),'gallery'=>$library->settings($uid),'epoch'=>$library->cacheKey($uid),'metadata'=>array_map(fn($f)=>$metadata->get($uid,$f->getId()),$files),'token_cipher'=>$config->getAppValue($app,$prefix.'worker_token'),'key_cipher'=>$config->getAppValue($app,$prefix.'vision_api_key'),'queue_hash'=>hash_file('sha256',$state->directory().'/native.sqlite3'),'ids'=>array_map(fn($f)=>$f->getId(),$files)];
 file_put_contents('/test-output/pre.json',json_encode($before,JSON_THROW_ON_ERROR));
 echo json_encode(['seeded'=>true,'ids'=>$before['ids'],'checks'=>$checks])."\n";exit;
}
$before=json_decode(file_get_contents('/test-output/pre.json'),true,64,JSON_THROW_ON_ERROR);
$settings=Server::get(\OCA\Nextgallery\AI\Service\Settings::class);$state=Server::get(\OCA\Nextgallery\AI\Service\State::class);
$current=$settings->get();
$expected=array_replace_recursive($settings->defaults(),$before['settings']);
if(!isset($current['faces_filters'])){
 unset($expected['faces_filters']);
}else{
 if(!isset($before['settings']['faces_filters'])){$expected['faces_filters']=['min_face_area'=>0.0025,'min_sharpness'=>45.0,'min_group_photos'=>(int)($expected['faces']['min_group_size']??3)];}
 if(isset($expected['faces_filters']['min_sharpness']))$expected['faces_filters']['min_sharpness']=(float)$expected['faces_filters']['min_sharpness'];
 if(isset($expected['faces_filters']['min_face_area']))$expected['faces_filters']['min_face_area']=(float)$expected['faces_filters']['min_face_area'];
 if(isset($expected['faces_filters']['min_group_photos']))$expected['faces_filters']['min_group_photos']=(int)$expected['faces_filters']['min_group_photos'];
}
if(!isset($current['faces']['share_inference'])){
 unset($expected['faces']['share_inference']);
}elseif(!isset($before['settings']['faces']['share_inference'])){
 $expected['faces']['share_inference']=$config->getAppValue('nextgallery','ai_share_results','yes')!=='no';
}
if(isset($expected['faces']['cosine_threshold']))$expected['faces']['cosine_threshold']=(float)$expected['faces']['cosine_threshold'];
if(isset($expected['faces']['detection_threshold']))$expected['faces']['detection_threshold']=(float)$expected['faces']['detection_threshold'];
if(!isset($before['settings']['folder_stages'])){$expected['folder_stages']=[];foreach($expected['folders'] as $folder)$expected['folder_stages'][$folder]=['descriptions','ocr','embeddings'];}
ok($current===$expected,'Existing AI settings changed during migration');
ok($settings->get()['analysis_enabled']===($before['settings']['analysis_enabled']??true),'Description default changed');
ok($settings->get()['embedding']['enabled']===($before['settings']['embedding']['enabled']??false),'Embeddings enabled implicitly');
ok(array_intersect_key($library->settings($uid),$before['gallery'])===$before['gallery'],'Gallery settings changed');ok($library->cacheKey($uid)===$before['epoch'],'Offline cache identity changed');
ok($config->getAppValue('nextgallery','ai_worker_token')===$before['token_cipher'],'Worker encrypted token not adopted');ok($config->getAppValue('nextgallery','ai_vision_api_key')===$before['key_cipher'],'Encrypted API key changed');ok($settings->workerConfig()['vision']['api_key']==='synthetic-test-key','Encrypted key cannot be decrypted');
ok(hash_file('sha256',$state->directory().'/native.sqlite3')===$before['queue_hash'],'Queue touched by migration');ok($state->control()['paused'] && $state->control()['pause_reason']==='Synthetic preserved pause','Pause state changed');
foreach($files as $i=>$file)ok($metadata->get($uid,$file->getId())===$before['metadata'][$i],'Existing analysis bytes/timestamp changed');
$search=Server::get(\OCA\Nextgallery\Service\GallerySearch::class);
ok(count($search->search($uid,'dog')['items'])===1,'Existing catalog filename was not backfilled');ok(count($search->search($uid,'ZX987')['items'])===1,'Fresh OCR unavailable in shared gallery search');ok(count($search->search($uid,'Hund irrelevant filler')['items'])===3,'Recall-oriented OR search missing matches');ok(!$search->search($uid,'SECRETNOTSELECTED')['items'],'Search leaks outside gallery roots');
$one=$search->search($uid,'synthetisch','',0,1);$two=$search->search($uid,'synthetisch','',$one['next'],1);ok(count($one['items'])===1&&$one['more']&&$one['items'][0]['id']!==$two['items'][0]['id'],'Stable search pagination failed');
$other=$users->get('merge-other')??$users->createUser('merge-other','SyntheticOtherOnly2026!');ok(!$search->search('merge-other','ZX987')['items'],'Cross-user search leak');
$api=Server::get(\OCA\Nextgallery\Controller\ApiController::class);ok($api->analysis($files[0]->getId())->getData()['available'],'Analysis details unavailable');ok($api->analysis($hidden->getId())->getStatus()===404,'Analysis details leak outside roots');
// An old worker bootstrap's native URL changes no queue scope or credentials.
$files[0]->putContent(file_get_contents('/samples/bike.png'));
ok(!$search->search($uid,'STALEROCR')['items'],'Changed file still matches old OCR');ok(($api->analysis($files[0]->getId())->getData()['stale']??false),'Changed file has no stale-analysis warning');
$files[1]->delete();ok(!$search->search($uid,'ZX987')['items'],'Deleted file still returned');
$query=$db->getQueryBuilder();$query->select('file_id')->from('nextgallery_ai_meta')->where($query->expr()->eq('user_id',$query->createNamedParameter('merge-other')));
$metadata->upsert('merge-other',data($files[2],'Only synthetic other user','OTHERUSEROCR'));$other->delete();ok(!$query->executeQuery()->fetchOne(),'Deleted account retained AI records');
ok($config->getUserValue($uid,'nextgallery','synthetic_preference')==='preserved','Gallery preference lost');ok($config->getUserValue($uid,'nextgallery','ai_ui_language')==='en','AI language lost');
ok(!$config->getAppKeys('chronicle') && !$config->getAppKeys('media_ai'),'Retired apps still registered');
$schema=$db->createSchema();ok(!$schema->hasTable('chronicle_media')&&!$schema->hasTable('chronicle_users')&&!$schema->hasTable('media_ai_meta'),'Retired tables remain');
$ids=$before['ids'];file_put_contents('/test-output/verified.json',json_encode(['checks'=>$checks,'ids'=>$ids,'uid'=>$uid,'version'=>$config->getAppValue('nextgallery','installed_version'),'database'=>$db->getDatabaseProvider()],JSON_THROW_ON_ERROR));
echo "$checks real Nextcloud migration/scope/freshness assertions passed\n";
