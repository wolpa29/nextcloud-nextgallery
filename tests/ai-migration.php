<?php
declare(strict_types=1);
namespace OCP {interface IConfig {}}
namespace OCP\Security {interface ICrypto {}}
namespace OCP\Migration {class SimpleMigrationStep {}interface IOutput {}}
namespace {
require __DIR__.'/../lib/AI/Service/Settings.php';require __DIR__.'/../lib/Migration/Version000200Date20261009110000.php';
class Config implements \OCP\IConfig {public array $values=[];public function getAppValue($app,$key,$default=''){return $this->values[$app][$key]??$default;}public function setAppValue($app,$key,$value){$this->values[$app][$key]=$value;}}
class Crypto implements \OCP\Security\ICrypto {public function encrypt($s){return 'enc:'.$s;}public function decrypt($s){return substr($s,4);}}
class Output implements \OCP\Migration\IOutput {}
$c=new Config();$c->values['media_ai']=['settings'=>'{"user_id":"legacy","enabled":false}','worker_token'=>'enc:old-token','vision_api_key'=>'enc:old-key','ocr_api_key'=>'enc:old-ocr'];
$legacy=$c->values['media_ai'];$s=new \OCA\Nextgallery\AI\Service\Settings($c,new Crypto());
assert($s->get()['user_id']==='paul');
$m=new \OCA\Nextgallery\Migration\Version000200Date20261009110000($c);$m->postSchemaChange(new Output(),fn()=>null,[]);
assert($s->get()['user_id']==='legacy');assert($s->workerToken()==='old-token');assert($c->values['media_ai']===$legacy);assert($s->workerConfig()['vision']['api_key']==='old-key');assert($s->verifyWorker('Bearer old-token'));
$c->values['nextgallery']['ai_vision_api_key']='';$c->values['nextgallery']['ai_worker_token']='enc:new-token';$m->postSchemaChange(new Output(),fn()=>null,[]);
assert($s->workerConfig()['vision']['api_key']==='');assert($s->workerToken()==='new-token');assert(!$s->verifyWorker('Bearer old-token'));
$value=$s->get();$s->save($value);assert($c->values['media_ai']===$legacy);assert(isset($c->values['nextgallery']['ai_settings']));
echo "11 migration/cleared-secret/idempotence assertions passed\n";
}
