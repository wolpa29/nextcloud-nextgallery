<?php
declare(strict_types=1);
namespace OCP {interface IConfig {} interface IDBConnection {} interface IRequest {public function getHeader(string $key):string;}}
namespace OCP\Security {interface ICrypto {}}
namespace OCP\Files {interface IRootFolder {} interface File {} interface Folder {}}
namespace OCP\AppFramework {class Controller {public function __construct(string $app,protected \OCP\IRequest $request){}}}
namespace OCP\AppFramework\Http {class DataResponse {public function __construct(public array $data,public int $status=200){}public function addHeader($k,$v){}}class DataDownloadResponse {}}
namespace OCP\AppFramework\OCS {class OCSException extends \Exception {}class OCSBadRequestException extends OCSException {}class OCSNotFoundException extends OCSException {}}
namespace {
$path=__DIR__.'/../../lib/';
foreach(['AI/Service/Settings','AI/Service/State','AI/Service/Metadata','AI/Service/Files','AI/Service/Inventory','Controller/AiWorkerController'] as $file)require $path.$file.'.php';
class Config implements \OCP\IConfig {public array $values=[];public function getAppValue($a,$k,$d=''){return $this->values[$k]??$d;}public function setAppValue($a,$k,$v){$this->values[$k]=$v;}}
class Crypto implements \OCP\Security\ICrypto {public function encrypt($s){return $s;}public function decrypt($s){return $s;}}
class Root implements \OCP\Files\IRootFolder {public function getUserFolder($uid){throw new \Exception('Unauthenticated request reached files');}}
class DB implements \OCP\IDBConnection {public function executeStatement($sql,$params){throw new \Exception('Unauthenticated request reached database');}}
class Request implements \OCP\IRequest {public string $token='';public function getHeader(string $key):string{return $key==='Authorization'?$this->token:'';}}
$c=new Config();$settings=new \OCA\Nextgallery\AI\Service\Settings($c,new Crypto());$root=new Root();$db=new DB();$files=new \OCA\Nextgallery\AI\Service\Files($root);$inventory=new \OCA\Nextgallery\AI\Service\Inventory($root,$db,$settings,$files);$metadata=new \OCA\Nextgallery\AI\Service\Metadata($db);$request=new Request();$controller=new \OCA\Nextgallery\Controller\AiWorkerController($request,$settings,$inventory,$metadata);$token=$settings->workerToken();$checks=0;
foreach(['','Bearer wrong','Basic '.$token] as $bad){$request->token=$bad;foreach([fn()=>$controller->config(),fn()=>$controller->inventory(),fn()=>$controller->reset(),fn()=>$controller->resolve(1),fn()=>$controller->image(1,'v1'),fn()=>$controller->publish(1,'v1','x','x',[]),fn()=>$controller->publishEmbedding(1,'v1',str_repeat('a',64),[])] as $call){if($call()->status!==401)throw new \Exception('Worker access not refused');$checks++;}}
$request->token='Bearer '.$token;if($controller->config()->status!==200)throw new \Exception('Valid worker refused');$checks++;if($controller->reset()->status!==409)throw new \Exception('Unrequested reset allowed');$checks++;
class ResetState extends \OCA\Nextgallery\AI\Service\State {public array $value=[];public function __construct(){}public function control():array{return $this->value;}}
class ResetMeta extends \OCA\Nextgallery\AI\Service\Metadata {public array $calls=[];public function __construct(){}public function reset(string $uid):void{$this->calls[]=$uid;}}
$resetState=new ResetState();$resetMeta=new ResetMeta();$controller=new \OCA\Nextgallery\Controller\AiWorkerController($request,$settings,$inventory,$resetMeta,$resetState);
foreach([[],['paused'=>false,'reset_requested'=>true],['paused'=>true,'reset_requested'=>false]] as $value){$resetState->value=$value;if($controller->reset()->status!==409 || $resetMeta->calls)throw new \Exception('Unsafe reset allowed');$checks++;}
$resetState->value=['paused'=>true,'reset_requested'=>true];if($controller->reset()->status!==200 || $resetMeta->calls!==[$settings->get()['user_id']])throw new \Exception('Explicit reset refused');$checks++;
echo "$checks PHP worker authentication assertions passed\n";
}
