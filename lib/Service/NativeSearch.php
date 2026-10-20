<?php
declare(strict_types=1);
namespace OCA\Nextgallery\Service;

use OCP\IDBConnection;
use OCP\Files\{IRootFolder,Folder,File};
use OCP\FilesMetadata\IFilesMetadataManager;
use OCA\Nextgallery\AI\Service\{Settings,EmbeddingClient};

/** Read-only mobile search, scoped to readable Android folders, independent of web galleries. */
class NativeSearch {
 public function __construct(private IDBConnection $db,private IRootFolder $root,private IFilesMetadataManager $metadata,private Settings $settings,private EmbeddingClient $client,private ?SearchIndex $index=null,private ?NativePeople $people=null) {}
 private function peopleService(): NativePeople {return $this->people??=\OCP\Server::get(NativePeople::class);}

 private function folders(string $uid,array $roots):array {
  if(!array_is_list($roots)||count($roots)>32)throw new \InvalidArgumentException('Maximal 32 Ordner auswählen.');
  $home=$this->root->getUserFolder($uid);$folders=[];
  foreach($roots as $path){
   if(!is_string($path)||strlen($path)>4096||str_contains($path,"\0")||str_contains($path,'\\')||preg_match('~(^|/)(\.|\.\.)(/|$)~',$path))throw new \InvalidArgumentException('Ungültiger Ordner.');
   $path='/'.trim($path,'/');
   try{$folder=$path==='/'?$home:$home->get($path);}catch(\OCP\Files\NotFoundException|\OCP\Files\NotPermittedException){throw new \InvalidArgumentException('Ordner nicht verfügbar.');}
   if(!$folder instanceof Folder||!$folder->isReadable())throw new \InvalidArgumentException('Ordner nicht verfügbar.');
   $folders[$path]=$folder;
  }
  ksort($folders);$selected=[];
  foreach($folders as $path=>$folder)if(!array_filter(array_keys($selected),fn($parent)=>$parent==='/'||str_starts_with($path,$parent.'/')))$selected[$path]=$folder;
  return [$home,$selected];
 }
 private function engine():SearchIndex {return $this->index??=\OCP\Server::get(SearchIndex::class);}
 public function search(string $uid,string $query,array $roots,string $kind='',int $offset=0,int $limit=60,string $phase='hybrid',string $period='',string $mode='folder',string $timezone='UTC',string $search_token='',bool $vectorOnly=false,string $subjectId=''):array {
  if(!in_array($phase,['text','hybrid'],true)||!in_array($kind,['','image','video'],true)||$offset<0||$limit<1||$limit>200||mb_strlen($query)>200||($period!==''&&!preg_match('/^\d{4}(?:-\d{2})?(?:-\d{2})?$/D',$period)))throw new \InvalidArgumentException('Suchanfrage ungültig.');
  if(!in_array($mode,['folder','metadata','filename'],true)||!in_array($timezone,\DateTimeZone::listIdentifiers(),true))throw new \InvalidArgumentException('Datumseinstellung ungültig.');
  if($search_token!==''&&!preg_match('/^[a-f0-9]{32}$/D',$search_token))throw new \InvalidArgumentException('Suchseite ungültig.');
  if($subjectId!==''){if(!preg_match('/^[a-f0-9]{32}$/iD',$subjectId))throw new \InvalidArgumentException('Subjekt ungültig.');$subjectId=strtolower($subjectId);}
  [$home,$folders]=$this->folders($uid,$roots);$roots=array_keys($folders);
  if(!trim($query)||!$roots)return ['items'=>[],'next'=>null,'more'=>false,'mode'=>'lexical','embedding_state'=>'not_indexed','search_backend'=>'sqlite','index_state'=>'ready']+($subjectId!==''?['subject_id'=>$subjectId]:[]);
  $config=$this->settings->workerConfig()['embedding'];$context=Settings::embeddingSignature(['user_id'=>$uid,'embedding'=>$config]).($subjectId!==''?':subject:'.$subjectId:'');
  $request=['query'=>trim($query),'roots'=>$roots,'kind'=>$kind,'offset'=>$offset,'limit'=>$limit,'phase'=>'text','period'=>$period,'mode'=>$mode,'timezone'=>$timezone,'embedding_enabled'=>$config['enabled'],'vector_only'=>$vectorOnly,'context'=>$context];
  if($search_token!==''){$request['search_token']=$search_token;$request['phase']=$phase;}
  $index=$this->engine();
  try{$result=$index->query($uid,$request);}catch(\InvalidArgumentException $e){throw $e;}catch(\Throwable $e){if($search_token!=='')throw $e;$result=$index->fallback($uid,$request);}
  if($phase==='hybrid'&&$result['embedding_state']==='pending'){
   $request['phase']='hybrid';
   try{$info=$this->client->info($config);$request['vector']=$this->client->query($config,trim($query),$info);$request['space']=EmbeddingClient::space($info,$config);}
   catch(\Throwable){$request['embedding_error']=true;}
   try{$result=$index->query($uid,$request);}catch(\Throwable){$result=$index->fallback($uid,$request);}
  }
  $request['search_token']=$result['search_token']??'';
  $subjectFileIds=$subjectId!==''?$this->peopleService()->subjectFileIds($uid,$subjectId,$roots):[];
  $items=[];$cursor=$offset;$bytes=0;$total=(int)($result['total']??0);$page=$result;
  do{
   foreach($page['items'] as $hit){
    $position=$cursor;$cursor++;
    if($subjectId!==''&&!isset($subjectFileIds[(int)$hit['file_id']]))continue;
    try{$file=$home->get($hit['path']);}catch(\OCP\Files\NotFoundException|\OCP\Files\NotPermittedException){continue;}
    if(!$file instanceof File||!$file->isReadable()||$file->getId()!==(int)$hit['file_id']||!hash_equals($file->getEtag(),$hit['etag']))continue;
    if($subjectId!==''&&!hash_equals($subjectFileIds[$file->getId()],$file->getEtag()))continue;
    $path='/'.ltrim($home->getRelativePath($file->getPath()),'/');
    if($path!==$hit['path']||!array_filter($roots,fn($root)=>$root==='/'||str_starts_with($path,$root.'/')))continue;
    $meta=$index->metadata($file);$date=Dates::resolve($path,$meta['capture']??null,$file->getMTime(),$mode,$timezone);
    if($period!==''&&!str_starts_with($date['date'],$period))continue;
    $row=$this->db->executeQuery('SELECT etag,metadata_json,updated_at,description,tags_text,objects_text,ocr_text FROM *PREFIX*nextgallery_ai_meta WHERE user_id=? AND file_id=?',[$uid,$file->getId()]);
    try{$ai=$row->fetch();}finally{$row->closeCursor();}
    $analysis=$ai?json_decode($ai['metadata_json'],true,64,JSON_THROW_ON_ERROR)['analysis']??[]:[];
    if($ai&&hash_equals($file->getEtag(),$ai['etag'])){$meta['mediaAi']=json_decode($ai['metadata_json'],true,64,JSON_THROW_ON_ERROR);$meta['aiUpdatedAt']=(int)$ai['updated_at'];$meta['aiEtag']=$ai['etag'];}
    // Cached ranks survive indexing changes, but deleted/stale content never explains a hit.
    preg_match_all('/[\p{L}\p{N}_]+/u',mb_strtolower(trim($query)),$wordMatches);$words=array_slice(array_unique($wordMatches[0]),0,16);
    $metadataText=[];$walk=function($value)use(&$walk,&$metadataText){if(is_array($value))foreach($value as $part)$walk($part);elseif(is_string($value)||is_int($value)||is_float($value))$metadataText[]=(string)$value;};$walk($index->metadata($file));
    $descCurrent=$ai&&hash_equals($file->getEtag(),$analysis['stage_etags']['descriptions']??$ai['etag']);$ocrCurrent=$ai&&hash_equals($file->getEtag(),$analysis['stage_etags']['ocr']??$ai['etag']);
    $values=['name'=>$file->getName(),'path'=>$path,'metadata'=>implode(' ',$metadataText),'description'=>$descCurrent?$ai['description']:'','tags'=>$descCurrent?$ai['tags_text']:'','objects'=>$descCurrent?$ai['objects_text']:'','ocr'=>$ocrCurrent?$ai['ocr_text']:''];
    $hit['search_match']['sources']=array_values(array_filter($hit['search_match']['sources'],function($source)use($values,$words,$uid,$file,$result){
     if($source==='embedding'){
      $space=$result['embedding_space']??null;if(!is_string($space))return false;
      $r=$this->db->executeQuery('SELECT file_id FROM *PREFIX*nextgallery_ai_vectors WHERE user_id=? AND file_id=? AND etag=? AND space=? LIMIT 1',[$uid,$file->getId(),$file->getEtag(),$space]);try{return (bool)$r->fetchOne();}finally{$r->closeCursor();}
     }
     return isset($values[$source])&&array_filter($words,fn($word)=>str_contains(mb_strtolower($values[$source]),$word));
    }));
    if(!$hit['search_match']['sources'])continue;
    $hit['search_match']['sources']=array_values(array_filter(['name','path','metadata','description','tags','objects','ocr','embedding'],fn($source)=>in_array($source,$hit['search_match']['sources'],true)));
    $item=['id'=>$file->getId(),'name'=>$file->getName(),'path'=>$path,'kind'=>str_starts_with($file->getMimeType(),'image/')?'image':'video','etag'=>$file->getEtag(),'mtime'=>$file->getMTime(),'size'=>$file->getSize(),'metadata'=>$meta,'search_match'=>$hit['search_match']];
    $size=strlen(json_encode($item,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE));
    if($items&&$bytes+$size>2*1024*1024){$cursor=$position;break 2;}
    $items[]=$item;$bytes+=$size;if(count($items)>=$limit)break 2;
   }
   if($cursor>=$total||!$page['items'])break;
   $request['offset']=$cursor;$request['limit']=$limit-count($items);$page=($result['index_state']??'ready')==='unavailable'?$index->fallback($uid,$request):$index->query($uid,$request);$total=(int)$page['total'];
  }while(count($items)<$limit);
  return ['items'=>$items,'next'=>$cursor<$total?$cursor:null,'more'=>$cursor<$total,'mode'=>$result['mode'],'embedding_state'=>$result['embedding_state'],'search_backend'=>$result['search_backend']??'sqlite','index_state'=>$result['index_state']??'ready','search_token'=>$result['search_token']??null]+($subjectId!==''?['subject_id'=>$subjectId]:[]);
 }
}
