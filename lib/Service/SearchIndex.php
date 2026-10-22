<?php
declare(strict_types=1);
namespace OCA\Nextgallery\Service;
use OCP\{IConfig,IDBConnection};
use OCP\Files\{IRootFolder,File};
use OCP\FilesMetadata\IFilesMetadataManager;
use Symfony\Component\Process\Process;

/** Per-account SQLite index. Original metadata/vectors remain in the canonical database. */
class SearchIndex {
 public function __construct(private IConfig $config,private IDBConnection $db,private IRootFolder $root,private IFilesMetadataManager $metadata,private SearchIndexChanges $changes) {}
 public function changes(): SearchIndexChanges { return $this->changes; }
 private function python():string {
  $configured=$this->config->getSystemValueString('nextgallery_search_python','');
  $candidates=$configured!==''?[$configured]:['/opt/nextgallery-ai-venv/bin/python','/usr/bin/python3'];
  foreach($candidates as $p)if(str_starts_with($p,'/')&&is_executable($p))return $p;
  throw new \RuntimeException('Search runtime is unavailable');
 }
 private function process(string $database,string $operation):Process {
  $worker=dirname(__DIR__,2).'/worker';$arch=php_uname('m');$arch=match($arch){'amd64'=>'x86_64','arm64'=>'aarch64',default=>$arch};
  $env=['PYTHONPATH'=>$worker.'/vendor/'.$arch,'PYTHONDONTWRITEBYTECODE'=>'1'];
  $p=new Process([$this->python(),$worker.'/search_index.py','--database',$database,'--'.$operation],null,$env);$p->setTimeout(180);return $p;
 }
 public function metadata(File $file):array {
  $out=[];try{$meta=$this->metadata->getMetadata($file->getId());
   foreach(['photos-exif'=>'exif','photos-ifd0'=>'ifd0','photos-gps'=>'gps'] as $key=>$name)if($meta->hasKey($key))$out[$name]=$meta->getArray($key);
   if($meta->hasKey('photos-original_datetime'))$out['capture']=$meta->getString('photos-original_datetime');
   $exif=$out['exif']??[];$out['capture']??=$exif['DateTimeOriginal']??$exif['EXIF']['DateTimeOriginal']??null;
   if(isset($out['gps']['latitude'],$out['gps']['longitude']))$out['gps']=[(float)$out['gps']['latitude'],(float)$out['gps']['longitude']];
  }catch(\Throwable){}return $out;
 }
 private function nodes(string $uid,?array $ids):\Generator {
  $home=$this->root->getUserFolder($uid);
  if($ids!==null){foreach($ids as $id){$found=false;foreach($home->getById($id) as $node){if($node instanceof File&&$node->isReadable()&&preg_match('~^(image|video)/~',$node->getMimeType())){$found=true;yield $node;break;}}if(!$found)yield (int)$id;}return;}
  for($offset=0;;){$rows=$home->search(Search::query($offset,2000));if(!$rows)break;$offset+=count($rows);foreach($rows as $node)if($node instanceof File&&$node->isReadable()&&preg_match('~^(image|video)/~',$node->getMimeType()))yield $node;}
 }
 private function records(string $uid,?array $ids=null,bool $vectors=true):\Generator {
  $home=$this->root->getUserFolder($uid);$batch=[];
  foreach($this->nodes($uid,$ids) as $node){if(is_int($node)){yield json_encode(['type'=>'delete','file_id'=>$node],JSON_THROW_ON_ERROR)."\n";continue;}$batch[]=$node;if(count($batch)>=20){yield from $this->batch($uid,$home,$batch,$vectors);$batch=[];}}
  if($batch)yield from $this->batch($uid,$home,$batch,$vectors);
 }
 private function batch(string $uid,$home,array $files,bool $vectors=true):\Generator {
  $docs=[];$ids=[];
  foreach($files as $file){$id=$file->getId();$ids[]=$id;$docs[$id]=['type'=>'document','file_id'=>$id,'path'=>'/'.ltrim($home->getRelativePath($file->getPath()),'/'),'name'=>$file->getName(),'kind'=>str_starts_with($file->getMimeType(),'image/')?'image':'video','etag'=>$file->getEtag(),'mtime'=>$file->getMTime(),'size'=>$file->getSize(),'metadata'=>($this->metadata($file)?:new \stdClass()),'description'=>'','tags'=>'','objects'=>'','ocr'=>''];}
  $q=$this->db->getQueryBuilder();$q->select('file_id','etag','description','tags_text','objects_text','ocr_text','metadata_json','updated_at')->from('nextgallery_ai_meta')->where($q->expr()->eq('user_id',$q->createNamedParameter($uid)))->andWhere($q->expr()->in('file_id',$q->createNamedParameter($ids,\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
  $r=$q->executeQuery();$etags=[];
  try{while($row=$r->fetch()){$id=(int)$row['file_id'];$doc=$docs[$id]??null;if(!$doc)continue;
   $analysis=json_decode($row['metadata_json'],true,64,JSON_THROW_ON_ERROR)['analysis']??[];$stages=$analysis['stage_etags']??[];
   if(hash_equals($doc['etag'],$stages['descriptions']??$row['etag']))foreach(['description'=>'description','tags_text'=>'tags','objects_text'=>'objects'] as $field=>$target)$doc[$target]=$row[$field];
   if(hash_equals($doc['etag'],$stages['ocr']??$row['etag']))$doc['ocr']=$row['ocr_text'];
   $doc['ai_etag']=$row['etag'];$doc['ai_updated_at']=(int)$row['updated_at'];$etags[$id]=$doc['etag'];
   yield json_encode($doc,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE)."\n";unset($docs[$id]);
  }}finally{$r->closeCursor();}
  foreach($docs as $id=>$doc){$etags[$id]=$doc['etag'];yield json_encode($doc,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE)."\n";}unset($docs);
  if(!$vectors)return;
  $q=$this->db->getQueryBuilder();$q->select('file_id','etag','frame','space','dimensions','vector_data')->from('nextgallery_ai_vectors')->where($q->expr()->eq('user_id',$q->createNamedParameter($uid)))->andWhere($q->expr()->in('file_id',$q->createNamedParameter($ids,\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
  $r=$q->executeQuery();try{while($row=$r->fetch()){if(!hash_equals($etags[(int)$row['file_id']],$row['etag']))continue;foreach(['file_id','frame','dimensions'] as $key)$row[$key]=(int)$row[$key];yield json_encode(['type'=>'vector']+$row,JSON_THROW_ON_ERROR)."\n";}}finally{$r->closeCursor();}
 }
 private function fingerprint(string $uid):string {
  $parts=[$this->changes->revision($uid)];
  foreach(['nextgallery_ai_meta','nextgallery_ai_vectors'] as $table){$r=$this->db->executeQuery('SELECT COUNT(*) AS n,COALESCE(MAX(updated_at),0) AS t FROM *PREFIX*'.$table.' WHERE user_id=?',[$uid]);try{$parts[]=json_encode($r->fetch(),JSON_THROW_ON_ERROR);}finally{$r->closeCursor();}}
  return hash('sha256',implode('|',$parts));
 }
 /** A resource lets Symfony drain stdin without waiting 200 ms for every yielded record. */
 private function input(\Generator $records,string $dir):array {
  $path=$dir.'/input-'.bin2hex(random_bytes(8)).'.ndjson';$stream=fopen($path,'x+b');
  if(!$stream)throw new \RuntimeException('Search export unavailable');chmod($path,0600);
  try{
   foreach($records as $record)if(fwrite($stream,$record)!==strlen($record))throw new \RuntimeException('Search export unavailable');
   if(!rewind($stream))throw new \RuntimeException('Search export unavailable');
   return [$stream,$path];
  }catch(\Throwable $error){fclose($stream);@unlink($path);throw $error;}
 }
 public function ensure(string $uid,bool $refreshCatalog=true):string {
  $dir=$this->changes->directory($uid);$database=$dir.'/index.sqlite3';$manifest=$dir.'/manifest.json';
  foreach([$database,$database.'.sessions',$manifest,$dir.'/index.lock',$dir.'/build.lock'] as $p)if(is_link($p))throw new \RuntimeException('Unsafe search index');
  $lock=fopen($dir.'/build.lock','c');if(!$lock)throw new \RuntimeException('Search index unavailable');chmod($dir.'/build.lock',0600);
  $until=microtime(true)+25;while(!flock($lock,LOCK_EX|LOCK_NB)){if(microtime(true)>$until){fclose($lock);throw new \RuntimeException('Search index is being updated');}usleep(100000);}
  $publication=null;
  try{
   $state=is_file($manifest)?json_decode((string)file_get_contents($manifest),true):[];$revision=$this->changes->revision($uid);$pending=$this->changes->pending($uid);$fingerprint=$this->fingerprint($uid);
   $full=!is_file($database)||($state['schema']??0)!==1||($state['catalog_revision']??'')!==$revision||($refreshCatalog&&time()-($state['catalog_at']??0)>300);
   // A changed aggregate without recorded IDs means an out-of-band write or restore.
   if(!$full&&($state['fingerprint']??'')!==$fingerprint&&!$pending)$full=true;
   if(!$full&&!$pending){$this->changes->clearBuildRequest($uid);return $database;}
   $tmp=$full?$dir.'/build-'.bin2hex(random_bytes(8)).'.sqlite3':$database;
   if(!$full){$publication=fopen($dir.'/index.lock','c');if(!$publication||!flock($publication,LOCK_EX))throw new \RuntimeException('Search index unavailable');chmod($dir.'/index.lock',0600);}
   [$input,$inputPath]=$this->input($this->records($uid,$full?null:array_keys($pending)),$dir);
   $p=$this->process($tmp,$full?'import':'update');$p->setInput($input);$p->run();
   if(!$p->isSuccessful()){if($full)@unlink($tmp);throw new \RuntimeException('Search index update failed');}
   if($full){$publication=fopen($dir.'/index.lock','c');if(!$publication||!flock($publication,LOCK_EX))throw new \RuntimeException('Search index unavailable');chmod($dir.'/index.lock',0600);chmod($tmp,0600);if(!rename($tmp,$database))throw new \RuntimeException('Search index publication failed');}
   $this->changes->acknowledge($uid,$pending);
   $next=['uid'=>$uid,'schema'=>1,'catalog_revision'=>$revision,'catalog_at'=>$full?time():($state['catalog_at']??time()),'updated_at'=>time(),'fingerprint'=>$fingerprint];
   $temp=$manifest.'.'.bin2hex(random_bytes(8));if(file_put_contents($temp,json_encode($next,JSON_THROW_ON_ERROR),LOCK_EX)===false)throw new \RuntimeException('Search state unavailable');chmod($temp,0600);if(!rename($temp,$manifest)){@unlink($temp);throw new \RuntimeException('Search state unavailable');}
   $this->changes->clearBuildRequest($uid);
   // A write during construction must retain a durable follow-up job.
   if($this->changes->revision($uid)!==$revision||$this->changes->pending($uid))$this->changes->requestBuild($uid);
   return $database;
  }finally{if(isset($input)&&is_resource($input))fclose($input);if(isset($inputPath))@unlink($inputPath);if(isset($tmp)&&($full??false))foreach([$tmp,$tmp.'-wal',$tmp.'-shm'] as $part)if(is_file($part)&&!is_link($part))@unlink($part);if(is_resource($publication)){flock($publication,LOCK_UN);fclose($publication);}flock($lock,LOCK_UN);fclose($lock);}
 }
 public function query(string $uid,array $request):array {
  $dir=$this->changes->directory($uid);$database=$dir.'/index.sqlite3';$state='ready';
  foreach([$database,$database.'.sessions',$dir.'/build.lock',$dir.'/index.lock'] as $path)if(is_link($path))throw new \RuntimeException('Unsafe search index');

  if (!is_file($database)) {
   // Honest preparing state for cold index: do not block HTTP request with heavy process import!
   // Request background build via durable coalescing marker without mutating account revision.
   $this->changes->requestBuild($uid);
   return [
    'items' => [],
    'next' => null,
    'more' => false,
    'total' => 0,
    'mode' => 'lexical',
    'embedding_state' => $request['embedding_enabled'] ? 'not_indexed' : 'disabled',
    'search_backend' => 'sqlite',
    'index_state' => 'preparing'
   ];
  }

  $building=fopen($dir.'/build.lock','c');if(!$building)throw new \RuntimeException('Search index unavailable');
  try{
   if(!flock($building,LOCK_SH|LOCK_NB)){
    $state='updating';
   }else{
    flock($building,LOCK_UN);
    // Existing index present: continue serving from existing database while requesting background repair
    $manifest=$dir.'/manifest.json';
    $mState=is_file($manifest)?json_decode((string)file_get_contents($manifest),true):[];
    $rev=$this->changes->revision($uid);
    $pending=$this->changes->pending($uid);
    $needsRebuild=($mState['schema']??0)!==1||($mState['catalog_revision']??'')!==$rev||!empty($pending)||time()-(int)($mState['catalog_at']??0)>300;
    if($needsRebuild){
     $state='updating';
     $this->changes->requestBuild($uid);
    }
   }
  }finally{fclose($building);}

  $lock=fopen($dir.'/index.lock','c');
  if(!$lock)throw new \RuntimeException('Search index unavailable');
  $until=microtime(true)+10;while(!flock($lock,LOCK_SH|LOCK_NB)){if(microtime(true)>$until){fclose($lock);throw new \RuntimeException('Search index is being updated');}usleep(100000);}
  try{
   $p=$this->process($database,'query');$p->setTimeout(35);$p->setInput(json_encode($request,JSON_THROW_ON_ERROR));$p->run();
   if(!$p->isSuccessful()&&str_contains($p->getErrorOutput(),'SearchSessionExpired'))throw new \InvalidArgumentException('Die Suchseite ist abgelaufen. Bitte erneut suchen.');
   if(!$p->isSuccessful()||strlen($p->getOutput())>4*1024*1024)throw new \RuntimeException('Search query unavailable');
   $value=json_decode($p->getOutput(),true,64,JSON_THROW_ON_ERROR);if(!is_array($value)||!isset($value['items'],$value['embedding_state']))throw new \RuntimeException('Invalid search response');
   return $value+['search_backend'=>'sqlite','index_state'=>$state];
  }finally{flock($lock,LOCK_UN);fclose($lock);}
 }
 /** Bounded lexical fallback; never decodes vectors or buffers full AI payloads. */
 public function fallback(string $uid,array $request):array {
  preg_match_all('/[\p{L}\p{N}_]+/u',mb_strtolower($request['query']),$matches);$words=array_slice(array_unique($matches[0]),0,16);$hits=[];$scores=[];
  foreach($this->records($uid,null,false) as $line){$doc=json_decode($line,true,64,JSON_THROW_ON_ERROR);if($doc['type']!=='document')continue;
   if(!array_filter($request['roots'],fn($root)=>$root==='/'||str_starts_with($doc['path'],$root.'/')))continue;
   if($request['kind']!==''&&$doc['kind']!==$request['kind'])continue;
   $date=Dates::resolve($doc['path'],$doc['metadata']['capture']??null,$doc['mtime'],$request['mode'],$request['timezone']);
   if($request['period']!==''&&!str_starts_with($date['date'],$request['period']))continue;
   $texts=[];$walk=function($value)use(&$walk,&$texts){if(is_array($value))foreach($value as $entry)$walk($entry);elseif(is_string($value)||is_int($value)||is_float($value))$texts[]=(string)$value;};$walk($doc['metadata']);
   $fields=['name'=>[$doc['name'],8],'path'=>[$doc['path'],2],'metadata'=>[implode(' ',$texts),1],'description'=>[$doc['description'],1],'tags'=>[$doc['tags'],3],'objects'=>[$doc['objects'],3],'ocr'=>[$doc['ocr'],2]];
   $score=0;$sources=[];foreach($words as $word)foreach($fields as $source=>[$value,$weight])if(str_contains(mb_strtolower($value),$word)){$score+=$weight;$sources[$source]=true;}
   if(!$score)continue;$id=$doc['file_id'];$scores[$id]=$score;$hits[$id]=['file_id'=>$id,'path'=>$doc['path'],'etag'=>$doc['etag'],'search_match'=>['query'=>trim($request['query']),'sources'=>array_keys($sources)]];
  }
  uksort($scores,fn($a,$b)=>($scores[$b]<=>$scores[$a])?:($a<=>$b));$ids=array_keys($scores);$offset=$request['offset'];$limit=$request['limit'];$page=array_slice($ids,$offset,$limit);$next=$offset+count($page);
  return ['items'=>array_map(fn($id)=>$hits[$id],$page),'next'=>$next<count($ids)?$next:null,'more'=>$next<count($ids),'total'=>count($ids),'mode'=>'lexical','embedding_state'=>$request['embedding_enabled']?'unavailable':'disabled','search_backend'=>'nextcloud_lexical_fallback','index_state'=>'unavailable'];
 }
 public function cleanup():void {
  foreach(glob($this->changes->directory().'/*/manifest.json')?:[] as $manifest){
   if(is_link($manifest)||is_link(dirname($manifest)))continue;
   $m=json_decode((string)file_get_contents($manifest),true);$uid=$m['uid']??null;
   if(!is_string($uid)||basename(dirname($manifest))!==hash('sha256',$uid))continue;
   $database=dirname($manifest).'/index.sqlite3';if(is_link($database)||!is_file($database))continue;
   $lock=fopen(dirname($manifest).'/index.lock','c');if(!$lock)continue;
   try{if(flock($lock,LOCK_EX|LOCK_NB)){$p=$this->process($database,'clean');$p->setTimeout(10);$p->run();}}
   finally{flock($lock,LOCK_UN);fclose($lock);}
  }
 }
 public function status(string $uid):array {
  $dir=$this->changes->directory($uid);$manifest=$dir.'/manifest.json';if(is_link($manifest))throw new \RuntimeException('Unsafe search index');
  $m=is_file($manifest)?json_decode((string)file_get_contents($manifest),true):[];
  try{$this->python();$state=is_file($dir.'/index.sqlite3')?'ready':'not_indexed';}catch(\Throwable){$state='unavailable';}
  return ['backend'=>'sqlite','state'=>$state,'updated_at'=>$m['updated_at']??null,'pending_files'=>count($this->changes->pending($uid))];
 }
}
