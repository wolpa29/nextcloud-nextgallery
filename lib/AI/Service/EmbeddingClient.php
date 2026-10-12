<?php
declare(strict_types=1);
namespace OCA\Nextgallery\AI\Service;
class EmbeddingClient {
 public const LMSTUDIO_MESSAGE='LM Studio liefert über diese Embedding-API nur Textvektoren. Für Bilder einen multimodalen Dienst verwenden.';
 protected function request(array $config,string $endpoint,?array $body=null,int $timeout=10):array {
  $url=($config['location']==='local'?'http://127.0.0.1:9379/v1':rtrim($config['url'],'/')).'/'.$endpoint;
  $handle=curl_init($url);$wire='';$headers=['Accept: application/json','Content-Type: application/json'];
  if(!empty($config['api_key']))$headers[]='Authorization: Bearer '.$config['api_key'];
  curl_setopt_array($handle,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROXY=>'',CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>$timeout,CURLOPT_HTTPHEADER=>$headers,
   CURLOPT_WRITEFUNCTION=>function($curl,$chunk)use(&$wire){if(strlen($wire)+strlen($chunk)>1048576)return 0;$wire.=$chunk;return strlen($chunk);}]);
  if($body!==null)curl_setopt_array($handle,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($body,JSON_THROW_ON_ERROR)]);
  $ok=curl_exec($handle)!==false;$code=curl_getinfo($handle,CURLINFO_HTTP_CODE);curl_close($handle);
  if($code===401 || $code===403)throw new \RuntimeException('Authentifizierung am Embedding-Server fehlgeschlagen. API-Key prüfen.');
  if(!$ok)throw new \RuntimeException('Embedding-Dienst nicht erreichbar oder Zeitüberschreitung. Adresse und Netzwerk prüfen.');
  if($code!==200)throw new \RuntimeException('Embedding-Dienst nicht bereit oder API-Adresse ungültig.');
  try{$value=json_decode($wire,true,64,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new \RuntimeException('Ungültige Embedding-Antwort');}
  if(!is_array($value))throw new \RuntimeException('Ungültige Embedding-Antwort');return $value;
 }
 public static function space(array $info,array $config):string {
  return hash('sha256',$info['space'].'|image-v1|edge='.$config['max_edge'].'|frames='.$config['video_frames']);
 }
 public function info(array $config):array {
  if($config['location']==='external' && $config['transport']==='lmstudio')throw new \RuntimeException(self::LMSTUDIO_MESSAGE);
  $info=$this->request($config,'info');
  $custom=$config['family']==='custom'&&$config['location']==='external';
  if(($info['model']??null)!==$config['model'] || (!$custom&&($info['family']??null)!==$config['family']))throw new \RuntimeException('Embedding-Modell passt nicht zur Konfiguration. Modell-ID und Modellfamilie prüfen.');
  if(!is_string($info['family']??null)||trim($info['family'])===''||strlen($info['family'])>128||preg_match('/[\x00-\x1f\x7f]/',$info['family']))throw new \RuntimeException('Ungültige Embedding-Antwort');
  if(!is_array($info['modalities']??null) || !in_array('image',$info['modalities'],true) || !in_array('text',$info['modalities'],true)
    || !is_string($info['space']??null) || !preg_match('/^[a-f0-9]{64}$/D',$info['space']) || !is_int($info['dimensions']??null) || $info['dimensions']<128 || $info['dimensions']>4096)throw new \RuntimeException('Ungültige Embedding-Antwort');
  return $info;
 }
 public function query(array $config,string $text,array $info):array {
  $result=$this->request($config,'embed',['model'=>$config['model'],'text'=>$text]);
  if(($result['space']??null)!==$info['space'])throw new \RuntimeException('Embedding-Modell wurde gewechselt');
  $vector=Embeddings::normalize($result['embedding']??[]);
  if(count($vector)!==$info['dimensions'])throw new \RuntimeException('Embedding-Dimension stimmt nicht');return $vector;
 }
 public function inspect(array $config):array {
  try{$info=$this->info($config);return ['ready'=>true,'location'=>$config['location'],'model'=>$info['model'],'dimensions'=>$info['dimensions'],'video'=>'sampled_frames','space'=>self::space($info,$config)];}
  catch(\Throwable $e){return ['ready'=>false,'location'=>$config['location'],'message'=>$e->getMessage()];}
 }
}
