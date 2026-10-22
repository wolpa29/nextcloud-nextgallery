<?php
declare(strict_types=1);
namespace OCA\Nextgallery\Service;
use OCP\IDBConnection;
/** Web and Android share the SQLite ranking and live authorization contract. */
class GallerySearch {
 public function __construct(private IDBConnection $db,private Library $library,private ?EmbeddingSearch $embeddingSearch=null,private ?NativeSearch $nativeSearch=null) {}
 public function search(string $uid,string $term,string $kind='',int $offset=0,int $limit=60,string $gallery='',string $phase='hybrid',string $search_token=''):array {
  $p=$this->library->settings($uid,$gallery);
  $native=$this->nativeSearch??$this->embeddingSearch?->native()??\OCP\Server::get(NativeSearch::class);
  $result=$native->search($uid,$term,$p['roots'],$kind,$offset,max(1,min(200,$limit)),$phase,'',$p['mode'],$p['timezone'],$search_token);
  $ids = array_map(fn($item) => (int)$item['id'], $result['items']);
  $payloads = [];
  if ($ids) {
   $q = $this->db->getQueryBuilder();
   $q->select('file_id', 'payload')
     ->from('nextgallery_media')
     ->where($q->expr()->eq('user_id', $q->createNamedParameter($uid)))
     ->andWhere($q->expr()->in('file_id', $q->createNamedParameter($ids, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
   $r = $q->executeQuery();
   try {
    while ($row = $r->fetch()) {
     $payloads[(int)$row['file_id']] = $row['payload'];
    }
   } finally { $r->closeCursor(); }
  }
  foreach($result['items'] as &$item){
   $date=Dates::resolve($item['path'],$item['metadata']['capture']??null,(int)($item['mtime']??0),$p['mode'],$p['timezone']);$item['date']=$date;
   $payload = $payloads[(int)$item['id']] ?? null;
   if($payload){$stored=json_decode($payload,true);if(($stored['etag']??null)===$item['etag'])$item['metadata']=array_replace($stored['metadata']??[],$item['metadata']);}
  }unset($item);return $result;
 }
}
