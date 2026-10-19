<?php
declare(strict_types=1);
namespace OCA\Nextgallery\AI\Service;
use OCP\IDBConnection;
use OCP\AppFramework\OCS\OCSBadRequestException;
class Embeddings {
 public function accounts():array {$q=$this->db->getQueryBuilder();$q->select('user_id')->from('nextgallery_ai_vectors')->groupBy('user_id');$result=$q->executeQuery();try{return array_column($result->fetchAll(),'user_id');}finally{$result->closeCursor();}}
 public function statuses(string $uid,array $ids):array {
  if(!$ids)return [];$q=$this->db->getQueryBuilder();$q->select('v.file_id','v.etag')->from('nextgallery_ai_vectors','v')->innerJoin('v','filecache','f','f.fileid=v.file_id AND f.etag=v.etag')->where($q->expr()->eq('v.user_id',$q->createNamedParameter($uid)))->andWhere($q->expr()->in('v.file_id',$q->createNamedParameter($ids,\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
  $rows=$q->executeQuery();$result=[];try{while($row=$rows->fetch())$result[(int)$row['file_id']]=$row['etag'];}finally{$rows->closeCursor();}return $result;
 }
 /** Current published vectors, grouped by file; stale etags and other users are excluded. */
 public function processingStatuses(string $uid,array $ids,?string $space=null):array {
  $result=[];
  foreach(array_chunk($ids,500) as $chunk){
   $q=$this->db->getQueryBuilder();$q->selectDistinct('v.file_id')->from('nextgallery_ai_vectors','v')->innerJoin('v','filecache','f','f.fileid=v.file_id AND f.etag=v.etag')->where($q->expr()->eq('v.user_id',$q->createNamedParameter($uid)))->andWhere($q->expr()->in('v.file_id',$q->createNamedParameter($chunk,\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
   if($space!==null)$q->andWhere($q->expr()->eq('v.space',$q->createNamedParameter($space)));
   $rows=$q->executeQuery();try{while($row=$rows->fetch())$result[(int)$row['file_id']]=true;}finally{$rows->closeCursor();}
  }
  return $result;
 }
 public function __construct(private IDBConnection $db,private ?\OCA\Nextgallery\Service\SearchIndexChanges $searchChanges=null){}
 public static function normalize(array $values):array {
  if(!array_is_list($values) || count($values)<128 || count($values)>4096)throw new OCSBadRequestException('Ungültiger Vektor');
  $norm=0.;foreach($values as $v){if((!is_float($v)&&!is_int($v))||!is_finite((float)$v))throw new OCSBadRequestException('Ungültiger Vektor');$norm+=$v*$v;}
  if($norm<1e-16 || !is_finite($norm))throw new OCSBadRequestException('Ungültiger Vektor');$norm=sqrt($norm);return array_map(fn($v)=>$v/$norm,$values);
 }
 /** Validated, normalized frames of one file: [[frame, seconds, vector], ...]. */
 public static function frames(array $vectors):array {
  if(!array_is_list($vectors) || count($vectors)<1 || count($vectors)>20)throw new OCSBadRequestException('Ungültige Embeddings');
  $frames=[];$dimensions=null;
  foreach($vectors as $i=>$v){
   if(!is_array($v)||($v['frame']??null)!==$i || !is_numeric($v['seconds']??null) || !is_finite((float)$v['seconds']) || $v['seconds']<0)throw new OCSBadRequestException('Ungültiger Video-Frame');
   if(!is_array($v['values']??null))throw new OCSBadRequestException('Ungültiger Vektor');
   $vector=self::normalize($v['values']);$dimensions??=count($vector);if(count($vector)!==$dimensions)throw new OCSBadRequestException('Unterschiedliche Vektordimensionen');
   $frames[]=[$i,(float)$v['seconds'],$vector];
  }
  return $frames;
 }
 public function publish(string $uid,int $fileId,string $etag,string $space,array $vectors):void {
  if($fileId<1 || strlen($etag)<1 || strlen($etag)>255 || !preg_match('/^[a-f0-9]{64}$/D',$space))throw new OCSBadRequestException('Ungültige Embeddings');
  $values=[];
  foreach(self::frames($vectors) as [$i,$seconds,$vector])$values[]=['user_id'=>$uid,'file_id'=>$fileId,'etag'=>$etag,'space'=>$space,'frame'=>$i,'sample_seconds'=>$seconds,'dimensions'=>count($vector),'vector_data'=>base64_encode(pack('g*',...$vector)),'updated_at'=>time()];
  $this->db->beginTransaction();
  try{
   // Replace a file's old spaces only inside the successful publish transaction.
   $this->db->executeStatement('DELETE FROM *PREFIX*nextgallery_ai_vectors WHERE user_id=? AND file_id=?',[$uid,$fileId]);
   foreach($values as $row)$this->db->executeStatement('INSERT INTO *PREFIX*nextgallery_ai_vectors ('.implode(',',array_keys($row)).') VALUES ('.implode(',',array_fill(0,count($row),'?')).')',array_values($row));
   $this->db->commit();
  }catch(\Throwable $e){$this->db->rollBack();throw $e;}
  $this->searchChanges?->file($uid,$fileId);
 }
 /** Vectors another account published for this very file version, in the same space. */
 public function sameFile(int $fileId,string $etag,string $space,string $exceptUid):?array {
  $r=$this->db->executeQuery('SELECT user_id,frame,sample_seconds,vector_data FROM *PREFIX*nextgallery_ai_vectors WHERE file_id=? AND etag=? AND space=? AND user_id<>? ORDER BY user_id,frame',[$fileId,$etag,$space,$exceptUid]);
  try{$rows=$r->fetchAll();}finally{$r->closeCursor();}
  $owner=$rows[0]['user_id']??null;$frames=[];
  foreach($rows as $row){
   if($row['user_id']!==$owner)break;
   $values=unpack('g*',base64_decode((string)$row['vector_data'],true)?:'');
   if(!$values||(int)$row['frame']!==count($frames))return null;
   $frames[]=['frame'=>(int)$row['frame'],'seconds'=>(float)$row['sample_seconds'],'values'=>array_values($values)];
  }
  return $frames?:null;
 }
 public function reset(string $uid):void {$this->db->executeStatement('DELETE FROM *PREFIX*nextgallery_ai_vectors WHERE user_id=?',[$uid]);$this->searchChanges?->account($uid);}
 public function resetAll():void {$this->db->executeStatement('DELETE FROM *PREFIX*nextgallery_ai_vectors');$this->searchChanges?->catalog();}
 public function spaces():array {
  $result=$this->db->executeQuery('SELECT DISTINCT space,dimensions FROM *PREFIX*nextgallery_ai_vectors');
  try{return $result->fetchAll();}finally{$result->closeCursor();}
 }
 public function count(string $uid,?string $space=null):int {
  $sql='SELECT COUNT(DISTINCT file_id) FROM *PREFIX*nextgallery_ai_vectors WHERE user_id=?';$args=[$uid];
  if($space!==null){$sql.=' AND space=?';$args[]=$space;}
  $r=$this->db->executeQuery($sql,$args);try{return (int)$r->fetchOne();}finally{$r->closeCursor();}
 }
}
