<?php
declare(strict_types=1);
namespace OCP {interface IConfig {}}
namespace OCP\Security {interface ICrypto {}}
namespace OCP\AppFramework\OCS {class OCSBadRequestException extends \Exception {}}
namespace {
require __DIR__.'/../nextcloud-app/media_ai/lib/Service/Settings.php';
require __DIR__.'/../nextcloud-app/media_ai/lib/Service/State.php';
class Config implements \OCP\IConfig {public array $values=[];public function getAppValue($app,$key,$default=''){return $this->values[$key]??$default;}public function setAppValue($app,$key,$value){$this->values[$key]=$value;}public function getSystemValueString($key){return $this->values[$key];}}
class Crypto implements \OCP\Security\ICrypto {public function encrypt($v){return 'encrypted:'.$v;}public function decrypt($v){return substr($v,10);}}
$count=0;function check($value){global $count;$count++;if(!$value)throw new \Exception('check '.$count.' failed');}
function reject(callable $f){try{$f();}catch(\OCP\AppFramework\OCS\OCSBadRequestException $e){check(true);return;}throw new \Exception('Unexpected acceptance');}
$c=new Config();$s=new \OCA\MediaAI\Service\Settings($c,new Crypto());
check(!$s->verifyWorker('Bearer '));$token=$s->workerToken();check($s->verifyWorker('Bearer '.$token));check(!$s->verifyWorker('Bearer wrong'));
$v=$s->defaults();$v['vision']['api_key']='secret-test';$s->save($v);check(!str_contains(json_encode($s->publicConfig()),'secret-test'));check($s->workerConfig()['vision']['api_key']==='secret-test');
$v['vision']['api_key']='';$s->save($v);check($s->workerConfig()['vision']['api_key']==='secret-test');
$bad=$v;$bad['folders']=['/Camera/../secret'];reject(fn()=>$s->save($bad));$bad=$v;$bad['vision']['url']='https://user:secret@example.test';reject(fn()=>$s->save($bad));$bad=$v;$bad['limit']=0;reject(fn()=>$s->save($bad));$bad=$v;$bad['vision']['api_key']="x\nheader";reject(fn()=>$s->save($bad));
check(\OCA\MediaAI\Service\Settings::endpoint('http://10.1.10.78:1234')==='http://10.1.10.78:1234/v1');
$v['vision']['clear_api_key']=true;$s->save($v);check($s->workerConfig()['vision']['api_key']==='');
$dir=sys_get_temp_dir().'/media-ai-test-'.bin2hex(random_bytes(8));mkdir($dir,0700);$c->values['datadirectory']=$dir;$state=new \OCA\MediaAI\Service\State($c);check($state->control()['paused']);$state->setControl('start');check(!$state->control()['paused']);$state->setControl('pause');check($state->control()['paused']);reject(fn()=>$state->logs('../secret'));reject(fn()=>$state->setControl('delete'));
foreach(glob($dir.'/.media_ai/*') as $file)unlink($file);rmdir($dir.'/.media_ai');rmdir($dir);
echo "$count PHP settings/control assertions passed\n";
}
