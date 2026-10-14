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
  foreach($result['items'] as &$item){
   $date=Dates::resolve($item['path'],$item['metadata']['capture']??null,$item['mtime'],$p['mode'],$p['timezone']);$item['date']=$date;
   $r=$this->db->executeQuery('SELECT payload FROM *PREFIX*nextgallery_media WHERE user_id=? AND file_id=?',[$uid,$item['id']]);
   try{$payload=$r->fetchOne();}finally{$r->closeCursor();}
   if($payload){$stored=json_decode($payload,true);if(($stored['etag']??null)===$item['etag'])$item['metadata']=array_replace($stored['metadata']??[],$item['metadata']);}
  }unset($item);return $result;
 }
}
