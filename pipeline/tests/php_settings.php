<?php
declare(strict_types=1);
namespace OCP {interface IConfig {}}
namespace OCP\Security {interface ICrypto {}}
namespace OCP\AppFramework\OCS {class OCSBadRequestException extends \Exception {}}
namespace {
require __DIR__.'/../../lib/AI/Service/Settings.php';
require __DIR__.'/../../lib/AI/Service/State.php';
class Config implements \OCP\IConfig {public array $values=[];public function getAppValue($app,$key,$default=''){return $this->values[$key]??$default;}public function setAppValue($app,$key,$value){$this->values[$key]=$value;}public function getSystemValueString($key){return $this->values[$key];}}
class Crypto implements \OCP\Security\ICrypto {public function encrypt($v){return 'encrypted:'.$v;}public function decrypt($v){return substr($v,10);}}
$count=0;function check($value){global $count;$count++;if(!$value)throw new \Exception('check '.$count.' failed');}
function reject(callable $f){try{$f();}catch(\OCP\AppFramework\OCS\OCSBadRequestException $e){check(true);return;}throw new \Exception('Unexpected acceptance');}
$c=new Config();$s=new \OCA\Nextgallery\AI\Service\Settings($c,new Crypto());
check(!$s->verifyWorker('Bearer '));$token=$s->workerToken();check($s->verifyWorker('Bearer '.$token));check(!$s->verifyWorker('Bearer wrong'));
foreach(['vision','ocr'] as $stage)check($s->defaults()[$stage]['max_edge']===8192);
$v=$s->defaults();$v['vision']['api_key']='secret-test';$s->save($v);check(!str_contains(json_encode($s->publicConfig()),'secret-test'));check($s->workerConfig()['vision']['api_key']==='secret-test');
$v['vision']['api_key']='';$s->save($v);check($s->workerConfig()['vision']['api_key']==='secret-test');
$bad=$v;$bad['folders']=['/Camera/../secret'];reject(fn()=>$s->save($bad));$bad=$v;$bad['vision']['url']='https://user:secret@example.test';reject(fn()=>$s->save($bad));$bad=$v;$bad['image_timeout_seconds']=-1;reject(fn()=>$s->save($bad));$bad=$v;$bad['vision']['api_key']="x\nheader";reject(fn()=>$s->save($bad));
check(\OCA\Nextgallery\AI\Service\Settings::endpoint('http://10.1.10.78:1234')==='http://10.1.10.78:1234/v1');
$v['vision']['clear_api_key']=true;$s->save($v);check($s->workerConfig()['vision']['api_key']==='');
$v['vision']['max_tokens']=0;$v['ocr']['max_tokens']=0;$v['ocr']['timeout_seconds']=7200;$s->save($v);check($s->get()['vision']['max_tokens']===0);check($s->get()['ocr']['timeout_seconds']===7200);$bad=$v;$bad['ocr']['max_tokens']=-1;reject(fn()=>$s->save($bad));
foreach(['vision','ocr'] as $stage){
    check($s->defaults()[$stage]['stream']===true);
    $v[$stage]['loop_repetitions']=7;$v[$stage]['numeric_loop_repetitions']=60;$v[$stage]['loop_retries']=2;$v[$stage]['stream']=false;$v[$stage]['retry_prompt']='Kurz und genau.';$v[$stage]['allow_reasoning_json']=true;
    $s->save($v);check($s->get()[$stage]['loop_repetitions']===7);check($s->get()[$stage]['numeric_loop_repetitions']===60);check($s->get()[$stage]['loop_retries']===2);check($s->get()[$stage]['stream']===false);check($s->get()[$stage]['retry_prompt']==='Kurz und genau.');check($s->get()[$stage]['allow_reasoning_json']===true);
    foreach(['loop_repetitions'=>[2,21,5.0],'numeric_loop_repetitions'=>[19,201],'loop_retries'=>[-1,4],'stream'=>['true',1,null],'allow_reasoning_json'=>['false'],'retry_prompt'=>[str_repeat('x',20001),42],'retry_temperature'=>[-0.1,2.1,'invalid']] as $key=>$values){foreach($values as $invalid){$bad=$v;$bad[$stage][$key]=$invalid;$before=$c->values;reject(fn()=>$s->save($bad));check($c->values===$before);}}
}
$legacy=$v;foreach(['vision','ocr'] as $stage)foreach(['stream','loop_repetitions','numeric_loop_repetitions','loop_retries','retry_prompt','allow_reasoning_json'] as $key)unset($legacy[$stage][$key]);$s->save($legacy);check($s->get()['ocr']['loop_repetitions']===5);check($s->get()['vision']['loop_retries']===3);
$legacyLimits=$s->get();$legacyLimits['limit']=45;$legacyLimits['max_seconds']=3600;$c->values['ai_settings']=json_encode($legacyLimits);check(!isset($s->get()['limit']));check(!isset($s->workerConfig()['max_seconds']));
foreach([0,600,86400] as $seconds){$timed=$s->get();$timed['image_timeout_seconds']=$seconds;$s->save($timed);check($s->get()['image_timeout_seconds']===$seconds);}
foreach([-1,86401,60.5,'600'] as $invalid){$bad=$s->get();$bad['image_timeout_seconds']=$invalid;reject(fn()=>$s->save($bad));}
$prompts=json_decode(file_get_contents(__DIR__.'/../../worker/default_prompts.json'),true);
foreach(['vision','ocr'] as $stage){
    check($s->publicConfig()[$stage]['default_prompt']===$prompts[$stage]['prompt']);
    check($s->publicConfig()[$stage]['default_retry_prompt']===$prompts[$stage]['retry_prompt']);
    check(!isset($s->workerConfig()[$stage]['default_prompt']));
}
$custom=$s->get();$custom['vision']['prompt']='Mein eigener Prompt';$s->save($custom);
check($s->publicConfig()['vision']['prompt']==='Mein eigener Prompt');
check($s->publicConfig()['vision']['default_prompt']===$prompts['vision']['prompt']);
check(!str_contains($c->values['ai_settings'],'default_prompt'));
check(!str_contains(json_encode($s->publicConfig()),'secret-test'));
check($s->defaults()['analysis_enabled']===true);check($s->defaults()['embedding']['enabled']===false);check($s->defaults()['embedding']['local_auto_setup']===true);
$emb=$s->get();$emb['analysis_enabled']=false;$emb['embedding']['enabled']=true;$emb['embedding']['api_key']='synthetic-embedding-secret';$s->save($emb);check($s->workerConfig()['embedding']['api_key']==='synthetic-embedding-secret');check(!str_contains(json_encode($s->publicConfig()),'synthetic-embedding-secret'));check($s->publicConfig()['embedding']['has_api_key']);
$emb['embedding']['api_key']='';$s->save($emb);check($s->workerConfig()['embedding']['api_key']==='synthetic-embedding-secret');
$beforeProbe=$c->values;$draft=$s->get();$draft['embedding']['location']='external';$draft['embedding']['url']='http://synthetic.invalid:9379';
check($s->probeConfig($draft)['embedding']['url']==='http://synthetic.invalid:9379/v1');
check($s->probeConfig($draft)['embedding']['api_key']==='synthetic-embedding-secret');
$draft['embedding']['clear_api_key']=true;check($s->probeConfig($draft)['embedding']['api_key']==='');
$draft['embedding']['api_key']='replacement-key';check($s->probeConfig($draft)['embedding']['api_key']==='replacement-key');
check($c->values===$beforeProbe);$draft['embedding']['api_key']="bad\nkey";reject(fn()=>$s->probeConfig($draft));check($c->values===$beforeProbe);
foreach(['enabled'=>'yes','local_auto_setup'=>'true','location'=>'remote','transport'=>'unknown','family'=>'qwen_text','max_edge'=>10,'video_frames'=>0,'local_model_path'=>'relative/path'] as $key=>$invalid){$bad=$emb;$bad['embedding'][$key]=$invalid;$before=$c->values;reject(fn()=>$s->save($bad));check($c->values===$before);}
$automatic=$emb;$automatic['embedding']['local_model_path']='';$s->save($automatic);check($s->get()['embedding']['local_auto_setup']);check($s->get()['embedding']['model']==='embeddinggemma-2');
$manual=$automatic;$manual['embedding']['local_auto_setup']=false;reject(fn()=>$s->save($manual));
$legacy=$emb;unset($legacy['embedding']['local_auto_setup']);$legacy['embedding']['local_model_path']='/opt/custom/model.litertlm';$c->values['ai_settings']=json_encode($legacy);check(!$s->get()['embedding']['local_auto_setup']);
$legacy['embedding']['local_model_path']=$s->defaults()['embedding']['local_model_path'];$c->values['ai_settings']=json_encode($legacy);check($s->get()['embedding']['local_auto_setup']);
$emb['embedding']['clear_api_key']=true;$s->save($emb);check($s->workerConfig()['embedding']['api_key']==='');
$dir=sys_get_temp_dir().'/media-ai-test-'.bin2hex(random_bytes(8));mkdir($dir,0700);$c->values['datadirectory']=$dir;$state=(new \OCA\Nextgallery\AI\Service\State($c))->forPipeline('embeddings');check($state->control()['paused']);$state->setControl('start');check(!$state->control()['paused']);$state->setControl('pause');check($state->control()['paused']);reject(fn()=>$state->logs('../secret'));reject(fn()=>$state->setControl('delete'));
check(!$state->status()['embedding_install_requested']);reject(fn()=>$state->requestEmbeddingInstall());
$state->atomic('service.json',['heartbeat_at'=>time()]);$state->atomic('status.json',['heartbeat_at'=>time(),'state'=>'paused']);reject(fn()=>$state->requestEmbeddingInstall());
$state->atomic('service.json',['heartbeat_at'=>time()]);$state->atomic('status.json',['heartbeat_at'=>time(),'state'=>'paused','embedding_setup_supported'=>true,'embedding_service'=>['phase'=>'not_installed','managed'=>true,'installed'=>false]]);
$controlBefore=$state->control();$install=$state->requestEmbeddingInstall();check($install['requested']);check($state->status()['embedding_install_requested']);check($state->control()===$controlBefore);check($state->requestEmbeddingInstall()['request_id']===$install['request_id']);
$state->atomic('service.json',['heartbeat_at'=>time()]);$state->atomic('status.json',['heartbeat_at'=>time(),'embedding_setup_supported'=>true,'embedding_service'=>['phase'=>'error','managed'=>true,'installed'=>false]]);check($state->requestEmbeddingInstall()['request_id']!==$install['request_id']);
$state->atomic('service.json',['heartbeat_at'=>time()]);$state->atomic('status.json',['heartbeat_at'=>time(),'embedding_setup_supported'=>true,'embedding_service'=>['managed'=>true,'installed'=>true]]);reject(fn()=>$state->requestEmbeddingInstall());
$full=$s->get();$full['embedding']['enabled']=false;$full['embedding']['local_profile']='full';$s->save($full);check($s->get()['embedding']['model']==='embeddinggemma-2-full');
$full['embedding']['local_profile']='invalid';reject(fn()=>$s->save($full));
reject(fn()=>$state->requestEmbeddingInstall('full'));
$state->atomic('service.json',['heartbeat_at'=>time()]);$state->atomic('status.json',['heartbeat_at'=>time(),'state'=>'paused','embedding_setup_supported'=>true,'embedding_setup_profiles'=>['lite','full'],'embedding_remove_supported'=>true,'embedding_models'=>['full'=>['installed'=>true]],'embedding_service'=>['profile'=>'lite','managed'=>true,'installed'=>true]]);
$fullInstall=$state->requestEmbeddingInstall('full');check($fullInstall['profile']==='full');reject(fn()=>$state->requestEmbeddingRemove('full'));reject(fn()=>$state->requestEmbeddingRemove('../state'));
$state->atomic('embedding-install.json',['requested'=>false]);$remove=$state->requestEmbeddingRemove('full');check($remove['profile']==='full');check($state->control()===$controlBefore);check($state->requestEmbeddingRemove('full')['request_id']===$remove['request_id']);reject(fn()=>$state->requestEmbeddingInstall('full'));
$state->atomic('embedding-remove.json',['requested'=>false]);$state->atomic('service.json',['heartbeat_at'=>time()]);$state->atomic('status.json',['heartbeat_at'=>time(),'state'=>'running','embedding_remove_supported'=>true,'embedding_models'=>['full'=>['installed'=>true]]]);reject(fn()=>$state->requestEmbeddingRemove('full'));
$active=$s->get();$active['embedding']['enabled']=true;$s->save($active);
$state->atomic('service.json',['heartbeat_at'=>time()]);$state->atomic('status.json',['state'=>'paused','heartbeat_at'=>time()]);$state->requireEmbeddingRebuild(\OCA\Nextgallery\AI\Service\Settings::embeddingSignature($s->get()),['target_space'=>str_repeat('b',64),'old_dimensions'=>128,'new_dimensions'=>768]);
check($state->status()['embedding_rebuild']['required']);$rebuild=$state->status()['embedding_rebuild'];reject(fn()=>$state->setControl('start',true));reject(fn()=>$state->setControl('start',true,'old-request-id'));check($state->status()['embedding_rebuild']['required']);
$state->setControl('start',true,$rebuild['request_id']);check(!$state->status()['embedding_rebuild']['required']);check($state->status()['embedding_rebuild']['approved_space']===str_repeat('b',64));
$changed=$s->get();$changed['embedding']['timeout_seconds']=300;check(\OCA\Nextgallery\AI\Service\Settings::embeddingSignature($changed)===\OCA\Nextgallery\AI\Service\Settings::embeddingSignature($s->get()));$changed['embedding']['local_profile']='lite';check(\OCA\Nextgallery\AI\Service\Settings::embeddingSignature($changed)!==\OCA\Nextgallery\AI\Service\Settings::embeddingSignature($s->get()));
$state->setControl('pause');$description=$state->forPipeline('descriptions');$description->atomic('service.json',['heartbeat_at'=>time()]);$description->atomic('status.json',['state'=>'paused']);$description->setControl('start',true,'','descriptions');check($description->control()['requested']);$description->setControl('pause',true);$description->requestReset('descriptions');$firstReset=$description->control();$state->requestReset('embeddings');check($description->control()===$firstReset);check($state->control()['reset_kind']==='embeddings');
foreach(glob($dir.'/.nextgallery_ai/*') as $file)unlink($file);rmdir($dir.'/.nextgallery_ai');rmdir($dir);
echo "$count PHP settings/control assertions passed\n";
}
