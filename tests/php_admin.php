<?php
declare(strict_types=1);
namespace OCP {interface IConfig {}interface IRequest {}interface IUserSession {}interface IGroupManager {}}
namespace OCP\Security {interface ICrypto {}}
namespace OCP\Files {interface IRootFolder {}interface Folder {}class NotFoundException extends \Exception {}class NotPermittedException extends \Exception {}}
namespace OCP\AppFramework {class Controller {public function __construct($app,$request){}}}
namespace OCP\AppFramework\Http {class DataResponse {public function __construct(public array $data,public int $status=200){}}}
namespace OCP\AppFramework\OCS {class OCSBadRequestException extends \Exception {}}
namespace {
foreach(['Service/Settings','Service/State','Controller/AdminController'] as $file)require __DIR__.'/../nextcloud-app/media_ai/lib/'.$file.'.php';
class Config implements \OCP\IConfig {public array $values=[];public string $dataDirectory;public function __construct(){$this->dataDirectory=sys_get_temp_dir().'/media-ai-admin-'.bin2hex(random_bytes(8));mkdir($this->dataDirectory);}public function getSystemValueString($key){return $this->dataDirectory;}public function getAppValue($a,$k,$d=''){return $this->values[$k]??$d;}public function setAppValue($a,$k,$v){$this->values[$k]=$v;}}
class Crypto implements \OCP\Security\ICrypto {public function encrypt($s){return $s;}public function decrypt($s){return $s;}}
class User {public function __construct(public string $uid){}public function getUID(){return $this->uid;}}
class Session implements \OCP\IUserSession {public string $uid='bob';public function getUser(){return new User($this->uid);}}
class Groups implements \OCP\IGroupManager {public function isAdmin($uid){return $uid==='admin';}}
class Folder implements \OCP\Files\Folder {public function isReadable(){return true;}public function get($path){if($path==='missing')throw new \OCP\Files\NotFoundException();return $this;}}
class Root implements \OCP\Files\IRootFolder {public function getUserFolder($uid){return new Folder();}}
class Request implements \OCP\IRequest {}
$checks=0;function check($v){global $checks;$checks++;if(!$v)throw new \Exception('Assertion '.$checks.' failed');}
$c=new Config();$settings=new \OCA\MediaAI\Service\Settings($c,new Crypto());$session=new Session();$controller=new \OCA\MediaAI\Controller\AdminController(new Request(),$settings,new \OCA\MediaAI\Service\State($c),$session,new Groups(),new Root());
foreach([fn()=>$controller->config(),fn()=>$controller->save([]),fn()=>$controller->status(),fn()=>$controller->logs('2026-10-02'),fn()=>$controller->control('start'),fn()=>$controller->folders('/'),fn()=>$controller->test(),fn()=>$controller->result(1),fn()=>$controller->maintenance('reset_ai','reset_ai')] as $call)check($call()->status===403);
$session->uid='paul';check(!$controller->config()->data['can_configure_models']);$v=$settings->defaults();$v['folders']=['/InstantUpload'];$v['vision']['url']='http://127.0.0.1:9/v1';$v['vision']['api_key']='injected';$v['user_id']='admin';$v['ocr']['loop_repetitions']=9;$v['ocr']['loop_retries']=0;$v['ocr']['retry_prompt']='injected';$v['ocr']['stream']=false;$controller->save($v);check($settings->get()['folders']===['/InstantUpload']);check($settings->get()['vision']['url']===$settings->defaults()['vision']['url']);check($settings->get()['user_id']==='paul');check($settings->workerConfig()['vision']['api_key']==='');check($controller->folders('../admin')->status===400);
$bad=$v;$bad['folders']=['/missing'];check($controller->save($bad)->status===400);check($settings->get()['folders']===['/InstantUpload']);
check($settings->get()['ocr']['loop_repetitions']===5);check($settings->get()['ocr']['loop_retries']===3);check($settings->get()['ocr']['stream']===true);check($settings->get()['ocr']['retry_prompt']==='');
$session->uid='admin';check($controller->config()->data['can_configure_models']);$controller->save($v);check($settings->get()['vision']['url']==='http://127.0.0.1:9/v1');
check($settings->get()['ocr']['loop_repetitions']===9);check($settings->get()['ocr']['loop_retries']===0);check($settings->get()['ocr']['retry_prompt']==='injected');check($settings->get()['ocr']['stream']===false);
$state=new \OCA\MediaAI\Service\State($c);$state->atomic('status.json',['state'=>'running']);$before=$c->values;check($controller->save($v)->status===409);check($c->values===$before);
unlink($state->directory().'/status.json');rmdir($state->directory());rmdir($c->dataDirectory);
echo "$checks PHP admin/owner access assertions passed\n";
}
