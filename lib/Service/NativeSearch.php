<?php
declare(strict_types=1);
namespace OCA\Nextgallery\Service;

use OCP\IDBConnection;
use OCP\Files\{IRootFolder,Folder,File};
use OCP\FilesMetadata\IFilesMetadataManager;
use OCA\Nextgallery\AI\Service\{Settings,EmbeddingClient};

/** Read-only mobile search, scoped to readable Android folders, independent of web galleries. */
class NativeSearch {
 public function __construct(private IDBConnection $db,private IRootFolder $root,private IFilesMetadataManager $metadata,private Settings $settings,private EmbeddingClient $client) {}

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
 private function mediaMetadata(File $file):array {
  $out=[];
  try{
   $meta=$this->metadata->getMetadata($file->getId());
   foreach(['photos-exif'=>'exif','photos-ifd0'=>'ifd0','photos-gps'=>'gps'] as $key=>$name)if($meta->hasKey($key))$out[$name]=$meta->getArray($key);
   if($meta->hasKey('photos-original_datetime'))$out['capture']=$meta->getString('photos-original_datetime');
   $exif=$out['exif']??[];$out['capture']??=$exif['DateTimeOriginal']??$exif['EXIF']['DateTimeOriginal']??null;
   if(isset($out['gps']['latitude'],$out['gps']['longitude']))$out['gps']=[(float)$out['gps']['latitude'],(float)$out['gps']['longitude']];
  }catch(\Throwable){} // Optional cached metadata; never open original media during a search.
  return $out;
 }
 public function search(string $uid,string $query,array $roots,string $kind='',int $offset=0,int $limit=60,string $phase='hybrid',string $period='',string $mode='folder',string $timezone='UTC'):array {
  if(!in_array($phase,['text','hybrid'],true)||!in_array($kind,['','image','video'],true)||$offset<0||$limit<1||$limit>200||mb_strlen($query)>200||($period!==''&&!preg_match('/^\d{4}(?:-\d{2})?(?:-\d{2})?$/D',$period)))throw new \InvalidArgumentException('Suchanfrage ungültig.');
  if(!in_array($mode,['folder','metadata','filename'],true)||!in_array($timezone,\DateTimeZone::listIdentifiers(),true))throw new \InvalidArgumentException('Datumseinstellung ungültig.');
  [$home,$folders]=$this->folders($uid,$roots);
  preg_match_all('/[\p{L}\p{N}_]+/u',mb_strtolower(trim($query)),$matches);$words=array_slice(array_values(array_unique($matches[0])),0,16);
  if(!$words||!$folders)return ['items'=>[],'next'=>null,'more'=>false,'mode'=>'lexical','embedding_state'=>'not_indexed'];
  $files=[];$paths=[];$etags=[];$metas=[];$lexical=[];$sources=[];
  foreach($folders as $rootPath=>$folder){
   for($start=0;;$start+=count($nodes)){
    $nodes=$folder->search(Search::query($start,500));if(!$nodes)break;
    foreach($nodes as $file){
     if(!$file instanceof File||!$file->isReadable()||isset($files[$file->getId()]))continue;
     $mime=$file->getMimeType();$type=str_starts_with($mime,'image/')?'image':(str_starts_with($mime,'video/')?'video':'');
     if(!$type||($kind!==''&&$type!==$kind))continue;
     $path='/'.ltrim($home->getRelativePath($file->getPath()),'/');
     if($rootPath!=='/'&&!str_starts_with($path,$rootPath.'/'))continue;
     $meta=$this->mediaMetadata($file);$date=Dates::resolve($path,$meta['capture']??null,$file->getMTime(),$mode,$timezone);
     if($period!==''&&!str_starts_with($date['date'],$period))continue;
     $id=$file->getId();$files[$id]=$file;$paths[$id]=$path;$etags[$id]=$file->getEtag();$metas[$id]=$meta;
     $score=0;foreach($words as $word)foreach([[mb_strtolower($file->getName()),8,'name'],[mb_strtolower($path),2,'path'],[mb_strtolower(json_encode($meta,JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_UNICODE)),1,'metadata']] as [$text,$weight,$src])if(str_contains($text,$word)){$score+=$weight;$sources[$id][$src]=true;}
     if($score)$lexical[$id]=$score;
    }
   }
  }
  $config=$this->settings->workerConfig()['embedding'];$vectorRows=[];
  foreach(array_chunk(array_keys($files),500) as $ids){
   $q=$this->db->getQueryBuilder();$q->select('file_id','etag','description','tags_text','objects_text','ocr_text','metadata_json','updated_at')->from('nextgallery_ai_meta')->where($q->expr()->eq('user_id',$q->createNamedParameter($uid)))->andWhere($q->expr()->in('file_id',$q->createNamedParameter($ids,\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
   $rows=$q->executeQuery();try{while($row=$rows->fetch()){
    $id=(int)$row['file_id'];if(!hash_equals($etags[$id],$row['etag']))continue;
    $score=$lexical[$id]??0;foreach($words as $word)foreach(['description'=>['description',1],'tags_text'=>['tags',3],'objects_text'=>['objects',3],'ocr_text'=>['ocr',2]] as $column=>[$src,$weight])if(str_contains(mb_strtolower($row[$column]),$word)){$score+=$weight;$sources[$id][$src]=true;}
    if($score)$lexical[$id]=$score;
    $metas[$id]['mediaAi']=json_decode($row['metadata_json'],true,64,JSON_THROW_ON_ERROR);$metas[$id]['aiUpdatedAt']=(int)$row['updated_at'];$metas[$id]['aiEtag']=$row['etag'];
   }}finally{$rows->closeCursor();}
   if(!$config['enabled'])continue;
   $q=$this->db->getQueryBuilder();$q->select('file_id','etag','space','dimensions');
   if($phase==='hybrid')$q->addSelect('vector_data');
   $q->from('nextgallery_ai_vectors')->where($q->expr()->eq('user_id',$q->createNamedParameter($uid)))->andWhere($q->expr()->in('file_id',$q->createNamedParameter($ids,\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
   $rows=$q->executeQuery();try{while($row=$rows->fetch())if(hash_equals($etags[(int)$row['file_id']],$row['etag']))$vectorRows[]=$row;}finally{$rows->closeCursor();}
  }
  uksort($lexical,fn($a,$b)=>($lexical[$b]<=>$lexical[$a])?:($a<=>$b));$ids=array_keys($lexical);$state=!$config['enabled']?'disabled':($vectorRows?'pending':'not_indexed');$mode='lexical';
  if($phase==='hybrid'&&$vectorRows){
   try{
    $info=$this->client->info($config);$vector=$this->client->query($config,trim($query),$info);$space=EmbeddingClient::space($info,$config);$scores=[];
    foreach($vectorRows as $row){
     if($row['space']!==$space||(int)$row['dimensions']!==count($vector))continue;
     $wire=base64_decode($row['vector_data'],true);if($wire===false||strlen($wire)!==4*count($vector))continue;
     $values=array_values(unpack('g*',$wire));$score=0.;foreach($vector as $i=>$value)$score+=$value*$values[$i];if(!is_finite($score))continue;
     $id=(int)$row['file_id'];$scores[$id]=isset($scores[$id])?max($scores[$id],$score):$score;
    }
    uksort($scores,fn($a,$b)=>($scores[$b]<=>$scores[$a])?:($a<=>$b));$state=$scores?'ready':'model_changed';
    if($scores){$ids=EmbeddingSearch::merge($ids,array_keys($scores));$mode='hybrid';foreach(array_keys($scores) as $id)$sources[$id]['embedding']=true;}
   }catch(\Throwable){$state='unavailable';}
  }
  $page=array_slice($ids,$offset,$limit);$items=[];$next=$offset;$bytes=0;
  foreach($page as $id){
   try{$file=$home->get($paths[$id]);}catch(\OCP\Files\NotFoundException|\OCP\Files\NotPermittedException){$next++;continue;}
   if(!$file instanceof File||!$file->isReadable()||$file->getId()!==$id||!hash_equals($etags[$id],$file->getEtag())){$next++;continue;}$path='/'.ltrim($home->getRelativePath($file->getPath()),'/');if($path!==$paths[$id]){$next++;continue;}
   $item=['id'=>$id,'name'=>$file->getName(),'path'=>$path,'kind'=>str_starts_with($file->getMimeType(),'image/')?'image':'video','etag'=>$file->getEtag(),'mtime'=>$file->getMTime(),'size'=>$file->getSize(),'metadata'=>$metas[$id],'search_match'=>['query'=>trim($query),'sources'=>array_values(array_keys($sources[$id]??[]))]];
   // Full OCR/video metadata can be large. Keep complete records and continue on the next page.
   $size=strlen(json_encode($item,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE));
   if($items&&$bytes+$size>2*1024*1024)break;
   $items[]=$item;$bytes+=$size;$next++;
  }
  return ['items'=>$items,'next'=>$next,'more'=>$next<count($ids),'mode'=>$mode,'embedding_state'=>$state];
 }
}
