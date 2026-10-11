<?php
/** Real Nextcloud DB/query builder, only the isolated synthetic test instance. */
declare(strict_types=1);
require '/var/www/html/lib/base.php';
set_exception_handler(function(\Throwable $e):void {fwrite(STDERR,get_class($e).': '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine()."\n");exit(1);});
use OCP\Server;use OCP\{IDBConnection,IUserSession,IUserManager};use OCP\Files\IRootFolder;
use OCA\Nextgallery\AI\Service\{Settings,EmbeddingClient,Embeddings};use OCA\Nextgallery\Service\{Library,EmbeddingSearch,GallerySearch};
if(!is_file('/test-output/ISOLATED_SYNTHETIC_TEST'))throw new RuntimeException('Synthetic guard missing');
$uid='merge-synthetic';Server::get(IUserSession::class)->setUser(Server::get(IUserManager::class)->get($uid));
$db=Server::get(IDBConnection::class);$settings=Server::get(Settings::class);$before=$settings->get();$c=$before;$c['analysis_enabled']=false;$c['embedding']['enabled']=true;$settings->save($c);
class SyntheticClient extends EmbeddingClient {
 public bool $fail=false;public int $calls=0;
 protected function request(array $config,string $endpoint,?array $body=null,int $timeout=10):array {
  $this->calls++;if($this->fail)throw new RuntimeException('Synthetic outage');
  if($endpoint==='info')return ['space'=>str_repeat('a',64),'model'=>$config['model'],'family'=>$config['family'],'dimensions'=>128,'modalities'=>['text','image']];
  return ['space'=>str_repeat('a',64),'embedding'=>array_merge([1.],array_fill(0,127,0.))];
 }
}
$client=new SyntheticClient();$info=$client->info($c['embedding']);$space=EmbeddingClient::space($info,$c['embedding']);$vectors=Server::get(Embeddings::class);$library=Server::get(Library::class);$home=Server::get(IRootFolder::class)->getUserFolder($uid);
$dog=$home->get('MergeSynthetic/dog.png');$invoice=$home->get('MergeSynthetic/invoice.png');
$active=$library->settings($uid)['activeGallery'];$library->save($uid,['activeGallery'=>'default']);
$state=$library->step($uid,true,150,20);while($state['running'])$state=$library->step($uid,false,150,20);
$library->save($uid,['activeGallery'=>$active]);
$positive=array_merge([1.],array_fill(0,127,0.));$negative=array_merge([-1.],array_fill(0,127,0.));
$checks=0;function verifyEmbedding($v,$message){global $checks;$checks++;if(!$v)throw new RuntimeException($message);}
$embedding=new EmbeddingSearch($db,$library,$settings,$client);$search=new GallerySearch($db,$library,$embedding);
try {
 $vectors->publish($uid,$invoice->getId(),$invoice->getEtag(),$space,[['frame'=>0,'seconds'=>0,'values'=>$negative],['frame'=>1,'seconds'=>5,'values'=>$positive]]);
 // dog was changed by the base fixture: stale vectors must not enter the result set.
 $vectors->publish($uid,$dog->getId(),'stale-synthetic-etag',$space,[['frame'=>0,'seconds'=>0,'values'=>$positive]]);
 $client->fail=true;$calls=$client->calls;
 $r=$search->search($uid,'invoice','',0,60,'default','text');
 verifyEmbedding($r['mode']==='lexical'&&$r['embedding_state']==='pending'&&count($r['items'])===1,'Immediate lexical phase failed');
 verifyEmbedding($client->calls===$calls,'Immediate text search contacted an offline model');
 $r=$search->search($uid,'a semantic paraphrase','',0,60,'default','text');
 verifyEmbedding(!$r['items']&&$r['embedding_state']==='pending','No-text query cannot continue with semantic enrichment');
 try{$search->search($uid,'invoice','',0,60,'default','invalid');throw new RuntimeException('Invalid search phase accepted');}catch(\InvalidArgumentException){verifyEmbedding(true,'Invalid phase rejected');}
 $client->fail=false;
 $r=$embedding->ranks($uid,'a semantic paraphrase','default','');verifyEmbedding($r['ids']===[$invoice->getId()],'Current ETag/frame max/scope failed');
 $r=$search->search($uid,'a semantic paraphrase','',0,1,'default');verifyEmbedding($r['mode']==='hybrid'&&count($r['items'])===1&&$r['items'][0]['id']===$invoice->getId(),'Vector-only candidate excluded');
 $r=$embedding->ranks($uid,'semantic','default','video');verifyEmbedding(!$r['ids'],'Image/video filter leak');
 $c['embedding']['max_edge']=1024;$settings->save($c);verifyEmbedding($embedding->ranks($uid,'semantic','default','')['state']==='model_changed','Model-space mismatch used');
 $settings->save($before+[]);$c=$before;$c['embedding']['enabled']=true;$settings->save($c);
 $client->fail=true;$r=$search->search($uid,'invoice','',0,60,'default');verifyEmbedding($r['mode']==='lexical'&&$r['embedding_state']==='unavailable'&&count($r['items'])===1,'Text fallback failed');
 $client->fail=false;$r=$search->search($uid,'dog','',0,60,'default');$ids=array_column($r['items'],'id');verifyEmbedding(in_array($dog->getId(),$ids,true)&&in_array($invoice->getId(),$ids,true),'Text/image union lost lexical hits');
 $other=$library->settings($uid)['activeGallery'];if($other!=='default'){verifyEmbedding(!$embedding->ranks($uid,'semantic',$other,'')['ids'],'Cross-gallery vector leak');}
 verifyEmbedding($vectors->count($uid)===2,'Distinct media count wrong');
 // Transaction rolls back after invalid input: existing vector rows survive.
 try{$vectors->publish($uid,$invoice->getId(),$invoice->getEtag(),$space,[['frame'=>0,'seconds'=>0,'values'=>array_fill(0,128,NAN)]]);throw new RuntimeException('Invalid vector accepted');}catch(\OCP\AppFramework\OCS\OCSBadRequestException){verifyEmbedding($vectors->count($uid)===2,'Invalid publish erased old vectors');}
 // A successful replacement discards every old frame/space for this file only.
 $replacement=str_repeat('c',64);$vectors->publish($uid,$invoice->getId(),$invoice->getEtag(),$replacement,[['frame'=>0,'seconds'=>0,'values'=>array_merge([1.],array_fill(0,255,0.))]]);
 $rows=$db->executeQuery('SELECT file_id,space,dimensions FROM *PREFIX*nextgallery_ai_vectors WHERE user_id=?',[$uid])->fetchAll();
 $own=array_values(array_filter($rows,fn($row)=>(int)$row['file_id']===$invoice->getId()));
 verifyEmbedding(count($own)===1&&$own[0]['space']===$replacement&&(int)$own[0]['dimensions']===256,'Old model/frames retained after replacement');
 verifyEmbedding(count($rows)===2,'Replacement touched another file');
 $vectors->reset($uid);verifyEmbedding($vectors->count($uid)===0,'Embedding reset failed');
 echo "$checks real Nextcloud embedding scope/freshness/fallback contracts passed\n";
}finally{$vectors->reset($uid);$settings->save($before);}
