<?php
declare(strict_types=1);
namespace OCA\Chronicle\Service;
use OCP\IDBConnection;

/** One gallery scope for filenames and fresh AI text. Dense retrieval can extend this service. */
class GallerySearch {
 public function __construct(private IDBConnection $db,private Library $library) {}
 public function search(string $uid,string $term,string $kind='',int $offset=0,int $limit=60):array {
  if (mb_strlen($term)>200 || $offset<0) throw new \InvalidArgumentException('Suchanfrage oder Seitenauswahl ungültig.');
  preg_match_all('/[\p{L}\p{N}_]+/u',mb_strtolower($term),$matches);
  $words=array_slice(array_values(array_unique($matches[0])),0,16);
  if (!$words) return ['items'=>[],'next'=>null,'more'=>false,'mode'=>'lexical'];
  $limit=max(1,min(200,$limit));$q=$this->db->getQueryBuilder();
  $q->select('c.file_id','c.payload')->from('chronicle_media','c')
   ->leftJoin('c','media_ai_meta','a','a.user_id=c.user_id AND a.file_id=c.file_id AND a.etag=c.etag')
   ->where($q->expr()->eq('c.user_id',$q->createNamedParameter($uid)));
  if (in_array($kind,['image','video'],true)) $q->andWhere($q->expr()->eq('c.kind',$q->createNamedParameter($kind)));
  $scores=[];$conditions=[];
  foreach ($words as $word) {
   $escaped=str_replace(['!','%','_'],['!!','!%','!_'],$word);
   foreach (['c.name_text'=>8,'c.path_text'=>2,'a.tags_text'=>3,'a.objects_text'=>3,'a.description'=>1,'a.ocr_text'=>2] as $column=>$weight) {
    $match="$column LIKE ".$q->createNamedParameter('%'.$escaped.'%')." ESCAPE '!'";
    $conditions[]=$match;$scores[]="CASE WHEN $match THEN $weight ELSE 0 END";
   }
  }
  // OR expands recall. Exact filename/text terms contribute to ordering, never a hard similarity cutoff.
  $q->andWhere('('.implode(' OR ',$conditions).')')->addSelect($q->createFunction('('.implode(' + ',$scores).') AS score'))
   ->orderBy('score','DESC')->addOrderBy('c.file_id','ASC')->setFirstResult($offset)->setMaxResults($limit);
  $result=$q->executeQuery();try {$rows=$result->fetchAll();}finally {$result->closeCursor();}
  $items=$this->library->hydrate($uid,$rows);
  return ['items'=>$items,'next'=>$rows?$offset+count($rows):null,'more'=>count($rows)===$limit,'mode'=>'lexical'];
 }
}
