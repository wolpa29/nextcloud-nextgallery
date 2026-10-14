<?php
declare(strict_types=1);
namespace OCA\Nextgallery\Service;
use OCP\IDBConnection;
use OCA\Nextgallery\AI\Service\{Settings,EmbeddingClient};
/** SQL vector ranking; frame maximum keeps a brief matching scene searchable. */
class EmbeddingSearch {
 public function __construct(private IDBConnection $db,private Library $library,private Settings $settings,private EmbeddingClient $client){}
 public function native():NativeSearch {
  return new NativeSearch($this->db,\OCP\Server::get(\OCP\Files\IRootFolder::class),\OCP\Server::get(\OCP\FilesMetadata\IFilesMetadataManager::class),$this->settings,$this->client,\OCP\Server::get(SearchIndex::class));
 }
 public function availability(string $uid,string $gallery,string $kind):string {
  $p=$this->library->settings($uid,$gallery);$c=$this->settings->workerConfig()['embedding'];
  if(!$c['enabled'])return 'disabled';
  $r=\OCP\Server::get(SearchIndex::class)->query($uid,['query'=>'search','roots'=>$p['roots'],'kind'=>$kind,'offset'=>0,'limit'=>1,'phase'=>'text','embedding_enabled'=>true,'mode'=>$p['mode'],'timezone'=>$p['timezone']]);
  return $r['embedding_state'];
 }
 public function ranks(string $uid,string $term,string $gallery,string $kind):array {
  $p=$this->library->settings($uid,$gallery);$items=[];$cursor=0;$state='not_indexed';$token='';
  do{$r=$this->native()->search($uid,$term,$p['roots'],$kind,$cursor,200,'hybrid','',$p['mode'],$p['timezone'],$token,true);$state=$r['embedding_state'];$token=$r['search_token']??'';
   foreach($r['items'] as $item)if(in_array('embedding',$item['search_match']['sources'],true))$items[]=$item['id'];
   $cursor=$r['next'];
  }while($r['more']&&$cursor!==null);
  return ['ids'=>$items,'state'=>$state];
 }
 public static function merge(array $text,array $image):array {
  $scores=[];foreach([$text,$image] as $list)foreach(array_values($list) as $rank=>$id)$scores[$id]=($scores[$id]??0)+1/(60+$rank+1);
  uksort($scores,fn($a,$b)=>($scores[$b]<=>$scores[$a])?:($a<=>$b));return array_keys($scores);
 }
}
