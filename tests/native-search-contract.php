<?php
declare(strict_types=1);
require '/var/www/html/lib/base.php';
set_exception_handler(function(\Throwable $e):void {fwrite(STDERR,get_class($e).': '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine()."\n");exit(1);});
use OCP\Server;use OCP\{IDBConnection,IUserSession,IUserManager};use OCP\Files\IRootFolder;use OCP\FilesMetadata\IFilesMetadataManager;
use OCA\Nextgallery\AI\Service\{Settings,EmbeddingClient,Embeddings,Metadata};use OCA\Nextgallery\Service\{Library,NativeSearch};
if(!is_file('/test-output/ISOLATED_SYNTHETIC_TEST'))throw new RuntimeException('Synthetic guard missing');
$uid='merge-synthetic';Server::get(IUserSession::class)->setUser(Server::get(IUserManager::class)->get($uid));
$db=Server::get(IDBConnection::class);$root=Server::get(IRootFolder::class);$home=$root->getUserFolder($uid);$settings=Server::get(Settings::class);$before=$settings->get();$web=Server::get(Library::class)->settings($uid);
class NativeSyntheticClient extends EmbeddingClient {
 public int $calls=0;public bool $fail=false;
 protected function request(array $config,string $endpoint,?array $body=null,int $timeout=10):array {
  $this->calls++;if($this->fail)throw new RuntimeException('Synthetic model unavailable');
  if($endpoint==='info')return ['space'=>str_repeat('a',64),'model'=>$config['model'],'family'=>$config['family'],'dimensions'=>128,'modalities'=>['text','image']];
  return ['space'=>str_repeat('a',64),'embedding'=>array_merge([1.],array_fill(0,127,0.))];
 }
}
$config=$before;$config['embedding']['enabled']=true;$settings->save($config);$client=new NativeSyntheticClient();$info=$client->info($config['embedding']);$space=EmbeddingClient::space($info,$config['embedding']);
$service=new NativeSearch($db,$root,Server::get(IFilesMetadataManager::class),$settings,$client);$vectors=Server::get(Embeddings::class);$metadata=Server::get(Metadata::class);
$folder=$home->nodeExists('NativeOnly')?$home->get('NativeOnly'):$home->newFolder('NativeOnly');$files=[];
for($i=0;$i<65;$i++)$files[]=$folder->newFile('native-'.str_pad((string)$i,3,'0',STR_PAD_LEFT).'.png',file_get_contents('/samples/dog.png'));
$checks=0;function nativeCheck($value,$message){global $checks;$checks++;if(!$value)throw new RuntimeException($message);}
try{
 $cachedManager=Server::get(IFilesMetadataManager::class);$cached=$cachedManager->getMetadata($files[0]->getId(),true);$cached->setArray('photos-ifd0',['Artist'=>'Müller']);$cached->setArray('photos-exif',['DateTimeOriginal'=>'2023:06:30 23:30:00+00:00']);$cachedManager->saveMetadata($cached);
 $file=$files[0];$metadata->upsert($uid,Metadata::validate($file->getId(),$file->getEtag(),str_repeat('a',64),str_repeat('b',64),['vision'=>['type'=>'foto','description'=>'Segelboot am See','tags'=>['Urlaub'],'objects'=>['Boot'],'uncertain_details'=>[]],'ocr'=>['text'=>'CAFÉ ZX314']],[]));
 $positive=array_merge([1.],array_fill(0,127,0.));$negative=array_merge([-1.],array_fill(0,127,0.));
 $vectors->publish($uid,$files[0]->getId(),$files[0]->getEtag(),$space,[['frame'=>0,'seconds'=>0,'values'=>$negative],['frame'=>1,'seconds'=>5,'values'=>$positive]]);
 $vectors->publish($uid,$files[1]->getId(),'old-etag',$space,[['frame'=>0,'seconds'=>0,'values'=>$positive]]);
 Server::get(\OCA\Nextgallery\Service\SearchIndex::class)->ensure($uid);
 $calls=$client->calls;
 foreach(['Segelboot'=>['description'],'Urlaub'=>['tags'],'Boot'=>['description','objects'],'ZX314'=>['ocr'],'native-000'=>['name','path'],'NativeOnly'=>['path']] as $query=>$expectedSources){
  $r=$service->search($uid,$query,['/NativeOnly'],'',0,60,'text');
  nativeCheck(count($r['items'])>0&&$r['items'][0]['search_match']===['query'=>$query,'sources'=>$expectedSources],'Single/combined source mismatch for '.$query);
 }
 nativeCheck($client->calls===$calls,'Text phase invoked embedding service');
 $rM=$service->search($uid,'Müller',['/NativeOnly'],'',0,60,'text');
 nativeCheck(count($rM['items'])===1&&$rM['items'][0]['search_match']===['query'=>'Müller','sources'=>['metadata']],'Unicode cached metadata match source missing');
 $rTrim=$service->search($uid,'  Urlaub  ',['/NativeOnly'],'',0,60,'text');
 nativeCheck(count($rTrim['items'])===1&&$rTrim['items'][0]['search_match']===['query'=>'Urlaub','sources'=>['tags']],'Query trimming in search_match failed');
 $rComb=$service->search($uid,'native Urlaub',['/NativeOnly'],'',0,60,'text');
 $f0=array_values(array_filter($rComb['items'],fn($it)=>$it['id']===$files[0]->getId()))[0]??null;
 nativeCheck($f0&&$f0['search_match']===['query'=>'native Urlaub','sources'=>['name','path','tags']],'Multi-word combination sources mismatch');
 nativeCheck(count($service->search($uid,'native',['/NativeOnly'],'',0,60,'text','2023-07','metadata','Europe/Vienna')['items'])===1,'Metadata date/timezone filter missing');
 nativeCheck(!$service->search($uid,'native',['/NativeOnly'],'',0,60,'text','2023-07','metadata','UTC')['items'],'UTC filter ignored capture timestamp offset');
 $r=$service->search($uid,'ZX314',['/NativeOnly'],'',0,60,'text');nativeCheck(count($r['items'])===1&&$r['items'][0]['metadata']['mediaAi']['analysis']['vision']['description']==='Segelboot am See'&&$r['items'][0]['search_match']===['query'=>'ZX314','sources'=>['ocr']],'Current AI metadata missing in response');
 $rSem=$service->search($uid,'unrelated semantic query',['/NativeOnly']);
 nativeCheck($rSem['mode']==='hybrid'&&array_column($rSem['items'],'id')===[$files[0]->getId()]&&$rSem['items'][0]['search_match']===['query'=>'unrelated semantic query','sources'=>['embedding']],'Vector-only/current etag/frame maximum failed');
 $rHybText=$service->search($uid,'ZX314',['/NativeOnly'],'',0,60,'hybrid');
 nativeCheck(count($rHybText['items'])===1&&$rHybText['items'][0]['search_match']===['query'=>'ZX314','sources'=>['ocr','embedding']],'Hybrid ocr+embedding combination mismatch');
 $client->fail=true;$r=$service->search($uid,'ZX314',['/NativeOnly']);nativeCheck($r['embedding_state']==='unavailable'&&count($r['items'])===1&&$r['items'][0]['search_match']===['query'=>'ZX314','sources'=>['ocr']],'Model outage lost text results');$client->fail=false;
 $changed=$config;$changed['embedding']['max_edge']=1024;$settings->save($changed);$r=$service->search($uid,'ZX314',['/NativeOnly']);nativeCheck($r['embedding_state']==='model_changed'&&count($r['items'])===1&&$r['items'][0]['search_match']===['query'=>'ZX314','sources'=>['ocr']],'Incompatible vector space was used');$settings->save($config);
 $rOldEtag=$service->search($uid,'native-001',['/NativeOnly']);
 $oldEtagItem=array_values(array_filter($rOldEtag['items'],fn($it)=>$it['id']===$files[1]->getId()))[0]??null;
 nativeCheck($oldEtagItem&&$oldEtagItem['search_match']===['query'=>'native-001','sources'=>['name','path']],'Old etag leaked embedding source');
 foreach([$rHybText,$rComb,$rM,$rSem] as $sample){foreach($sample['items'] as $it){$s=$it['search_match']['sources'];nativeCheck($s===array_values(array_unique($s)),'search_match sources contained duplicates');}}
 $r=$service->search($uid,'native',['/NativeOnly'],'',0,60,'text');$second=$service->search($uid,'native',['/NativeOnly'],'',$r['next'],60,'text');
 nativeCheck(count($r['items'])===60&&$r['more']&&count($second['items'])===5&&!$second['more'],'Mobile pagination incomplete');
 nativeCheck(count(array_unique(array_merge(array_column($r['items'],'id'),array_column($second['items'],'id'))))===65,'Mobile pagination duplicated a result');
 foreach(array_slice($files,5,5) as $large)$metadata->upsert($uid,Metadata::validate($large->getId(),$large->getEtag(),str_repeat('c',64),str_repeat('d',64),['vision'=>['type'=>'foto','description'=>'longpayload','tags'=>[],'objects'=>[],'uncertain_details'=>[]],'ocr'=>['text'=>str_repeat('x',480000)]],[]));
 Server::get(\OCA\Nextgallery\Service\SearchIndex::class)->ensure($uid);
 $r=$service->search($uid,'longpayload',['/NativeOnly'],'',0,60,'text');$second=$service->search($uid,'longpayload',['/NativeOnly'],'',$r['next'],60,'text');
 nativeCheck(count($r['items'])>0&&count($r['items'])<5&&$r['more'],'Large OCR records were not split into bounded pages');
 nativeCheck(count(array_unique(array_merge(array_column($r['items'],'id'),array_column($second['items'],'id'))))===5&&!$second['more'],'Payload pagination lost or duplicated OCR records');
 $q=$db->getQueryBuilder();$q->delete('nextgallery_ai_meta')->where($q->expr()->eq('user_id',$q->createNamedParameter($uid)))->andWhere($q->expr()->in('file_id',$q->createNamedParameter(array_map(fn($file)=>$file->getId(),array_slice($files,5,5)),\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));$q->executeStatement();
 nativeCheck(!$service->search($uid,'ZX314',['/TripsSynthetic'],'',0,60,'text')['items'],'Android folder scope leak');
 nativeCheck(!$service->search($uid,'native',['/NativeOnly'],'video',0,60,'text')['items'],'Media kind filter leak');
 nativeCheck(!$service->search($uid,'native',[],'',0,60,'text')['items'],'Empty roots exposed files');
 $overlap=$service->search($uid,'native',['/NativeOnly','/NativeOnly'],'',0,100,'text');nativeCheck(count($overlap['items'])===65,'Overlapping roots count='.count($overlap['items']).', next='.$overlap['next'].', more='.(int)$overlap['more']);
 foreach([['/../NativeOnly'],['/NativeOnly/../MergeSynthetic'],['/absent'],['/NativeOnly/native-000.png'],['/NativeOnly\\escape']] as $roots){try{$service->search($uid,'native',$roots);throw new RuntimeException('Invalid roots accepted');}catch(\InvalidArgumentException){nativeCheck(true,'Invalid scope rejected');}}
 $users=Server::get(IUserManager::class);$other=$users->get('native-other')??$users->createUser('native-other','SyntheticNativeOnly2026!');
 try{try{$service->search('native-other','ZX314',['/NativeOnly']);throw new RuntimeException('Other account accepted scope');}catch(\InvalidArgumentException){nativeCheck(true,'Other account denied');}}finally{$other->delete();}
 $files[0]->putContent(file_get_contents('/samples/invoice.png'));$r=$service->search($uid,'ZX314',['/NativeOnly'],'',0,60,'text');nativeCheck(!$r['items'],'Changed file retained stale OCR');
 nativeCheck(Server::get(Library::class)->settings($uid)===$web,'Mobile search changed web gallery settings');
 echo "$checks real Nextcloud native search/permissions/pagination/embedding contracts passed\n";
}finally{$settings->save($before);$vectors->reset($uid);}
