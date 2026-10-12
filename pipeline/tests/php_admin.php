<?php
declare(strict_types=1);
namespace OCP {interface IConfig {}interface IRequest {}interface IUserSession {}interface IGroupManager {}}
namespace OCP\Security {interface ICrypto {}}
namespace OCP\Files {interface IRootFolder {}interface Folder {}class NotFoundException extends \Exception {}class NotPermittedException extends \Exception {}}
namespace OCP\AppFramework {class Controller {public function __construct($app,$request){}}}
namespace OCP\AppFramework\Http {class DataResponse {public function __construct(public array $data,public int $status=200){}}}
namespace OCP\AppFramework\OCS {class OCSBadRequestException extends \Exception {}}
namespace {
foreach(['AI/Service/Metadata','AI/Service/Settings','AI/Service/State','AI/Service/ModelConnection','AI/Service/EmbeddingClient','Controller/AiAdminController'] as $file)require __DIR__.'/../../lib/'.$file.'.php';
class Config implements \OCP\IConfig {public array $values=[];public string $dataDirectory;public function __construct(){$this->dataDirectory=sys_get_temp_dir().'/media-ai-admin-'.bin2hex(random_bytes(8));mkdir($this->dataDirectory);}public function getSystemValueString($key){return $this->dataDirectory;}public function getAppValue($a,$k,$d=''){return $this->values[$k]??$d;}public function setAppValue($a,$k,$v){$this->values[$k]=$v;}}
class Crypto implements \OCP\Security\ICrypto {public function encrypt($s){return $s;}public function decrypt($s){return $s;}}
class User {public function __construct(public string $uid){}public function getUID(){return $this->uid;}}
class Session implements \OCP\IUserSession {public ?string $uid='bob';public function getUser(){return $this->uid===null?null:new User($this->uid);}}
class Groups implements \OCP\IGroupManager {public function isAdmin($uid){return $uid==='admin';}}
class Folder implements \OCP\Files\Folder {public function isReadable(){return true;}public function get($path){if($path==='missing')throw new \OCP\Files\NotFoundException();return $this;}}
class Root implements \OCP\Files\IRootFolder {public function getUserFolder($uid){return new Folder();}}
class Meta extends \OCA\Nextgallery\AI\Service\Metadata {public int $amount=4000;public function __construct(){}public function count(string $uid,?string $stage=null):int{return $this->amount;}}
class Probe extends \OCA\Nextgallery\AI\Service\ModelConnection {public int $calls=0;public array $last=[];public function inspect(array $config):array{$this->last=$config;$this->calls++;return ['checked_at'=>123,'vision'=>['loaded'=>true,'context_length'=>114810]];}}
class EmbeddingProbe extends \OCA\Nextgallery\AI\Service\EmbeddingClient {public array $last=[];public function inspect(array $config):array{$this->last=$config;return ['ready'=>true];}}
class Request implements \OCP\IRequest {}
$checks=0;function check($v){global $checks;$checks++;if(!$v)throw new \Exception('Assertion '.$checks.' failed');}
$c=new Config();$settings=new \OCA\Nextgallery\AI\Service\Settings($c,new Crypto());$session=new Session();$probe=new Probe();$meta=new Meta();$controller=new \OCA\Nextgallery\Controller\AiAdminController(new Request(),$settings,new \OCA\Nextgallery\AI\Service\State($c),$session,new Groups(),new Root(),$meta,null,$probe);
foreach([fn()=>$controller->config(),fn()=>$controller->library(),fn()=>$controller->save([]),fn()=>$controller->status(),fn()=>$controller->logs('2026-10-02'),fn()=>$controller->control('start','','descriptions'),fn()=>$controller->folders('/'),fn()=>$controller->result(1),fn()=>$controller->maintenance('reset_ai','reset_ai'),fn()=>$controller->embeddingInstall(),fn()=>$controller->embeddingRemove('full')] as $call)check($call()->status===403);
check($controller->test()->status===403);check($probe->calls===0);$session->uid=null;check($controller->test()->status===401);$session->uid='paul';
foreach([fn()=>$controller->config(),fn()=>$controller->save([]),fn()=>$controller->status(),fn()=>$controller->test(),fn()=>$controller->embeddingTest()] as $call)check($call()->status===403);
$v=$settings->defaults();$v['folders']=['/InstantUpload'];$v['vision']['url']='http://127.0.0.1:9/v1';$v['vision']['api_key']='injected';$v['ocr']['loop_repetitions']=9;$v['ocr']['loop_retries']=0;$v['ocr']['retry_prompt']='injected';$v['ocr']['stream']=false;
$session->uid='admin';check($controller->config()->data['can_configure_models']);check($controller->test()->status===200);check($probe->calls===1);$controller->save($v);check($settings->get()['vision']['url']==='http://127.0.0.1:9/v1');
check($settings->get()['ocr']['loop_repetitions']===9);check($settings->get()['ocr']['loop_retries']===0);check($settings->get()['ocr']['retry_prompt']==='injected');check($settings->get()['ocr']['stream']===false);
$state=(new \OCA\Nextgallery\AI\Service\State($c))->forPipeline('descriptions');$state->atomic('service.json',['heartbeat_at'=>time()]);$state->atomic('status.json',['state'=>'running']);$before=$c->values;check($controller->save($v)->status===409);check($c->values===$before);
$draft=$settings->get();$draft['vision']['url']='http://draft.invalid:1234';$draft['vision']['api_key']='';check($controller->test($draft)->status===200);check($probe->last['vision']['url']==='http://draft.invalid:1234/v1');check($probe->last['vision']['api_key']==='injected');check($c->values===$before);
$draft['vision']['clear_api_key']=true;check($controller->test($draft)->status===200);check($probe->last['vision']['api_key']==='');check($c->values===$before);
$draft['vision']['api_key']='draft-secret';check($controller->test($draft)->status===200);check($probe->last['vision']['api_key']==='draft-secret');check($c->values===$before);
$draft['vision']['url']='ftp://invalid';$calls=$probe->calls;check($controller->test($draft)->status===400);check($probe->calls===$calls);check($c->values===$before);
$embeddingProbe=new EmbeddingProbe();$embeddingController=new \OCA\Nextgallery\Controller\AiAdminController(new Request(),$settings,new \OCA\Nextgallery\AI\Service\State($c),$session,new Groups(),new Root(),embeddingClient:$embeddingProbe);
$draft=$settings->get();$draft['embedding']['location']='external';$draft['embedding']['url']='http://embedding-draft.invalid:9379';$draft['embedding']['api_key']='synthetic-candidate-key';check($embeddingController->embeddingTest($draft)->status===200);check($embeddingProbe->last['url']==='http://embedding-draft.invalid:9379/v1');check($embeddingProbe->last['api_key']==='synthetic-candidate-key');check($c->values===$before);
// Only embedding fields are relevant to an unsaved embedding connection probe.
$draft['vision']['url']='ftp://invalid-vision';$draft['ocr']['model']='';$draft['schedule_time']='invalid';$draft['timezone']='invalid';
check($embeddingController->embeddingTest($draft)->status===200);check($embeddingProbe->last['api_key']==='synthetic-candidate-key');check($c->values===$before);
$draft['embedding']['url']='ftp://invalid-embedding';$last=$embeddingProbe->last;check($embeddingController->embeddingTest($draft)->status===400);check($embeddingProbe->last===$last);check($c->values===$before);
$draft['embedding']['url']='http://embedding-draft.invalid:9379';
$draft['embedding']['api_key']="invalid\nkey";check($embeddingController->embeddingTest($draft)->status===400);check($c->values===$before);
$state->atomic('status.json',['state'=>'running','heartbeat_at'=>time(),'stats'=>['pending'=>5,'error'=>1]]);
$controlBefore=$state->control();foreach(['start','resume','retry'] as $action){check($controller->control($action,'','descriptions')->status===409);check($state->control()===$controlBefore);}
$state->setControl('start');check($controller->control('pause','','descriptions')->status===200);check($controller->control('pause','','descriptions')->status===409);check($controller->control('start','','descriptions')->status===409);
$state->atomic('status.json',['state'=>'paused','heartbeat_at'=>time(),'stats'=>['pending'=>5,'error'=>1]]);$control=$state->control();$control['requested']=false;$control['pause_reason']='LM Studio offline';$state->atomic('control.json',$control);check($controller->status()->data['pipelines']['descriptions']['control']['pause_reason']==='LM Studio offline');check($controller->control('resume','','descriptions')->status===200);check(!isset($state->control()['pause_reason']));check($controller->control('resume','','descriptions')->status===409);
$control['paused']=true;$control['requested']=false;$state->atomic('control.json',$control);$state->atomic('status.json',['state'=>'paused','heartbeat_at'=>time(),'stats'=>['pending'=>0,'error'=>1]]);check($controller->control('resume','','descriptions')->status===409);check($controller->control('retry','','descriptions')->status===200);
$control['paused']=true;$control['requested']=false;$state->atomic('control.json',$control);$state->atomic('status.json',['state'=>'paused','heartbeat_at'=>time()]);check($controller->config()->data['metadata_count']===4000);check($controller->maintenance('reset_ai','',4000)->status===400);check($controller->maintenance('reset_ai','reset_ai',3999)->status===409);check(empty($state->control()['reset_requested']));check($controller->maintenance('reset_ai','reset_ai',4000)->status===200);check($state->control()['reset_requested']);
$state->atomic('status.json',['state'=>'finished','heartbeat_at'=>0,'stats'=>[]]);check($controller->control('start','','descriptions')->status===409);
$session->uid='paul';check($controller->embeddingInstall()->status===403);$session->uid='admin';check($controller->embeddingInstall()->status===409);
$state=$state->forPipeline('embeddings');$state->atomic('service.json',['heartbeat_at'=>time()]);$state->atomic('status.json',['state'=>'paused','heartbeat_at'=>time(),'embedding_setup_supported'=>true,'embedding_service'=>['phase'=>'not_installed','managed'=>true,'installed'=>false]]);$beforeInstall=$state->control();check($controller->embeddingInstall()->status===200);check($state->control()===$beforeInstall);
$external=$settings->get();$external['embedding']['location']='external';$settings->save($external);check($controller->embeddingInstall()->status===409);
$session->uid='paul';check($controller->embeddingRemove('full')->status===403);$session->uid='admin';check($controller->embeddingRemove('../state')->status===409);
$state->atomic('embedding-install.json',['requested'=>false]);$state->atomic('status.json',['state'=>'paused','heartbeat_at'=>time(),'embedding_remove_supported'=>true,'embedding_models'=>['full'=>['installed'=>true]]]);check($controller->embeddingRemove('full')->status===200);check($state->control()===$beforeInstall);
unlink($state->directory().'/embedding-remove.json');unlink($state->directory().'/embedding-install.json');
foreach(glob($state->directory().'/*') as $file)if(is_file($file))unlink($file);rmdir($state->directory());rmdir($c->dataDirectory);
echo "$checks PHP admin/owner access assertions passed\n";
}
