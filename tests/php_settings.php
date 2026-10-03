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
foreach(['vision','ocr'] as $stage)check($s->defaults()[$stage]['max_edge']===2048);
$v=$s->defaults();$v['vision']['api_key']='secret-test';$s->save($v);check(!str_contains(json_encode($s->publicConfig()),'secret-test'));check($s->workerConfig()['vision']['api_key']==='secret-test');
$v['vision']['api_key']='';$s->save($v);check($s->workerConfig()['vision']['api_key']==='secret-test');
$bad=$v;$bad['folders']=['/Camera/../secret'];reject(fn()=>$s->save($bad));$bad=$v;$bad['vision']['url']='https://user:secret@example.test';reject(fn()=>$s->save($bad));$bad=$v;$bad['image_timeout_seconds']=-1;reject(fn()=>$s->save($bad));$bad=$v;$bad['vision']['api_key']="x\nheader";reject(fn()=>$s->save($bad));
check(\OCA\MediaAI\Service\Settings::endpoint('http://10.1.10.78:1234')==='http://10.1.10.78:1234/v1');
$v['vision']['clear_api_key']=true;$s->save($v);check($s->workerConfig()['vision']['api_key']==='');
$v['vision']['max_tokens']=0;$v['ocr']['max_tokens']=0;$v['ocr']['timeout_seconds']=7200;$s->save($v);check($s->get()['vision']['max_tokens']===0);check($s->get()['ocr']['timeout_seconds']===7200);$bad=$v;$bad['ocr']['max_tokens']=-1;reject(fn()=>$s->save($bad));
foreach(['vision','ocr'] as $stage){
    check($s->defaults()[$stage]['stream']===true);
    $v[$stage]['loop_repetitions']=7;$v[$stage]['numeric_loop_repetitions']=60;$v[$stage]['loop_retries']=2;$v[$stage]['stream']=false;$v[$stage]['retry_prompt']='Kurz und genau.';$v[$stage]['allow_reasoning_json']=true;
    $s->save($v);check($s->get()[$stage]['loop_repetitions']===7);check($s->get()[$stage]['numeric_loop_repetitions']===60);check($s->get()[$stage]['loop_retries']===2);check($s->get()[$stage]['stream']===false);check($s->get()[$stage]['retry_prompt']==='Kurz und genau.');check($s->get()[$stage]['allow_reasoning_json']===true);
    foreach(['loop_repetitions'=>[2,21,5.0],'numeric_loop_repetitions'=>[19,201],'loop_retries'=>[-1,4],'stream'=>['true',1,null],'allow_reasoning_json'=>['false'],'retry_prompt'=>[str_repeat('x',20001),42],'retry_temperature'=>[-0.1,2.1,'invalid']] as $key=>$values){foreach($values as $invalid){$bad=$v;$bad[$stage][$key]=$invalid;$before=$c->values;reject(fn()=>$s->save($bad));check($c->values===$before);}}
}
$legacy=$v;foreach(['vision','ocr'] as $stage)foreach(['stream','loop_repetitions','numeric_loop_repetitions','loop_retries','retry_prompt','allow_reasoning_json'] as $key)unset($legacy[$stage][$key]);$s->save($legacy);check($s->get()['ocr']['loop_repetitions']===5);check($s->get()['vision']['loop_retries']===3);
$legacyLimits=$s->get();$legacyLimits['limit']=45;$legacyLimits['max_seconds']=3600;$c->values['settings']=json_encode($legacyLimits);check(!isset($s->get()['limit']));check(!isset($s->workerConfig()['max_seconds']));
foreach([0,600,86400] as $seconds){$timed=$s->get();$timed['image_timeout_seconds']=$seconds;$s->save($timed);check($s->get()['image_timeout_seconds']===$seconds);}
foreach([-1,86401,60.5,'600'] as $invalid){$bad=$s->get();$bad['image_timeout_seconds']=$invalid;reject(fn()=>$s->save($bad));}
$prompts=json_decode(file_get_contents(__DIR__.'/../nextcloud-app/media_ai/worker/default_prompts.json'),true);
foreach(['vision','ocr'] as $stage){
    check($s->publicConfig()[$stage]['default_prompt']===$prompts[$stage]['prompt']);
    check($s->publicConfig()[$stage]['default_retry_prompt']===$prompts[$stage]['retry_prompt']);
    check(!isset($s->workerConfig()[$stage]['default_prompt']));
}
$custom=$s->get();$custom['vision']['prompt']='Mein eigener Prompt';$s->save($custom);
check($s->publicConfig()['vision']['prompt']==='Mein eigener Prompt');
check($s->publicConfig()['vision']['default_prompt']===$prompts['vision']['prompt']);
check(!str_contains($c->values['settings'],'default_prompt'));
check(!str_contains(json_encode($s->publicConfig()),'secret-test'));
$dir=sys_get_temp_dir().'/media-ai-test-'.bin2hex(random_bytes(8));mkdir($dir,0700);$c->values['datadirectory']=$dir;$state=new \OCA\MediaAI\Service\State($c);check($state->control()['paused']);$state->setControl('start');check(!$state->control()['paused']);$state->setControl('pause');check($state->control()['paused']);reject(fn()=>$state->logs('../secret'));reject(fn()=>$state->setControl('delete'));
foreach(glob($dir.'/.media_ai/*') as $file)unlink($file);rmdir($dir.'/.media_ai');rmdir($dir);
echo "$count PHP settings/control assertions passed\n";
}
