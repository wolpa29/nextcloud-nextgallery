<?php
declare(strict_types=1);
namespace OCA\Nextgallery\Service;
use OCP\{IConfig,IDBConnection};
use OCP\Files\{IRootFolder,Folder,File};
use OCP\Lock\ILockingProvider;
use OCP\FilesMetadata\IFilesMetadataManager;
use Symfony\Component\Process\Process;
class Library {
 public function __construct(private IConfig $config,private IDBConnection $db,private IRootFolder $root,private ILockingProvider $locks,private IFilesMetadataManager $metadata) {}
 public function settings(string $uid):array {
  $p=json_decode($this->config->getUserValue($uid,'nextgallery','settings','{}'),true)??[];
  return array_replace(['roots'=>[],'mode'=>'folder','grouping'=>'month','order'=>'desc','tile'=>180,'timezone'=>'Europe/Vienna','offlineLimit'=>1000],$p);
 }
 public function cacheKey(string $uid):string {return substr(hash('sha256',$uid.'|'.$this->config->getUserValue($uid,'nextgallery','epoch','initial')),0,32);}
 public function state(string $uid):array {return json_decode($this->config->getUserValue($uid,'nextgallery','scan','{}'),true)??[];}
 private function stateSave(string $uid,array $s):void {$this->config->setUserValue($uid,'nextgallery','scan',json_encode($s,JSON_THROW_ON_ERROR));}
 private function lock(string $uid):void {$this->locks->acquireLock(hash('sha256','nextgallery:'.$uid),ILockingProvider::LOCK_EXCLUSIVE);}
 private function unlock(string $uid):void {$this->locks->releaseLock(hash('sha256','nextgallery:'.$uid),ILockingProvider::LOCK_EXCLUSIVE);}
 public function save(string $uid,array $settings):array {
  $this->lock($uid);
  try {
   $p=$this->settings($uid);$old=$p;$home=$this->root->getUserFolder($uid);
   if (isset($settings['roots'])) {
    if (!is_array($settings['roots']) || count($settings['roots'])>32) throw new \InvalidArgumentException('Maximal 32 Ordner auswählen.');
    $roots=[];
    foreach ($settings['roots'] as $path) {
     if (!is_string($path) || str_contains($path,"\0") || in_array('..',explode('/',$path),true)) throw new \InvalidArgumentException('Ungültiger Ordner.');
     $path='/'.trim($path,'/');
     if ($path==='/' || !$home->nodeExists($path) || !($home->get($path) instanceof Folder) || !$home->get($path)->isReadable()) throw new \InvalidArgumentException('Bitte einen vorhandenen Unterordner auswählen.');
     $roots[]=$path;
    }
    sort($roots);$selected=[];
    foreach (array_unique($roots) as $r) {if (!array_filter($selected,fn($s)=>str_starts_with($r,$s.'/'))) $selected[]=$r;}
    $p['roots']=$selected;
   }
   foreach (['mode'=>['folder','metadata','filename'],'grouping'=>['year','month','day','rail'],'order'=>['asc','desc']] as $key=>$allowed) {
    if (isset($settings[$key])) {if (!in_array($settings[$key],$allowed,true)) throw new \InvalidArgumentException('Ungültige Einstellung: '.$key);$p[$key]=$settings[$key];}
   }
   if (isset($settings['timezone'])) {if (!in_array($settings['timezone'],\DateTimeZone::listIdentifiers(),true)) throw new \InvalidArgumentException('Ungültige Zeitzone.');$p['timezone']=$settings['timezone'];}
   foreach (['tile'=>[100,320],'offlineLimit'=>[1,5000]] as $key=>[$min,$max]) if (isset($settings[$key])) $p[$key]=max($min,min($max,(int)$settings[$key]));
   $this->config->setUserValue($uid,'nextgallery','settings',json_encode($p,JSON_THROW_ON_ERROR));
   $q=$this->db->getQueryBuilder();$q->select('user_id')->from('nextgallery_users')->where($q->expr()->eq('user_id',$q->createNamedParameter($uid)));
   if (!$q->executeQuery()->fetchOne()) {$q=$this->db->getQueryBuilder();$q->insert('nextgallery_users')->setValue('user_id',$q->createNamedParameter($uid))->executeStatement();}
   if ($p['roots']!==$old['roots'] || $p['mode']!==$old['mode'] || $p['timezone']!==$old['timezone']) {
    $q=$this->db->getQueryBuilder();$q->delete('nextgallery_media')->where($q->expr()->eq('user_id',$q->createNamedParameter($uid)))->executeStatement();
    $this->config->setUserValue($uid,'nextgallery','epoch',bin2hex(random_bytes(16)));
    $this->start($uid,$p);
   }
   return $p;
  } finally {$this->unlock($uid);}
 }
 public function users():array {$q=$this->db->getQueryBuilder();return $q->select('user_id')->from('nextgallery_users')->executeQuery()->fetchAll(\PDO::FETCH_COLUMN);}
 public function forget(string $uid):void {
  $this->lock($uid);
  try {foreach(['nextgallery_media','nextgallery_users'] as $table){$q=$this->db->getQueryBuilder();$q->delete($table)->where($q->expr()->eq('user_id',$q->createNamedParameter($uid)))->executeStatement();}}
  finally {$this->unlock($uid);}
 }
 private function start(string $uid,array $p):array {
  $s=['running'=>count($p['roots'])>0,'root'=>0,'after'=>0,'generation'=>bin2hex(random_bytes(16)),'seen'=>0,'errors'=>0,'started'=>time(),'completed'=>0];
  $this->stateSave($uid,$s);return $s;
 }
 private function inRoots(string $path,array $roots):bool {foreach ($roots as $r) if (str_starts_with($path,rtrim($r,'/').'/')) return true;return false;}
 public function file(string $uid,int $id):File {
  $home=$this->root->getUserFolder($uid);$p=$this->settings($uid);
  foreach ($home->getById($id) as $node) {
   if ($node instanceof File && $node->isReadable() && $this->inRoots('/'.ltrim($home->getRelativePath($node->getPath()),'/'),$p['roots']) && $this->kind($node->getMimeType())) return $node;
  }
  throw new \OCP\Files\NotFoundException('Medium nicht verfügbar.');
 }
 private function kind(string $mime):?string {
  if (in_array($mime,['image/jpeg','image/png','image/webp','image/gif','image/avif','image/heic','image/heif','image/tiff','image/bmp'],true)) return 'image';
  return in_array($mime,['video/mp4','video/quicktime','video/webm','video/x-matroska','video/3gpp','video/x-msvideo'],true)?'video':null;
 }
 private function extract(File $f):array {
  $raw=null;$gps=null;$width=0;$height=0;$duration=null;
  try {
   $m=$this->metadata->getMetadata($f->getId());
   if ($m->hasKey('photos-exif')) {$e=$m->getArray('photos-exif');$raw=$e['DateTimeOriginal']??null;$width=(int)($e['ExifImageWidth']??0);$height=(int)($e['ExifImageLength']??0);}
   if ($m->hasKey('photos-gps')) {$g=$m->getArray('photos-gps');if (isset($g['latitude'],$g['longitude'])) $gps=[(float)$g['latitude'],(float)$g['longitude']];}
  } catch (\Throwable) {}
  if ($f->getMimeType()==='image/jpeg' && $f->getSize()<=64*1024*1024 && function_exists('exif_read_data')) {
   $stream=$f->fopen('rb');
   if (is_resource($stream)) {try {$chunk=stream_set_chunk_size($stream,1);$e=@exif_read_data($stream,null,true);stream_set_chunk_size($stream,$chunk);if ($e) {
    $d=$e['EXIF']['DateTimeOriginal']??$e['IFD0']['DateTimeOriginal']??null;$offset=$e['EXIF']['OffsetTimeOriginal']??$e['IFD0']['OffsetTimeOriginal']??'';if ($d) $raw=$d.$offset;
    $gps=Dates::gps($e)??$gps;$width=(int)($e['COMPUTED']['Width']??$width);$height=(int)($e['COMPUTED']['Height']??$height);
   }} finally {fclose($stream);}}
  }
  if ($this->kind($f->getMimeType())==='video' && $f->getStorage()->isLocal() && is_executable('/usr/bin/ffprobe')) {
   try {
    // Folder search returns jailed relative paths. Resolve the storage's real
    // cache entry before handing a local file to FFprobe.
    $entry=$f->getStorage()->getCache()->get($f->getId());
    $path=$f->getStorage()->getLocalFile($entry->getPath());
    $proc=new Process(['/usr/bin/ffprobe','-v','error','-show_entries','format=duration:format_tags=creation_time,location,com.apple.quicktime.creationdate,com.apple.quicktime.location.ISO6709:stream=width,height','-of','json',$path]);$proc->setTimeout(3);$proc->mustRun();
    $v=json_decode($proc->getOutput(),true);$tags=$v['format']['tags']??[];
    $raw=$tags['com.apple.quicktime.creationdate']??$tags['creation_time']??$raw;$duration=isset($v['format']['duration'])?(float)$v['format']['duration']:null;
    foreach ($v['streams']??[] as $stream) if (!empty($stream['width'])) {$width=(int)$stream['width'];$height=(int)$stream['height'];break;}
    if (preg_match('/^([+-]\d+(?:\.\d+)?)([+-]\d+(?:\.\d+)?)/',$tags['com.apple.quicktime.location.ISO6709']??$tags['location']??'',$a)) $gps=[(float)$a[1],(float)$a[2]];
   } catch (\Throwable) {}
  }
  if ($gps && (!is_finite($gps[0]) || !is_finite($gps[1]) || abs($gps[0])>90 || abs($gps[1])>180)) $gps=null;
  return compact('raw','gps','width','height','duration')+['revision'=>2];
 }
 public function step(string $uid,bool $restart=false,int $limit=100,float $seconds=4):array {
  $this->lock($uid);
  try {
   $p=$this->settings($uid);$s=$this->state($uid);
   if ($restart || !$s) $s=$this->start($uid,$p);
   if (!$s['running']) return $s;
   $home=$this->root->getUserFolder($uid);$deadline=microtime(true)+$seconds;$handled=0;
   while ($s['root']<count($p['roots']) && $handled<$limit && microtime(true)<$deadline) {
    try {$folder=$home->get($p['roots'][$s['root']]);if (!$folder instanceof Folder || !$folder->isReadable()) throw new \RuntimeException('Ordner nicht lesbar.');$nodes=$folder->search(Search::query((int)$s['after'],min(100,$limit-$handled)));}
    catch (\Throwable $e) {$s['errors']++;$s['lastError']=get_class($e).': '.$e->getMessage();$s['root']++;$s['after']=0;continue;}
    if (!$nodes) {$s['root']++;$s['after']=0;continue;}
    foreach ($nodes as $node) {
     $s['after']++;$handled++;
     if ($node instanceof File && $node->isReadable() && ($kind=$this->kind($node->getMimeType()))) {
      try {
       $path='/'.ltrim($home->getRelativePath($node->getPath()),'/');
       if (!$this->inRoots($path,$p['roots'])) continue;
       $q=$this->db->getQueryBuilder();$q->select('etag','payload')->from('nextgallery_media')->where($q->expr()->eq('user_id',$q->createNamedParameter($uid)))->andWhere($q->expr()->eq('file_id',$q->createNamedParameter($node->getId())));$existing=$q->executeQuery()->fetch();
       $old=$existing?json_decode($existing['payload'],true):null;
       $retryVideo=$kind==='video' && ($old['metadata']['duration']??null)===null && $node->getStorage()->isLocal() && is_executable('/usr/bin/ffprobe');
       $meta=$old && !$retryVideo && $existing['etag']===$node->getEtag() && ($old['metadata']['revision']??0)===2?$old['metadata']:$this->extract($node);
       $date=Dates::resolve($path,$meta['raw'],$node->getMTime(),$p['mode'],$p['timezone']);
       $item=['id'=>$node->getId(),'name'=>$node->getName(),'path'=>$path,'kind'=>$kind,'mime'=>$node->getMimeType(),'etag'=>$node->getEtag(),'date'=>$date,'mtime'=>$node->getMTime(),'size'=>$node->getSize(),'metadata'=>$meta];
       $values=['name_text'=>mb_strtolower($node->getName()),'path_text'=>mb_strtolower($path),'sort_key'=>$date['sort'],'period'=>$date['period'],'kind'=>$kind,'etag'=>$node->getEtag(),'generation'=>$s['generation'],'latitude'=>$meta['gps'][0]??null,'longitude'=>$meta['gps'][1]??null,'payload'=>json_encode($item,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE)];
       $q=$this->db->getQueryBuilder();
       if ($existing) {$q->update('nextgallery_media')->where($q->expr()->eq('user_id',$q->createNamedParameter($uid)))->andWhere($q->expr()->eq('file_id',$q->createNamedParameter($node->getId())));foreach($values as $k=>$v) $q->set($k,$q->createNamedParameter($v));}
       else {$q->insert('nextgallery_media')->setValue('user_id',$q->createNamedParameter($uid))->setValue('file_id',$q->createNamedParameter($node->getId()));foreach($values as $k=>$v) $q->setValue($k,$q->createNamedParameter($v));}
       $q->executeStatement();$s['seen']++;
      } catch (\Throwable $e) {$s['errors']++;$s['lastError']=get_class($e).': '.$e->getMessage();}
     }
     if (microtime(true)>=$deadline || $handled>=$limit) break;
    }
   }
   if ($s['root']>=count($p['roots'])) {
    // A complete sweep removes deleted/moved media; failed roots also stop being exposed.
    $q=$this->db->getQueryBuilder();$q->delete('nextgallery_media')->where($q->expr()->eq('user_id',$q->createNamedParameter($uid)))->andWhere($q->expr()->neq('generation',$q->createNamedParameter($s['generation'])))->executeStatement();
    $s['running']=false;$s['completed']=time();
   }
   $this->stateSave($uid,$s);return $s;
  } finally {$this->unlock($uid);}
 }
 public function folders(string $uid,string $path):array {
  if (str_contains($path,"\0") || in_array('..',explode('/',$path),true)) throw new \InvalidArgumentException('Ungültiger Pfad.');
  $home=$this->root->getUserFolder($uid);$folder=$path==='/'?$home:$home->get($path);
  if (!$folder instanceof Folder || !$folder->isReadable()) throw new \InvalidArgumentException('Ordner nicht lesbar.');
  $items=[];foreach($folder->getDirectoryListing() as $n) if ($n instanceof Folder && $n->isReadable()) $items[]=['name'=>$n->getName(),'path'=>'/'.ltrim($home->getRelativePath($n->getPath()),'/')];
  usort($items,fn($a,$b)=>strnatcasecmp($a['name'],$b['name']));return $items;
 }
 private function query(string $uid,string $kind='',string $period='') {
  $q=$this->db->getQueryBuilder();$q->from('nextgallery_media')->where($q->expr()->eq('user_id',$q->createNamedParameter($uid)));
  if (in_array($kind,['image','video'],true)) $q->andWhere($q->expr()->eq('kind',$q->createNamedParameter($kind)));
  if ($period && preg_match('/^\d{4}(?:-\d{2})?(?:-\d{2})?$/',$period)) $q->andWhere($q->expr()->like('period',$q->createNamedParameter($period.'%')));
  return $q;
 }
 public function summary(string $uid,string $kind=''):array {
  $p=$this->settings($uid);$q=$this->query($uid,$kind);$rows=$q->select('period','kind')->addSelect($q->func()->count('*','n'))->groupBy('period','kind')->executeQuery()->fetchAll();
  $groups=[];$total=0;$videos=0;
  foreach($rows as $r) {
   $period=$r['period'];$key=match($p['grouping']){'year'=>substr($period,0,4),'day'=>$period,default=>substr($period,0,7)};
   if (!isset($groups[$key])) $groups[$key]=['key'=>$key,'count'=>0];$groups[$key]['count']+=(int)$r['n'];$total+=(int)$r['n'];if ($r['kind']==='video') $videos+=(int)$r['n'];
  }
  if ($p['order']==='asc') ksort($groups);else krsort($groups);
  return ['groups'=>array_values($groups),'total'=>$total,'videos'=>$videos,'state'=>$this->state($uid),'settings'=>$p];
 }
 public function items(string $uid,string $kind='',string $period='',int $offset=0,int $limit=150,bool $geo=false):array {
  $p=$this->settings($uid);$q=$this->query($uid,$kind,$period);$q->select('file_id','payload');
  if ($geo) $q->andWhere($q->expr()->isNotNull('latitude'));
  $direction=$p['order']==='asc'?'ASC':'DESC';$rows=$q->orderBy('sort_key',$direction)->addOrderBy('file_id',$direction)->setFirstResult(max(0,$offset))->setMaxResults(max(1,min(200,$limit)))->executeQuery()->fetchAll();
  return ['items'=>$this->hydrate($uid,$rows),'next'=>count($rows)?max(0,$offset)+count($rows):null,'more'=>count($rows)===max(1,min(200,$limit))];
 }
 public function hydrate(string $uid,array $rows):array {
  $items=[];
  foreach($rows as $row) {
   try {$f=$this->file($uid,(int)$row['file_id']);$item=json_decode($row['payload'],true);
    // Revalidate current user permissions, roots and path before returning identifying data.
    $home=$this->root->getUserFolder($uid);$path='/'.ltrim($home->getRelativePath($f->getPath()),'/');
    if ($path!==$item['path'] || $f->getEtag()!==$item['etag']) continue;
    $item['metadata']['capture']=$item['metadata']['raw']??null;
    $item['mtime']=$f->getMTime();$item['size']=$f->getSize();
    unset($item['metadata']['raw']);$items[]=$item;
   } catch (\Throwable) {}
  }
  return $items;
 }
}
