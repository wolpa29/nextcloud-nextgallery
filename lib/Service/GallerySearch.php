<?php
declare(strict_types=1);
namespace OCA\Nextgallery\Service;
use OCP\IDBConnection;

/** One gallery scope for filenames and fresh AI text. Dense retrieval can extend this service. */
class GallerySearch {
 public function __construct(private IDBConnection $db,private Library $library,private ?EmbeddingSearch $embeddingSearch=null) {}
 public function search(string $uid,string $term,string $kind='',int $offset=0,int $limit=60,string $gallery=''):array {
  if (mb_strlen($term)>200 || $offset<0) throw new \InvalidArgumentException('Suchanfrage oder Seitenauswahl ungültig.');
  preg_match_all('/[\p{L}\p{N}_]+/u',mb_strtolower($term),$matches);
  $words=array_slice(array_values(array_unique($matches[0])),0,16);
  if (!$words) return ['items'=>[],'next'=>null,'more'=>false,'mode'=>'lexical'];
  $limit=max(1,min(200,$limit));$q=$this->db->getQueryBuilder();
  $q->select('c.file_id','c.payload')->from('nextgallery_media','c')
   ->leftJoin('c','nextgallery_ai_meta','a','a.user_id=c.user_id AND a.file_id=c.file_id AND a.etag=c.etag')
   ->where($q->expr()->eq('c.user_id',$q->createNamedParameter($uid)));
  $this->library->scopeQuery($q,$uid,'c.path_text',$gallery);
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
  $embedding=$this->embeddingSearch?->ranks($uid,$term,$gallery,$kind)??['ids'=>[],'state'=>'disabled'];
  if($embedding['ids']){
   // Union of all scoped text and image candidates: no similarity cutoff or AND gate.
   $q->setFirstResult(0)->setMaxResults(null);
   $r=$q->executeQuery();try{$lex=$r->fetchAll();}finally{$r->closeCursor();}
   $ids=EmbeddingSearch::merge(array_column($lex,'file_id'),$embedding['ids']);$page=array_slice($ids,$offset,$limit);
   $rows=[];
   foreach(array_chunk($page,100) as $chunk){
    $load=$this->db->getQueryBuilder();$load->select('file_id','payload')->from('nextgallery_media')->where($load->expr()->eq('user_id',$load->createNamedParameter($uid)))->andWhere($load->expr()->in('file_id',$load->createNamedParameter($chunk,\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
    $r=$load->executeQuery();try{foreach($r->fetchAll() as $row)$rows[(int)$row['file_id']]=$row;}finally{$r->closeCursor();}
   }
   $ordered=[];foreach($page as $id)if(isset($rows[$id]))$ordered[]=$rows[$id];
   return ['items'=>$this->library->hydrate($uid,$ordered),'next'=>$offset+count($page),'more'=>$offset+count($page)<count($ids),'mode'=>'hybrid','embedding_state'=>$embedding['state']];
  }
  $result=$q->executeQuery();try {$rows=$result->fetchAll();}finally {$result->closeCursor();}
  $items=$this->library->hydrate($uid,$rows);
  return ['items'=>$items,'next'=>$rows?$offset+count($rows):null,'more'=>count($rows)===$limit,'mode'=>'lexical','embedding_state'=>$embedding['state']];
 }
}
