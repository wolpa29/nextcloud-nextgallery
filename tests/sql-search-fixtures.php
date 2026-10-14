<?php
/** Real HTTP UI fixtures. Refuses every environment except our disposable integration container. */
declare(strict_types=1);
require '/var/www/html/lib/base.php';
if(!is_file('/test-output/ISOLATED_SYNTHETIC_TEST'))throw new RuntimeException('Synthetic guard missing');
use OCP\Server;use OCP\{IUserManager,IUserSession,IConfig};use OCP\Files\IRootFolder;
use OCA\Nextgallery\AI\Service\{Metadata,Embeddings,Settings,EmbeddingClient};use OCA\Nextgallery\Service\Library;
$users=Server::get(IUserManager::class);$uid='sql-user';$user=$users->get($uid)??$users->createUser($uid,'SqlSearchOnly2026!');Server::get(IUserSession::class)->setUser($user);
$home=Server::get(IRootFolder::class)->getUserFolder($uid);$dir=$home->nodeExists('SqlSearchFixtures')?$home->get('SqlSearchFixtures'):$home->newFolder('SqlSearchFixtures');
$files=[];foreach(['motorrad-demo.png'=>'bike.png','boot-demo.png'=>'dog.png','dokument-demo.png'=>'invoice.png'] as $name=>$sample)$files[$name]=$dir->nodeExists($name)?$dir->get($name):$dir->newFile($name,file_get_contents('/samples/'.$sample));
$files['video-demo.mp4']=$dir->nodeExists('video-demo.mp4')?$dir->get('video-demo.mp4'):$dir->newFile('video-demo.mp4',file_get_contents('/tmp/video-demo.mp4'));
$settings=Server::get(Settings::class);$cfg=$settings->get();$cfg['embedding']=array_replace($cfg['embedding'],['enabled'=>true,'location'=>'external','transport'=>'multimodal','family'=>'custom','model'=>'synthetic-768','url'=>'http://127.0.0.1:19379/v1','local_auto_setup'=>false]);$settings->save($cfg);
$client=Server::get(EmbeddingClient::class);$info=$client->info($settings->workerConfig()['embedding']);$space=EmbeddingClient::space($info,$cfg['embedding']);
$meta=Server::get(Metadata::class);$vectors=Server::get(Embeddings::class);$meta->reset($uid);$vectors->reset($uid);
$ids=[];$positive=array_merge([1.],array_fill(0,767,0.));$sail=array_merge([0.,1.],array_fill(0,766,0.));$negative=array_merge([-1.],array_fill(0,767,0.));
foreach($files as $name=>$file){$bike=str_contains($name,'motorrad');$video=str_contains($name,'video');$boat=str_contains($name,'boot');
 $vision=['type'=>$video?'video':'foto','description'=>$bike?'Ein synthetisches Motorrad am See':($boat?'Ein synthetisches Segelboot':'Ein synthetisches Testdokument'),'tags'=>[$bike?'Motorrad':($boat?'Segelboot':'Dokument'),'SYNTHETISCH'],'objects'=>[$bike?'Helm':'Testobjekt'],'uncertain_details'=>[]];
 $ocr=['text'=>$bike?'MOTORRAD TESTCODE ZX314':($boat?'SEGELBOOT TESTCODE BOOT42':'BELEG 4711 TESTTEXT')];
 $meta->upsert($uid,Metadata::validate($file->getId(),$file->getEtag(),str_repeat('c',64),str_repeat('d',64),['vision'=>$vision,'ocr'=>$ocr],[]));
 $values=$boat?$sail:($bike?$positive:$negative);$frames=[['frame'=>0,'seconds'=>0,'values'=>$values]];if($video)$frames[]=['frame'=>1,'seconds'=>.5,'values'=>$positive];
 $vectors->publish($uid,$file->getId(),$file->getEtag(),$space,$frames);$ids[$name]=$file->getId();
}
$library=Server::get(Library::class);$library->save($uid,['roots'=>['/SqlSearchFixtures'],'language'=>'de']);$state=$library->step($uid,true,150,20);while($state['running'])$state=$library->step($uid,false,150,20);
$config=Server::get(IConfig::class);$config->setUserValue($uid,'core','lang','de');$config->setUserValue('synthetic-admin','core','lang','de');
Server::get(\OCA\Nextgallery\Service\SearchIndex::class)->ensure($uid);
file_put_contents('/test-output/sql-fixtures.json',json_encode(['uid'=>$uid,'ids'=>$ids,'dimensions'=>768,'space'=>$space],JSON_PRETTY_PRINT));echo json_encode(['synthetic'=>true,'ids'=>$ids,'dimensions'=>768]),PHP_EOL;
