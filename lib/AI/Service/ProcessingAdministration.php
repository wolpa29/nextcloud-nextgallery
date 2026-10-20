<?php
declare(strict_types=1);
namespace OCA\Nextgallery\AI\Service;
use OCP\AppFramework\OCS\OCSBadRequestException;

/** Central run suspension and an explicitly confirmed, quiescent model transition. */
class ProcessingAdministration {
 public function __construct(private Settings $settings,private State $state,private Embeddings $vectors,private EmbeddingClient $client,private ?SharedResults $shared=null){}
 private function ids():array {return array_values(array_unique(array_merge($this->state->accountIds(),$this->vectors->accounts())));}
 private function cache(string $uid,bool $readOnly=false):?\SQLite3 {
  $file=$this->state->forUser($uid)->directory().'/embeddings.sqlite3';
  if(!is_file($file))return null;
  if(is_link($file))throw new OCSBadRequestException('Embedding-Speicher ist nicht verfügbar.');
  $db=new \SQLite3($file,$readOnly?SQLITE3_OPEN_READONLY:SQLITE3_OPEN_READWRITE);$db->enableExceptions(true);$db->busyTimeout(1000);return $db;
 }
 public function overview():array {
  $pipelines=[];
  foreach(State::PIPELINES as $kind){
   $rows=[];foreach($this->ids() as $uid){$s=$this->state->forUser($uid)->forPipeline($kind)->status();$rows[]=['user_id'=>$uid,'state'=>$s['state'],'requested'=>!empty($s['control']['requested']),'paused'=>!empty($s['control']['paused']),'kind'=>$kind];}
   $pipelines[$kind]=['paused'=>$this->state->processingPaused($kind),'running'=>$this->state->anyRunning($kind),'accounts'=>$rows];
  }
  return $pipelines['embeddings']+['pipelines'=>$pipelines];
 }
 public function pauseAll(string $kind='embeddings'):array {
  $lane=$this->state->forPipeline($kind);
  $lane->setProcessingPaused(true);
  foreach($this->ids() as $uid)$lane->forUser($uid)->setControl('pause');
  return $this->overview();
 }
 public function allowRuns(string $kind='embeddings'):array {
  $lane=$this->state->forPipeline($kind);$lock=$lane->globalLock(true);
  try{$lane->setProcessingPaused(false);return $this->overview();}
  finally{State::releaseLock($lock);}
 }
 /** Embedding-only settings do not need the description gate (and vice versa). */
 private static function canonical(mixed $value):mixed {
  if(is_int($value))return (float)$value; // JSON may reload 0.0 as 0.
  if(!is_array($value))return $value;
  if(!array_is_list($value))ksort($value);
  foreach($value as $key=>$item)$value[$key]=self::canonical($item);
  return $value;
 }
 public static function changedPipelines(array $previous,array $candidate,array $submitted=[]):array {
  $kinds=[];
  foreach(array_unique(array_merge(array_keys($previous),array_keys($candidate))) as $key){
   if(self::canonical($previous[$key]??null)===self::canonical($candidate[$key]??null))continue;
   $lanes=in_array($key,['faces','faces_filters'],true)?['faces']:($key==='embedding'?['embeddings']:(in_array($key,['ocr','ocr_enabled'],true)?['ocr']:(in_array($key,['analysis_enabled','vision','video'],true)?['descriptions']:State::PIPELINES)));
   $kinds=array_merge($kinds,$lanes);
  }
  foreach(['vision','ocr','embedding'] as $key)if(!empty($submitted[$key]['api_key'])||!empty($submitted[$key]['clear_api_key']))$kinds[]=$key==='embedding'?'embeddings':($key==='ocr'?'ocr':'descriptions');
  return array_values(array_intersect(State::PIPELINES,array_unique($kinds)));
 }
 private function candidate(array $config):array {return $this->settings->save($config,null,false);}
 private function compatible(array $submitted):array {
  $config=$this->settings->probeEmbedding($submitted);
  try{
   $info=$this->client->info($config);$space=EmbeddingClient::space($info,$config);
  }catch(\Throwable $error){return ['ready'=>false,'compatible'=>false,'compatibility'=>'unverified','reason'=>$error->getMessage()];}
  $result=['ready'=>true,'location'=>$config['location'],'model'=>$info['model'],'space'=>$space,'dimensions'=>$info['dimensions'],'video'=>'sampled_frames'];
  try{
   foreach($this->vectors->spaces() as $row)if($row['space']!==$space||(int)$row['dimensions']!==$info['dimensions'])return $result+['compatible'=>false,'compatibility'=>'different','reason'=>'Der Dienst ist erreichbar, liefert aber einen anderen Vektorraum. Modellstand, Laufzeit und Bildverarbeitung prüfen.'];
   foreach($this->ids() as $uid){$db=$this->cache($uid,true);if(!$db)continue;try{$rows=$db->query('SELECT DISTINCT space FROM embeddings');while($row=$rows->fetchArray(SQLITE3_ASSOC))if($row['space']!==$space)return $result+['compatible'=>false,'compatibility'=>'different','reason'=>'Der lokale Verarbeitungsspeicher enthält andere Bildvektoren.'];}finally{$db->close();}}
   return $result+['compatible'=>true,'compatibility'=>'matching','reason'=>'Gleicher Vektorraum nachgewiesen. Vorhandene Vektoren können weiterverwendet werden.'];
  }catch(\Throwable){return $result+['compatible'=>false,'compatibility'=>'unverified','reason'=>'Gespeicherte Vektorräume konnten nicht geprüft werden. Es wurden keine Vektoren gelöscht.'];}
 }
 public function inspect(array $config):array {
  $result=$this->compatible($config);
  if(empty($result['ready']))$result['message']=$result['reason'];
  return $result;
 }
 private function changeId():string {
  $counts=[];foreach($this->ids() as $uid){$n=0;$db=$this->cache($uid);if($db){try{$n=$db->querySingle('SELECT COUNT(*) FROM embeddings');}finally{$db->close();}}$counts[$uid]=[$this->vectors->count($uid),$n];}ksort($counts);
  return hash('sha256',Settings::embeddingSignature($this->settings->get()).json_encode($counts,JSON_THROW_ON_ERROR));
 }
 public function preview(array $config):array {
  $match=$this->compatible($config);$files=0;foreach($this->vectors->accounts() as $uid)$files+=$this->vectors->count($uid);
  return ['config_id'=>$this->changeId(),'files'=>$files,'accounts'=>count($this->vectors->accounts())]+$match;
 }
 public function save(array $config,string $confirmation,string $mode,bool $rebuild):array {
  $settingsLock=fopen($this->state->rootDirectory().'/settings.lock','c');
  if(!$settingsLock||!flock($settingsLock,LOCK_EX))throw new \RuntimeException('Einstellungen sind gesperrt');
  $locks=[];
  try{
   $previous=$this->settings->get();$candidate=$this->candidate($config);
   $kinds=self::changedPipelines($previous,$candidate,$config);
   foreach($kinds as $kind){$locks[]=$this->state->globalLock(true,$kind);if($this->state->anyRunning($kind))throw new OCSBadRequestException('Bitte die betroffene Verarbeitung pausieren und das Ende der aktuellen Datei abwarten.');}
   if(!in_array('embeddings',$kinds,true)){$this->settings->save($config);return ['changed'=>false,'mode'=>$mode,'rebuild_queued'=>0];}
   foreach($this->ids() as $uid)if(!empty($this->state->forUser($uid)->forPipeline('embeddings')->control()['reset_requested']))throw new OCSBadRequestException('Bitte zuerst die angeforderten Datenlöschungen abschließen lassen.');
   $changed=Settings::embeddingSignature($previous)!==Settings::embeddingSignature($candidate);
   $affected=$this->vectors->accounts();$cached=false;
   foreach($this->ids() as $uid){$db=$this->cache($uid);if(!$db)continue;try{if($db->querySingle('SELECT COUNT(*) FROM embeddings')>0){$cached=true;$affected[]=$uid;}}finally{$db->close();}}
   $affected=array_values(array_unique($affected));
   if($changed&&($affected||$cached)){
    if(!hash_equals($this->changeId(),$confirmation)||!in_array($mode,['reset','keep'],true))throw new OCSBadRequestException('Modellwechsel bitte erneut bestätigen.');
    $match=$mode==='keep'?$this->compatible($config):[];
    if($mode==='keep'&&empty($match['compatible']))throw new OCSBadRequestException($match['reason']);
    // No worker or HTTP start can pass the global gate during cache/DB/config changes.
    if($mode==='reset'){
     $wasPaused=$this->state->processingPaused('embeddings');$this->state->setProcessingPaused(true,'embeddings');
     foreach($this->ids() as $uid){$db=$this->cache($uid);if(!$db)continue;try{$db->exec('BEGIN IMMEDIATE');$db->exec('DELETE FROM embeddings');$db->exec('COMMIT');}finally{$db->close();}}
     $this->vectors->resetAll();$this->shared?->forgetKind('embedding');
    }
    $this->settings->save($config);
    foreach($this->ids() as $uid){
     $own=$this->state->forUser($uid)->forPipeline('embeddings');$own->atomic('embedding-rebuild.json',$mode==='keep'?['required'=>false,'approved'=>true,'signature'=>Settings::embeddingSignature($this->settings->forUser($uid)->get()),'approved_space'=>$match['space']]:[]);
     if($mode==='reset'){$c=$own->control();$c['paused']=true;$c['requested']=false;unset($c['retry_errors']);$own->atomic('control.json',$c);$s=$own->status();$s['stats']=$s['embedding_stats']=['done'=>0,'pending'=>0,'error'=>0];$s['last_run']=null;$s['current']=null;$s['state']='paused';unset($s['embedding_space']);$own->atomic('status.json',$s);$own->atomic('embedding-cache-reset.json',['revision'=>bin2hex(random_bytes(12))]);}
     if($mode==='reset'&&in_array($uid,$affected,true)&&$rebuild&&$candidate['embedding']['enabled']){
      $own->registerAccount();$control=$own->control();$control['paused']=false;$control['requested']=true;$control['run_kind']='embeddings';$control['request_id']=bin2hex(random_bytes(12));$control['updated_at']=time();unset($control['retry_errors']);$own->atomic('control.json',$control);
     }
    }
    if($mode==='reset')$this->state->setProcessingPaused($rebuild&&$candidate['embedding']['enabled']?false:$wasPaused,'embeddings');
   }else{$this->settings->save($config);}
   return ['changed'=>$changed,'mode'=>$mode,'rebuild_queued'=>$changed&&$mode==='reset'&&$rebuild&&$candidate['embedding']['enabled']?count($affected):0];
  }finally{State::releaseLock($locks);flock($settingsLock,LOCK_UN);fclose($settingsLock);}
 }
}
