<?php
declare(strict_types=1);
namespace OCP {interface IConfig {} interface IDBConnection {} interface IRequest {public function getHeader(string $key):string;}}
namespace OCP\Security {interface ICrypto {}}
namespace OCP\Files {interface IRootFolder {} interface File {} interface Folder {}}
namespace OCP\AppFramework {class Controller {public function __construct(string $app,protected \OCP\IRequest $request){}}}
namespace OCP\AppFramework\Http {class DataResponse {public function __construct(public array $data,public int $status=200){}public function addHeader($k,$v){}}class StreamResponse {public array $headers=[];public function __construct(public $stream){}public function addHeader($k,$v){$this->headers[$k]=$v;}}}
namespace OCP\AppFramework\OCS {class OCSException extends \Exception {}class OCSBadRequestException extends OCSException {}class OCSNotFoundException extends OCSException {}}
namespace {
$path=__DIR__.'/../../lib/';
foreach(['AI/Service/Settings','AI/Service/Metadata','AI/Service/Files','AI/Service/Inventory','Controller/AiWorkerController'] as $file)require $path.$file.'.php';
class Config implements \OCP\IConfig {public array $values=[];public function getAppValue($a,$k,$d=''){return $this->values[$k]??$d;}public function setAppValue($a,$k,$v){$this->values[$k]=$v;}}
class Crypto implements \OCP\Security\ICrypto {public function encrypt($s){return $s;}public function decrypt($s){return $s;}}
class File implements \OCP\Files\File {
 public string $mime='video/mp4';public bool $readable=true;
 public int $size=100000000;public bool $encrypted=false;public object $storage;
 public function getId(){return 12;}public function getEtag(){return 'v1';}public function getSize(){return $this->size;}
 public function isEncrypted(){return $this->encrypted;}public function getStorage(){return $this->storage;}
 public function getMimeType(){return $this->mime;}public function isReadable(){return $this->readable;}
 public function getPath(){return '/paul/files/Camera/video.mp4';}
 public function fopen($mode){$s=fopen('php://temp','w+b');fwrite($s,'video bytes');rewind($s);return $s;}
}
class Folder implements \OCP\Files\Folder {
 public function __construct(public File $file){}public function getById($id){return [$this->file];}
 public function get($path){return $this;}public function isReadable(){return true;}
 public function getStorage(){return new class {function getCache(){return new class {function getNumericStorageId(){return 1;}};}};}
 public function getInternalPath(){return 'files';}public function getRelativePath($path){return '/Camera/video.mp4';}
}
class Root implements \OCP\Files\IRootFolder {public function __construct(public Folder $folder){}public function getUserFolder($uid){return $this->folder;}}
class DB implements \OCP\IDBConnection {public string $sql='';public function executeQuery($sql,$params){$this->sql=$sql;return new class {function fetchAll(){return [['fileid'=>12,'storage'=>1,'path'=>'files/Camera/video.mp4','etag'=>'v1','size'=>100000000,'mimetype'=>'video/mp4']];}function closeCursor(){}};}}
class Request implements \OCP\IRequest {public string $token='';public function getHeader(string $key):string{return $key==='Authorization'?$this->token:'';}}
$checks=0;function check($ok){global $checks;$checks++;if(!$ok)throw new \Exception('Media check '.$checks.' failed');}
$c=new Config();$settings=new \OCA\Nextgallery\AI\Service\Settings($c,new Crypto());$file=new File();$root=new Root(new Folder($file));$db=new DB();$files=new \OCA\Nextgallery\AI\Service\Files($root);$inventory=new \OCA\Nextgallery\AI\Service\Inventory($root,$db,$settings,$files);$metadata=new \OCA\Nextgallery\AI\Service\Metadata($db);$request=new Request();$controller=new \OCA\Nextgallery\Controller\AiWorkerController($request,$settings,$inventory,$metadata);$request->token='Bearer '.$settings->workerToken();
check($files->byId('paul',12)===$file);
$page=$inventory->page();check(count($page['items'])===1);check($page['items'][0]['mime']==='video/mp4');check(str_contains($db->sql,"video/%"));
$response=$controller->image(12,'v1');check($response instanceof \OCP\AppFramework\Http\StreamResponse);check(is_resource($response->stream));check(stream_get_contents($response->stream)==='video bytes');fclose($response->stream);check($response->headers['ETag']==='"v1"');check($response->headers['Content-Type']==='video/mp4');
check($controller->image(12,'wrong')->status===409);
$local=tempnam(sys_get_temp_dir(),'synthetic-embedding-video-');file_put_contents($local,'synthetic video');
$file->size=filesize($local);
$file->storage=new class($local) {
 public bool $local=true;public bool $encrypted=false;public string $received='';
 public function __construct(public string $path){}public function isLocal(){return $this->local;}
 public function getCache(){return new class($this->encrypted){public function __construct(public bool $encrypted){}public function get($id){return new class($this->encrypted) implements \ArrayAccess{public function __construct(public bool $encrypted){}public function getPath(){return 'files/Camera/video.mp4';}public function offsetExists(mixed $k):bool{return $k==='encrypted';}public function offsetGet(mixed $k):mixed{return $this->encrypted;}public function offsetSet(mixed $k,mixed $v):void{}public function offsetUnset(mixed $k):void{}};}};}
 public function getLocalFile($path){$this->received=$path;return $this->path;}
};
$c->values['ai_settings']=json_encode(['embedding'=>['enabled'=>true]]);
check($controller->resolve(12,'wrong',true)->status===409);
check($controller->resolve(12,'',true)->status===400);
$resolved=$controller->resolve(12,'v1',true);check($resolved->data['local_path']===realpath($local));check($file->storage->received==='files/Camera/video.mp4');
check(!array_key_exists('local_path',$controller->resolve(12)->data));
$file->encrypted=true;check($controller->resolve(12,'v1',true)->data['local_path']===null);$file->encrypted=false;
$file->storage->local=false;check($controller->resolve(12,'v1',true)->data['local_path']===null);$file->storage->local=true;
$file->storage->encrypted=true;check($controller->resolve(12,'v1',true)->data['local_path']===null);$file->storage->encrypted=false;
$file->size++;check($controller->resolve(12,'v1',true)->data['local_path']===null);
$file->mime='image/jpeg';check($controller->resolve(12,'v1',true)->status===400);$file->mime='video/mp4';
$c->values['ai_settings']='{}';check($controller->resolve(12,'v1',true)->status===400);
unlink($local);$file->size=100000000;
$file->mime='image/jpeg';check($controller->image(12,'v1')->status===413);
$file->mime='application/pdf';try{$files->byId('paul',12);throw new \Exception('Non-media accepted');}catch(\OCP\AppFramework\OCS\OCSException $e){check(true);}
$v=$settings->defaults();check($v['video']['frame_count']===10);check($v['ocr']['max_batch_images']===20);check($v['ocr']['tile_overlap_percent']===10);
foreach([['video','frame_count',0],['video','frame_count',21],['video','frame_count',10.0],['ocr','tile_overlap_percent',0],['ocr','max_batch_images',65]] as [$stage,$key,$value]){$bad=$v;$bad[$stage][$key]=$value;$before=$c->values;try{$settings->save($bad);throw new \Exception('Invalid value accepted');}catch(\OCP\AppFramework\OCS\OCSBadRequestException $e){check($before===$c->values);}}
foreach(['video','ocr'] as $stage){$bad=$v;$bad[$stage]='invalid';$before=$c->values;try{$settings->save($bad);throw new \Exception('Invalid media configuration accepted');}catch(\OCP\AppFramework\OCS\OCSBadRequestException $e){check($before===$c->values);}}
foreach(['vision','ocr'] as $stage){check($v[$stage]['model_max_pixels']===4194304);check($v[$stage]['transport']==='lmstudio');foreach([['model_max_pixels',-1],['model_max_pixels',100],['transport','unknown']] as [$key,$value]){$bad=$v;$bad[$stage][$key]=$value;$before=$c->values;try{$settings->save($bad);throw new \Exception('Invalid encoder configuration accepted');}catch(\OCP\AppFramework\OCS\OCSBadRequestException $e){check($before===$c->values);}}}
$data=\OCA\Nextgallery\AI\Service\Metadata::validate(12,'v1',str_repeat('a',64),str_repeat('b',64),['vision'=>['type'=>'video','description'=>'Ein Fahrrad am Strand.','tags'=>['Fahrrad'],'objects'=>[],'uncertain_details'=>[]],'ocr'=>['text'=>'','source'=>'skipped_video']],[]);check($data['type']==='video');check($data['ocr_text']==='');
echo "$checks PHP media assertions passed\n";
}
