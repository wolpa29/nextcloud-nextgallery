<?php
declare(strict_types=1);
namespace OCA\Nextgallery\AI\Service;
use OCP\AppFramework\OCS\OCSBadRequestException;

/** Central run suspension and an explicitly confirmed, quiescent model transition. */
class ProcessingAdministration {
 public function __construct(private Settings $settings,private State $state,private Embeddings $vectors,private EmbeddingClient $client){}
 private function ids():array {return array_values(array_unique(array_merge($this->state->accountIds(),$this->vectors->accounts())));}
 private function cache(string $uid):?\SQLite3 {
  $file=$this->state->forUser($uid)->directory().'/embeddings.sqlite3';
  if(!is_file($file))return null;
  if(is_link($file))throw new OCSBadRequestException('Embedding-Speicher ist nicht verfügbar.');
  $db=new \SQLite3($file);$db->enableExceptions(true);$db->busyTimeout(1000);return $db;
 }
 public function overview():array {
  $rows=[];
  foreach($this->ids() as $uid){$s=$this->state->forUser($uid)->status();$rows[]=['user_id'=>$uid,'state'=>$s['state'],'requested'=>!empty($s['control']['requested']),'paused'=>!empty($s['control']['paused']),'kind'=>$s['control']['run_kind']??'both'];}
  return ['paused'=>$this->state->processingPaused(),'running'=>$this->state->anyRunning(),'accounts'=>$rows];
 }
 public function pauseAll():array {
  // Stop flags can be written while the worker holds its shared processing lock.
  $this->state->setProcessingPaused(true);
  foreach($this->ids() as $uid)$this->state->forUser($uid)->setControl('pause');
  return $this->overview();
 }
 public function allowRuns():array {
  $lock=$this->state->globalLock(true);
  try{$this->state->setProcessingPaused(false);return $this->overview();}
  finally{flock($lock,LOCK_UN);fclose($lock);}
 }
 private function candidate(array $config):array {return $this->settings->save($config,null,false);}
 private function compatible(array $candidate,array $submitted):array {
  $config=$candidate['embedding'];$config['api_key']=$this->settings->workerConfig()['embedding']['api_key']??'';
  if(!empty($submitted['embedding']['clear_api_key']))$config['api_key']='';
  if(($submitted['embedding']['api_key']??'')!=='')$config['api_key']=$submitted['embedding']['api_key'];
  try{
   $info=$this->client->info($config);$space=EmbeddingClient::space($info,$config);
   foreach($this->vectors->spaces() as $row)if($row['space']!==$space||(int)$row['dimensions']!==$info['dimensions'])return ['compatible'=>false,'reason'=>'Das neue Modell benötigt andere Bildvektoren.'];
   foreach($this->ids() as $uid){$db=$this->cache($uid);if(!$db)continue;try{$rows=$db->query('SELECT DISTINCT space FROM embeddings');while($row=$rows->fetchArray(SQLITE3_ASSOC))if($row['space']!==$space)return ['compatible'=>false,'reason'=>'Der lokale Verarbeitungsspeicher enthält andere Bildvektoren.'];}finally{$db->close();}}
   return ['compatible'=>true,'space'=>$space,'dimensions'=>$info['dimensions']];
  }catch(\Throwable){return ['compatible'=>false,'reason'=>'Der neue Dienst muss erreichbar sein und denselben Vektorraum nachweisen.'];}
 }
 private function changeId():string {
  $counts=[];foreach($this->ids() as $uid){$n=0;$db=$this->cache($uid);if($db){try{$n=$db->querySingle('SELECT COUNT(*) FROM embeddings');}finally{$db->close();}}$counts[$uid]=[$this->vectors->count($uid),$n];}ksort($counts);
  return hash('sha256',Settings::embeddingSignature($this->settings->get()).json_encode($counts,JSON_THROW_ON_ERROR));
 }
 public function preview(array $config):array {
  $candidate=$this->candidate($config);$files=0;foreach($this->vectors->accounts() as $uid)$files+=$this->vectors->count($uid);
  return ['config_id'=>$this->changeId(),'files'=>$files,'accounts'=>count($this->vectors->accounts())]+$this->compatible($candidate,$config);
 }
 public function save(array $config,string $confirmation,string $mode,bool $rebuild):array {
  $lock=$this->state->globalLock(true);
  try{
   if($this->state->anyRunning())throw new OCSBadRequestException('Bitte alle Läufe pausieren und das Ende der aktuellen Datei abwarten.');
   $previous=$this->settings->get();$candidate=$this->candidate($config);
   foreach($this->ids() as $uid)if(!empty($this->state->forUser($uid)->control()['reset_requested']))throw new OCSBadRequestException('Bitte zuerst die angeforderten Datenlöschungen abschließen lassen.');
   $changed=Settings::embeddingSignature($previous)!==Settings::embeddingSignature($candidate);
   $affected=$this->vectors->accounts();$cached=false;
   foreach($this->ids() as $uid){$db=$this->cache($uid);if(!$db)continue;try{if($db->querySingle('SELECT COUNT(*) FROM embeddings')>0){$cached=true;$affected[]=$uid;}}finally{$db->close();}}
   $affected=array_values(array_unique($affected));
   if($changed&&($affected||$cached)){
    if(!hash_equals($this->changeId(),$confirmation)||!in_array($mode,['reset','keep'],true))throw new OCSBadRequestException('Modellwechsel bitte erneut bestätigen.');
    $match=$mode==='keep'?$this->compatible($candidate,$config):[];
    if($mode==='keep'&&empty($match['compatible']))throw new OCSBadRequestException($match['reason']);
    // No worker or HTTP start can pass the global gate during cache/DB/config changes.
    if($mode==='reset'){
     $wasPaused=$this->state->processingPaused();$this->state->setProcessingPaused(true);
     foreach($this->ids() as $uid){$db=$this->cache($uid);if(!$db)continue;try{$db->exec('BEGIN IMMEDIATE');$db->exec('DELETE FROM embeddings');$db->exec('COMMIT');}finally{$db->close();}}
     $this->vectors->resetAll();
    }
    $this->settings->save($config);
    foreach($this->ids() as $uid){
     $own=$this->state->forUser($uid);$own->atomic('embedding-rebuild.json',$mode==='keep'?['required'=>false,'approved'=>true,'signature'=>Settings::embeddingSignature($this->settings->forUser($uid)->get()),'approved_space'=>$match['space']]:[]);
     if($mode==='reset'){$c=$own->control();$c['paused']=true;$c['requested']=false;unset($c['retry_errors']);$own->atomic('control.json',$c);$s=$own->status();$s['embedding_stats']=['done'=>0,'pending'=>0,'error'=>0];unset($s['embedding_space']);$own->atomic('status.json',$s);}
     if($mode==='reset'&&in_array($uid,$affected,true)&&$rebuild&&$candidate['embedding']['enabled']){
      $own->registerAccount();$control=$own->control();$control['paused']=false;$control['requested']=true;$control['run_kind']='embeddings';$control['request_id']=bin2hex(random_bytes(12));$control['updated_at']=time();unset($control['retry_errors']);$own->atomic('control.json',$control);
     }
    }
    if($mode==='reset')$this->state->setProcessingPaused($rebuild&&$candidate['embedding']['enabled']?false:$wasPaused);
   }else{$this->settings->save($config);}
   return ['changed'=>$changed,'mode'=>$mode,'rebuild_queued'=>$changed&&$mode==='reset'&&$rebuild&&$candidate['embedding']['enabled']?count($affected):0];
  }finally{flock($lock,LOCK_UN);fclose($lock);}
 }
}
