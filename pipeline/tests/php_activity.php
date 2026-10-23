<?php
declare(strict_types=1);
namespace OCP {interface IConfig {} interface IDBConnection {}}
namespace OCP\AppFramework\OCS {class OCSBadRequestException extends \Exception {}}
namespace {
require __DIR__.'/../../lib/AI/Service/State.php';
require __DIR__.'/../../lib/AI/Service/Metadata.php';
class ActivityConfig implements \OCP\IConfig {
 public function __construct(public string $path){}
 public function getSystemValueString($key){return $this->path;}
 public function getAppValue($app,$key,$fallback=''){return $fallback;}
}
$checks=0;function activityCheck($value,$message){global $checks;$checks++;if(!$value)throw new \RuntimeException($message);}
$path=sys_get_temp_dir().'/nextgallery-activity-'.bin2hex(random_bytes(8));mkdir($path,0700);
try{
 $state=new \OCA\Nextgallery\AI\Service\State(new ActivityConfig($path));$dir=$state->directory().'/logs';mkdir($dir,0700);$file=$dir.'/2026-10-13.jsonl';$wire='';$expected=[];
 for($i=0;$i<6000;$i++){$error=$i%19===0;$kind=$i%3===0?'ocr':'embeddings';$row=['event'=>$error?'file_error':'file_finished','level'=>$error?'error':'info','kind'=>$kind,'file_id'=>$i,'path'=>'Synthetic/'.str_repeat('a',100).'.png','error'=>$error?'Synthetic HTTP 503':'' ];$wire.=json_encode($row)."\n";if($error&&$kind==='ocr')$expected[]=$i;}
 file_put_contents($file,$wire);$cursor=0;$ids=[];$iterations=0;
 do{$result=$state->logs('2026-10-13',$cursor,'error','ocr');foreach($result['entries'] as $row){activityCheck($row['event']==='file_error'&&$row['kind']==='ocr','Unfiltered row');$ids[]=$row['file_id'];}$cursor=$result['cursor'];activityCheck(++$iterations<20,'Pagination loop');}while($cursor!==null);
 activityCheck($ids===array_reverse($expected),'Older errors lost or duplicated across chunks');
 $cursor=0;$ids=[];$iterations=0;
 do{$result=$state->logs('2026-10-13',$cursor);foreach($result['entries'] as $row)$ids[]=$row['file_id'];$cursor=$result['cursor'];activityCheck(++$iterations<50,'All-event pagination loop');}while($cursor!==null);
 activityCheck($ids===array_reverse(range(0,5999)),'All-event pagination lost or repeated records');
 file_put_contents($file,json_encode(['event'=>'embedding_error','kind'=>'embeddings','error'=>'Older worker error'])."\n".json_encode(['event'=>'file_deferred','kind'=>'ocr','reason'=>'Service offline'])."\n");
 activityCheck(count($state->logs('2026-10-13',0,'error')['entries'])===2,'Legacy and deferred errors missing');
 activityCheck($state->logs('2026-10-12')['cursor']===null,'Missing day cursor');
 foreach([['bad',0,'all','all'],['2026-10-13',-1,'all','all'],['2026-10-13',0,'oops','all'],['2026-10-13',0,'all','oops']] as $args){try{$state->logs(...$args);throw new \RuntimeException('Invalid filter accepted');}catch(\OCP\AppFramework\OCS\OCSBadRequestException){$checks++;}}
 class CountResult {public int $index=0;public bool $closed=false;public function __construct(public array $rows){foreach($this->rows as $i=>&$row)$row['file_id']??=$i+1;} public function fetchAll(){return array_slice($this->rows,$this->index);} public function fetch(){return $this->rows[$this->index++]??false;}public function closeCursor(){$this->closed=true;}}
 class CountDB implements \OCP\IDBConnection {public int $calls=0;public CountResult $result;public function executeQuery($sql,$args){$this->calls++;$this->result=new CountResult([['etag'=>'a','metadata_json'=>json_encode(['analysis'=>['vision'=>['description'=>'synthetic'],'ocr'=>['text'=>'']]])],['etag'=>'b','metadata_json'=>json_encode(['analysis'=>['ocr'=>['text'=>'text'],'completed_stages'=>['ocr'=>true]]])],['etag'=>'c','metadata_json'=>json_encode(['analysis'=>['ocr'=>['text'=>'','source'=>'skipped_video']]])]]);return $this->result;}}
 $db=new CountDB();$meta=new \OCA\Nextgallery\AI\Service\Metadata($db);activityCheck($meta->counts('synthetic')===['metadata'=>3,'descriptions'=>1,'ocr'=>2,'audio'=>0],'Independent stored counts incorrect');activityCheck($db->calls===1&&$db->result->closed,'Counts need multiple passes or leak cursor');
 echo json_encode(['checks'=>$checks,'synthetic_only'=>true])."\n";
}finally{$it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $item)$item->isDir()?rmdir($item->getPathname()):unlink($item->getPathname());rmdir($path);}
}
