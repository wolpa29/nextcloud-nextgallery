<?php
declare(strict_types=1);
namespace OCA\Nextgallery\AI\Service;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\AppFramework\OCS\OCSBadRequestException;

/**
 * Stage results by content, shared between accounts that can read identical bytes.
 * Keys are sha256 + stage + model revision (or embedding space). Descriptions and OCR
 * may be adopted from another revision, like an account's own finished results that
 * survive a model or prompt change; vectors only match within one embedding space.
 * Callers prove file access first; rows carry no paths, file IDs or account provenance.
 */
class SharedResults {
 public const KINDS=['vision','ocr','embedding'];
 /** Pipeline (reset) names to stored stage names. */
 public const PIPELINES=['descriptions'=>'vision','ocr'=>'ocr','embeddings'=>'embedding'];
 public function __construct(private IDBConnection $db,private IConfig $config){}
 public function enabled():bool {return $this->config->getAppValue('nextgallery','ai_share_results','yes')!=='no';}
 public static function key(string $sha256,string $kind,string $revision):void {
  if(!preg_match('/^[a-f0-9]{64}$/D',$sha256)||!preg_match('/^[a-f0-9]{64}$/D',$revision)||!in_array($kind,self::KINDS,true))throw new OCSBadRequestException('Ungültiger Ergebnisschlüssel');
 }
 /** Same bounds as a published stage; returns the stored JSON. */
 public static function validate(string $kind,mixed $result):string {
  if(!is_array($result))throw new OCSBadRequestException('Ungültiges Ergebnis');
  // Model output only: anything beyond the stage schema (e.g. provenance) stays private.
  if($kind==='embedding')Embeddings::frames($result);
  elseif($kind==='ocr'){if(array_diff(array_keys($result),['text','source'])||!is_string($result['text']??null)||strlen($result['text'])>500000||!is_string($result['source']??'')||strlen($result['source']??'')>64)throw new OCSBadRequestException('Ungültiges OCR-Ergebnis');}
  else{
   $keys=array_keys($result);sort($keys);
   if($keys!==['description','objects','tags','type','uncertain_details'])throw new OCSBadRequestException('Ungültige Bildbeschreibung');
   Metadata::validate(1,'shared',str_repeat('0',64),str_repeat('0',64),['vision'=>$result,'ocr'=>['text'=>'']],[],'descriptions');
  }
  $json=json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION);
  if(strlen($json)>1048576)throw new OCSBadRequestException('Ergebnis zu groß');
  return $json;
 }
 /** Public model name only (no URLs or keys); null when unknown. */
 public static function model(mixed $model):?string {
  if($model===null)return null;
  if(!is_array($model)||array_diff(array_keys($model),['id','weights']))throw new OCSBadRequestException('Ungültige Modellangabe');
  foreach($model as $value)if($value!==null&&(!is_string($value)||strlen($value)>200))throw new OCSBadRequestException('Ungültige Modellangabe');
  return json_encode($model,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
 }
 /** Exact revision first; with $anyRevision the newest valid result of another revision. */
 public function find(string $sha256,string $kind,string $revision,bool $anyRevision=false):?array {
  self::key($sha256,$kind,$revision);
  $any=$anyRevision&&$kind!=='embedding';
  $r=$this->db->executeQuery('SELECT result,revision,model FROM *PREFIX*nextgallery_ai_shared WHERE sha256=? AND kind=?'.($any?'':' AND revision=?').' ORDER BY CASE WHEN revision=? THEN 0 ELSE 1 END,created_at DESC,id DESC LIMIT 20',
   $any?[$sha256,$kind,$revision]:[$sha256,$kind,$revision,$revision]);
  try{$rows=$r->fetchAll();}finally{$r->closeCursor();}
  foreach($rows as $row){
   $result=json_decode((string)$row['result'],true);
   try{self::validate($kind,$result);}catch(OCSBadRequestException){continue;}
   $model=is_string($row['model']??null)?json_decode($row['model'],true):null;
   return ['result'=>$result,'revision'=>$row['revision'],'model'=>is_array($model)?$model:null];
  }
  return null;
 }
 /** First contributor wins; identical keys describe identical bytes and settings. */
 public function store(string $uid,string $sha256,string $kind,string $revision,mixed $result,mixed $model=null):bool {
  self::key($sha256,$kind,$revision);$json=self::validate($kind,$result);$model=self::model($model);
  return $this->db->insertIgnoreConflict('nextgallery_ai_shared',['sha256'=>$sha256,'kind'=>$kind,'revision'=>$revision,'user_id'=>$uid,'result'=>$json,'model'=>$model,'created_at'=>time()])>0;
 }
 /** An explicit reset or account deletion also withdraws that account's contributions. */
 public function forget(string $uid,?string $pipeline=null):void {
  if($pipeline===null||$pipeline==='all'){$this->db->executeStatement('DELETE FROM *PREFIX*nextgallery_ai_shared WHERE user_id=?',[$uid]);return;}
  $this->db->executeStatement('DELETE FROM *PREFIX*nextgallery_ai_shared WHERE user_id=? AND kind=?',[$uid,self::PIPELINES[$pipeline]??throw new OCSBadRequestException('Ungültige Verarbeitung')]);
 }
 public function forgetKind(string $kind):void {
  if(!in_array($kind,self::KINDS,true))throw new OCSBadRequestException('Ungültige Verarbeitung');
  $this->db->executeStatement('DELETE FROM *PREFIX*nextgallery_ai_shared WHERE kind=?',[$kind]);
 }
}
