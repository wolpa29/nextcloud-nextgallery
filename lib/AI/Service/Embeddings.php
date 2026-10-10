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
 public function __construct(private IDBConnection $db){}
 public static function normalize(array $values):array {
  if(!array_is_list($values) || count($values)<128 || count($values)>4096)throw new OCSBadRequestException('Ungültiger Vektor');
  $norm=0.;foreach($values as $v){if((!is_float($v)&&!is_int($v))||!is_finite((float)$v))throw new OCSBadRequestException('Ungültiger Vektor');$norm+=$v*$v;}
  if($norm<1e-16 || !is_finite($norm))throw new OCSBadRequestException('Ungültiger Vektor');$norm=sqrt($norm);return array_map(fn($v)=>$v/$norm,$values);
 }
 public function publish(string $uid,int $fileId,string $etag,string $space,array $vectors):void {
  if($fileId<1 || strlen($etag)<1 || strlen($etag)>255 || !preg_match('/^[a-f0-9]{64}$/D',$space) || !array_is_list($vectors) || count($vectors)<1 || count($vectors)>20)throw new OCSBadRequestException('Ungültige Embeddings');
  $values=[];$dimensions=null;
  foreach($vectors as $i=>$v){
   if(!is_array($v)||($v['frame']??null)!==$i || !is_numeric($v['seconds']??null) || !is_finite((float)$v['seconds']) || $v['seconds']<0)throw new OCSBadRequestException('Ungültiger Video-Frame');
   if(!is_array($v['values']??null))throw new OCSBadRequestException('Ungültiger Vektor');
   $vector=self::normalize($v['values']);$dimensions??=count($vector);if(count($vector)!==$dimensions)throw new OCSBadRequestException('Unterschiedliche Vektordimensionen');
   $values[]=['user_id'=>$uid,'file_id'=>$fileId,'etag'=>$etag,'space'=>$space,'frame'=>$i,'sample_seconds'=>(float)$v['seconds'],'dimensions'=>$dimensions,'vector_data'=>base64_encode(pack('g*',...$vector)),'updated_at'=>time()];
  }
  $this->db->beginTransaction();
  try{
   // Replace a file's old spaces only inside the successful publish transaction.
   $this->db->executeStatement('DELETE FROM *PREFIX*nextgallery_ai_vectors WHERE user_id=? AND file_id=?',[$uid,$fileId]);
   foreach($values as $row)$this->db->executeStatement('INSERT INTO *PREFIX*nextgallery_ai_vectors ('.implode(',',array_keys($row)).') VALUES ('.implode(',',array_fill(0,count($row),'?')).')',array_values($row));
   $this->db->commit();
  }catch(\Throwable $e){$this->db->rollBack();throw $e;}
 }
 public function reset(string $uid):void {$this->db->executeStatement('DELETE FROM *PREFIX*nextgallery_ai_vectors WHERE user_id=?',[$uid]);}
 public function count(string $uid):int {
  $r=$this->db->executeQuery('SELECT COUNT(DISTINCT file_id) FROM *PREFIX*nextgallery_ai_vectors WHERE user_id=?',[$uid]);try{return (int)$r->fetchOne();}finally{$r->closeCursor();}
 }
}
