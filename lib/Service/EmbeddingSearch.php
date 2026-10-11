<?php
declare(strict_types=1);
namespace OCA\Nextgallery\Service;
use OCP\IDBConnection;
use OCA\Nextgallery\AI\Service\{Settings,EmbeddingClient};
/** Exact scoped scan; frame maximum keeps a brief matching scene searchable. */
class EmbeddingSearch {
 public function __construct(private IDBConnection $db,private Library $library,private Settings $settings,private EmbeddingClient $client){}
 private function scoped(string $uid,string $gallery,string $kind){
  $q=$this->db->getQueryBuilder();$q->from('nextgallery_ai_vectors','v')->innerJoin('v','nextgallery_media','c','c.file_id=v.file_id AND c.user_id=v.user_id AND c.etag=v.etag')
   ->where($q->expr()->eq('v.user_id',$q->createNamedParameter($uid)));
  $this->library->scopeQuery($q,$uid,'c.path_text',$gallery);
  if(in_array($kind,['image','video'],true))$q->andWhere($q->expr()->eq('c.kind',$q->createNamedParameter($kind)));return $q;
 }
 /** No model request: safe to use before returning immediately visible text hits. */
 public function availability(string $uid,string $gallery,string $kind):string {
  $config=$this->settings->workerConfig()['embedding'];if(!$config['enabled'])return 'disabled';
  $q=$this->scoped($uid,$gallery,$kind);$q->select('v.file_id')->setMaxResults(1);$r=$q->executeQuery();try{$any=$r->fetch();}finally{$r->closeCursor();}
  return $any?'pending':'not_indexed';
 }
 public function ranks(string $uid,string $term,string $gallery,string $kind):array {
  $state=$this->availability($uid,$gallery,$kind);if($state!=='pending')return ['ids'=>[],'state'=>$state];
  $config=$this->settings->workerConfig()['embedding'];
  try{
   $info=$this->client->info($config);$query=$this->client->query($config,$term,$info);$space=EmbeddingClient::space($info,$config);
   $q=$this->scoped($uid,$gallery,$kind);$q->select('v.file_id','v.vector_data','v.dimensions')->andWhere($q->expr()->eq('v.space',$q->createNamedParameter($space)));
   $r=$q->executeQuery();$scores=[];
   try{while($row=$r->fetch()){
    if((int)$row['dimensions']!==count($query))continue;$bytes=base64_decode($row['vector_data'],true);if($bytes===false || strlen($bytes)!==4*count($query))continue;
    $v=array_values(unpack('g*',$bytes));$score=0.;foreach($query as $i=>$value)$score+=$value*$v[$i];if(!is_finite($score))continue;
    $id=(int)$row['file_id'];$scores[$id]=isset($scores[$id])?max($scores[$id],$score):$score;
   }}finally{$r->closeCursor();}
   uksort($scores,fn($a,$b)=>($scores[$b]<=>$scores[$a])?:($a<=>$b));return ['ids'=>array_keys($scores),'state'=>$scores?'ready':'model_changed'];
  }catch(\Throwable){return ['ids'=>[],'state'=>'unavailable'];} // Text search survives an offline embedding host.
 }
 public static function merge(array $text,array $image):array {
  $scores=[];foreach([$text,$image] as $list)foreach(array_values($list) as $rank=>$id)$scores[$id]=($scores[$id]??0)+1/(60+$rank+1);
  uksort($scores,fn($a,$b)=>($scores[$b]<=>$scores[$a])?:($a<=>$b));return array_keys($scores);
 }
}
