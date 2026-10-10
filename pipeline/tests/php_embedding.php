<?php
declare(strict_types=1);
namespace OCP{interface IDBConnection{}}
namespace OCP\AppFramework\OCS{class OCSBadRequestException extends \Exception{}}
namespace {
require __DIR__.'/../../lib/AI/Service/Embeddings.php';require __DIR__.'/../../lib/AI/Service/EmbeddingClient.php';require __DIR__.'/../../lib/Service/EmbeddingSearch.php';
use OCA\Nextgallery\AI\Service\{Embeddings,EmbeddingClient};use OCA\Nextgallery\Service\EmbeddingSearch;
$count=0;function check($v){global $count;$count++;if(!$v)throw new \Exception('Embedding assertion '.$count);}
check(EmbeddingSearch::merge([1,2],[3,2])===[2,1,3]);check(EmbeddingSearch::merge([],range(1,1000))===range(1,1000));check(EmbeddingSearch::merge([99],[1,2,3])===[1,99,2,3]);
$info=['space'=>str_repeat('a',64)];$config=['max_edge'=>2048,'video_frames'=>10];check(EmbeddingClient::space($info,$config)===hash('sha256',str_repeat('a',64).'|image-v1|edge=2048|frames=10'));
$vector=Embeddings::normalize(array_merge([2.],array_fill(0,127,0.)));check($vector[0]===1.);check(strlen(pack('g*',...$vector))===512);
foreach([array_fill(0,128,0.),array_fill(0,128,NAN),array_fill(0,128,INF),array_fill(0,128,'1'),[1.,2.]] as $bad){try{Embeddings::normalize($bad);throw new \Exception('Invalid vector accepted');}catch(\OCP\AppFramework\OCS\OCSBadRequestException){$count++;}}
$client=new EmbeddingClient();try{$client->info(['location'=>'external','transport'=>'lmstudio']);throw new \Exception('LM Studio image support invented');}catch(\RuntimeException $e){check($e->getMessage()===EmbeddingClient::LMSTUDIO_MESSAGE);}
echo "$count embedding storage/ranking assertions passed\n";
}
