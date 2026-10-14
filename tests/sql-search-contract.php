<?php
/** Derived-index, stable-page and failure recovery contracts on synthetic isolated data. */
declare(strict_types=1);
require '/var/www/html/lib/base.php';
set_exception_handler(function(Throwable $e):void {fwrite(STDERR,get_class($e).': '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine()."\n");exit(1);});
if(!is_file('/test-output/ISOLATED_SYNTHETIC_TEST'))throw new RuntimeException('Synthetic guard missing');
use OCP\Server;use OCP\{IConfig,IDBConnection,IUserManager,IUserSession};use OCP\Files\IRootFolder;
use OCA\Nextgallery\Service\{NativeSearch,SearchIndex,SearchIndexChanges};use OCA\Nextgallery\AI\Service\{Settings,Metadata,Embeddings,EmbeddingClient};
$uid='sql-contract';$users=Server::get(IUserManager::class);$user=$users->get($uid)??$users->createUser($uid,'SyntheticSqlOnly2026!');Server::get(IUserSession::class)->setUser($user);
$home=Server::get(IRootFolder::class)->getUserFolder($uid);$root=$home->nodeExists('SqlContract')?$home->get('SqlContract'):$home->newFolder('SqlContract');
$files=[];for($i=0;$i<10;$i++)$files[]=$root->nodeExists('sqlmotor-'.$i.'.png')?$root->get('sqlmotor-'.$i.'.png'):$root->newFile('sqlmotor-'.$i.'.png',file_get_contents('/samples/bike.png'));
$meta=Server::get(Metadata::class);$vectors=Server::get(Embeddings::class);$search=Server::get(NativeSearch::class);$index=Server::get(SearchIndex::class);$changes=Server::get(SearchIndexChanges::class);$db=Server::get(IDBConnection::class);$config=Server::get(IConfig::class);
$settings=Server::get(Settings::class);$before=$settings->get();$cfg=$before;$cfg['embedding']['enabled']=false;$settings->save($cfg);
$checks=0;function sqlCheck($ok,$message){global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function protectedSqlState($db,$uid){$parts=[];foreach(['nextgallery_ai_meta','nextgallery_ai_vectors'] as $t){$r=$db->executeQuery('SELECT * FROM *PREFIX*'.$t.' WHERE user_id=? ORDER BY file_id',[$uid]);try{$parts[]=json_encode($r->fetchAll(),JSON_THROW_ON_ERROR);}finally{$r->closeCursor();}}return hash('sha256',implode('|',$parts));}
try{
 $meta->reset($uid);$vectors->reset($uid);
 $meta->upsert($uid,Metadata::validate($files[0]->getId(),$files[0]->getEtag(),str_repeat('a',64),str_repeat('c',64),['vision'=>['type'=>'foto','description'=>'A synthetic motorcycle','tags'=>['BLAU'],'objects'=>['Helm'],'uncertain_details'=>[]],'ocr'=>['text'=>'CAFÉ ZX314']],[]));
 $protected=protectedSqlState($db,$uid);$index->ensure($uid);
 sqlCheck(protectedSqlState($db,$uid)===$protected,'Index creation modified canonical descriptions/OCR/vectors');
 $first=$search->search($uid,'sqlmotor',['/SqlContract'],'',0,3,'text');$token=$first['search_token'];
 sqlCheck($first['search_backend']==='sqlite'&&is_string($token)&&strlen($token)===32,'SQL snapshot token missing');
 $beforeIds=array_column($search->search($uid,'sqlmotor',['/SqlContract'],'',0,100,'text')['items'],'id');
 $extra=$root->nodeExists('sqlmotor-new.png')?$root->get('sqlmotor-new.png'):$root->newFile('sqlmotor-new.png',file_get_contents('/samples/bike.png'));
 $index->ensure($uid);$all=array_column($first['items'],'id');$cursor=$first['next'];
 while($cursor!==null){$page=$search->search($uid,'sqlmotor',['/SqlContract'],'',$cursor,3,'text','','folder','UTC',$token);$all=array_merge($all,array_column($page['items'],'id'));$cursor=$page['next'];}
 sqlCheck($all===$beforeIds,'Full rebuild changed frozen page ordering or added a new result');
 sqlCheck(in_array($extra->getId(),array_column($search->search($uid,'sqlmotor',['/SqlContract'],'',0,100,'text')['items'],'id'),true),'Fresh query missed newly indexed file');
 try{$search->search($uid,'different',['/SqlContract'],'',3,3,'text','','folder','UTC',$token);sqlCheck(false,'Changed query reused snapshot');}catch(InvalidArgumentException){sqlCheck(true,'Changed query rejected');}
 $ocr=$search->search($uid,'ZX314',['/SqlContract'],'',0,5,'text');sqlCheck(count($ocr['items'])===1&&$ocr['items'][0]['search_match']['sources']===['ocr'],'OCR SQL query failed');
 // Time-based catalog sweeps run in cron instead of blocking the first search after five minutes.
 $manifest=$changes->directory($uid).'/manifest.json';$stale=json_decode(file_get_contents($manifest),true);$stale['catalog_at']=0;file_put_contents($manifest,json_encode($stale,JSON_THROW_ON_ERROR));
 $search->search($uid,'ZX314',['/SqlContract'],'',0,5,'text');sqlCheck(json_decode(file_get_contents($manifest),true)['catalog_at']===0,'Normal query triggered a periodic full catalog sweep');
 $index->ensure($uid);sqlCheck(json_decode(file_get_contents($manifest),true)['catalog_at']>0,'Background maintenance did not refresh an expired catalog');
 // A background full rebuild can retain readable published results.
 $build=fopen($changes->directory($uid).'/build.lock','c');flock($build,LOCK_EX);
 try{$start=microtime(true);$during=$search->search($uid,'sqlmotor',['/SqlContract'],'',0,3,'text');sqlCheck($during['index_state']==='updating'&&count($during['items'])===3&&microtime(true)-$start<5,'Search blocked on an unpublished background rebuild');}finally{flock($build,LOCK_UN);fclose($build);}
 $original=$config->getSystemValueString('nextgallery_search_python','');$config->setSystemValue('nextgallery_search_python','/synthetic/missing-python');
 try{
  $fallback=$search->search($uid,'ZX314',['/SqlContract'],'',0,5,'hybrid');sqlCheck($fallback['index_state']==='unavailable'&&count($fallback['items'])===1,'Missing runtime did not retain lexical OCR search');
  sqlCheck($index->status($uid)['state']==='unavailable','Missing runtime status claimed ready');
 }finally{if($original==='')$config->deleteSystemValue('nextgallery_search_python');else $config->setSystemValue('nextgallery_search_python',$original);}
 sqlCheck(protectedSqlState($db,$uid)===$protected,'Queries/fallback changed canonical AI data');
 // A cached lexical hit disappears immediately after its explicit deletion.
 $meta->reset($uid,'ocr');$deleted=$search->search($uid,'ZX314',['/SqlContract'],'',0,5,'text','','folder','UTC',$ocr['search_token']);sqlCheck(!$deleted['items'],'Frozen snapshot leaked deleted OCR');
 sqlCheck($meta->count($uid,'descriptions')===1,'OCR deletion affected descriptions');
 // Counts and partial deletion traverse several bounded batches, retaining independent stages.
 for($i=0;$i<42;$i++){
  $f=$root->newFile('large-ocr-'.$i.'.png',file_get_contents('/samples/bike.png'));
  $meta->upsert($uid,Metadata::validate($f->getId(),$f->getEtag(),str_repeat('a',64),str_repeat('c',64),['vision'=>['type'=>'foto','description'=>'Persistent synthetic description','tags'=>['RETAIN'],'objects'=>[],'uncertain_details'=>[]],'ocr'=>['text'=>str_repeat('SYNTHETIC ',24000)]],[]));
 }
 sqlCheck($meta->counts($uid)===['metadata'=>43,'descriptions'=>43,'ocr'=>42],'Bounded overview counts lost a batch');
 $meta->reset($uid,'ocr');sqlCheck($meta->counts($uid)===['metadata'=>43,'descriptions'=>43,'ocr'=>0],'Paged OCR deletion lost independent descriptions');
 $dir=$changes->directory($uid);sqlCheck(is_file($dir.'/index.sqlite3.sessions'),'Private session database missing');
 $user->delete();sqlCheck(!is_dir($dir),'Account deletion retained its private search index');
 file_put_contents('/test-output/sql-contract-result.json',json_encode(['checks'=>$checks,'peak_php_mib'=>memory_get_peak_usage(true)/1048576,'synthetic'=>true],JSON_PRETTY_PRINT));
 echo "$checks SQL snapshot/canonical preservation/fallback/account cleanup contracts passed; PHP peak ".round(memory_get_peak_usage(true)/1048576,2)." MiB\n";
}finally{$settings->save($before);if($users->get($uid))$users->get($uid)->delete();}
