<?php
declare(strict_types=1);
namespace OCP {interface IConfig {}}
namespace OCP\AppFramework\OCS {class OCSBadRequestException extends \Exception {}}
namespace {
require __DIR__.'/../../lib/AI/Service/State.php';
require __DIR__.'/../../lib/AI/Service/ProcessingAdministration.php';
class PipelineConfig implements \OCP\IConfig {
 public function __construct(public string $path){}
 public function getSystemValueString($key){return $this->path;}
 public function getAppValue($app,$key,$fallback=''){return $key==='ai_settings'?json_encode(['user_id'=>'alice','embedding'=>['enabled'=>true]]):$fallback;}
}
$checks=0;
function verifyPipeline($value,$message){global $checks;$checks++;if(!$value)throw new \RuntimeException($message);}
function rejectPipeline($fn,$message){try{$fn();}catch(\OCP\AppFramework\OCS\OCSBadRequestException){verifyPipeline(true,$message);return;}verifyPipeline(false,$message);}
$path=sys_get_temp_dir().'/nextgallery-pipelines-'.bin2hex(random_bytes(8));mkdir($path,0700);
try{
 $state=new \OCA\Nextgallery\AI\Service\State(new PipelineConfig($path));$state->atomic('control.json',['paused'=>false,'requested'=>true,'run_kind'=>'both','request_id'=>'legacy']);$state->atomic('schedule.json',['date'=>'2026-10-10']);$state->atomic('status.json',['analysis_stats'=>['done'=>5],'embedding_stats'=>['done'=>7]]);
 file_put_contents($state->directory().'/native.sqlite3','description cache sentinel');file_put_contents($state->directory().'/embeddings.sqlite3','vector cache sentinel');
 $d=$state->forPipeline('descriptions');$e=$state->forPipeline('embeddings');
 verifyPipeline($d->control()['requested']&&$e->control()['requested'],'Combined legacy request was lost');
 verifyPipeline(file_get_contents($state->directory().'/native.sqlite3')==='description cache sentinel'&&file_get_contents($state->directory().'/embeddings.sqlite3')==='vector cache sentinel','Migration rewrote caches');
 verifyPipeline($d->status()['stats']['done']===5&&$e->status()['stats']['done']===7,'Independent legacy counters lost');
 foreach([$d,$e] as $lane){$lane->atomic('service.json',['heartbeat_at'=>time()]);$lane->setControl('pause');$lane->atomic('status.json',['state'=>'paused','stats'=>['pending'=>2,'error'=>1]]);}
 $d->setControl('start',true);$d->atomic('status.json',['state'=>'running','current'=>['path'=>'description.png'],'stats'=>['pending'=>2,'error'=>1]]);
 $description=$d->control();$e->setControl('start',true);verifyPipeline($e->control()['requested']&&$d->control()===$description,'Embedding start blocked or changed descriptions');
 $e->setControl('pause',true);verifyPipeline($d->control()===$description,'Embedding pause changed descriptions');
 $e->setProcessingPaused(true);rejectPipeline(fn()=>$e->setControl('start',true),'Embedding suspension bypassed');
 verifyPipeline(!$d->processingPaused(),'Embedding admin pause affected descriptions');
 $descriptionGate=$d->globalLock();$embeddingGate=$e->globalLock(true);\OCA\Nextgallery\AI\Service\State::releaseLock($embeddingGate);
 rejectPipeline(fn()=>$d->globalLock(true),'Description change allowed under active description gate');\OCA\Nextgallery\AI\Service\State::releaseLock($descriptionGate);
 $e->requestReset('embeddings');verifyPipeline($e->control()['reset_kind']==='embeddings'&&$d->control()===$description,'Vector reset affected active descriptions');
 $e->atomic('control.json',['paused'=>true,'requested'=>false]);$e->setProcessingPaused(false);$e->requireEmbeddingRebuild('changed',['target_space'=>str_repeat('a',64)]);
 rejectPipeline(fn()=>$e->setControl('start',true),'Unconfirmed embedding rebuild started');
 $d->atomic('status.json',['state'=>'paused','stats'=>['pending'=>2]]);$d->setControl('pause');$d->setControl('start',true);verifyPipeline($d->control()['requested'],'Embedding rebuild blocked descriptions');
 $id=$e->status()['embedding_rebuild']['request_id'];$e->setControl('start',true,$id);verifyPipeline(!$e->status()['embedding_rebuild']['required'],'Embedding rebuild confirmation lost');
 verifyPipeline($state->status()['pipelines']['descriptions']['control']['requested']&&$state->status()['pipelines']['embeddings']['control']['requested'],'Both requests missing from status');
 $d->setControl('pause');$e->setControl('pause');$e->atomic('status.json',['state'=>'running']);$before=$d->control();rejectPipeline(fn()=>$state->requestReset('all'),'Combined reset allowed with active embeddings');verifyPipeline($d->control()===$before,'Rejected combined reset partly reset descriptions');
 $bob=$state->forUser('bob')->forPipeline('embeddings');$bob->setControl('pause');verifyPipeline($d->control()===$before,'Other account changed description state');
 $prior=['embedding'=>['enabled'=>true,'model'=>'old'],'vision'=>['model'=>'vision'],'ocr'=>[],'video'=>[],'analysis_enabled'=>true,'max_image_pixels'=>1000000];$next=$prior;$next['embedding']['model']='new';
 verifyPipeline(\OCA\Nextgallery\AI\Service\ProcessingAdministration::changedPipelines($prior,$next)===['embeddings'],'Embedding change locks descriptions');$ordered=$prior;$ordered['vision']=['model'=>'vision','url'=>'http://synthetic.invalid','temperature'=>0];$reordered=$ordered;$reordered['vision']=['temperature'=>0.0,'url'=>'http://synthetic.invalid','model'=>'vision'];$reordered['embedding']['model']='new';verifyPipeline(\OCA\Nextgallery\AI\Service\ProcessingAdministration::changedPipelines($ordered,$reordered)===['embeddings'],'Field order coupled independent model changes');$next=$prior;$next['vision']['model']='new';verifyPipeline(\OCA\Nextgallery\AI\Service\ProcessingAdministration::changedPipelines($prior,$next)===['descriptions'],'Vision change locks embeddings');$next=$prior;$next['max_image_pixels']=2000000;verifyPipeline(\OCA\Nextgallery\AI\Service\ProcessingAdministration::changedPipelines($prior,$next)===['descriptions','ocr','embeddings','faces','audio'],'Shared media limits lack both gates');
$next=$prior;$next['ocr']['model']='new-ocr';verifyPipeline(\OCA\Nextgallery\AI\Service\ProcessingAdministration::changedPipelines($prior,$next)===['ocr'],'OCR model changes must use only the OCR gate');
 $o=$state->forPipeline('ocr');$o->atomic('service.json',['heartbeat_at'=>time()]);$o->atomic('status.json',['state'=>'paused','stats'=>['pending'=>2]]);$o->setControl('pause');$d->atomic('status.json',['state'=>'paused','stats'=>['pending'=>2]]);$d->setControl('pause');$e->atomic('status.json',['state'=>'running']);$embeddingGate=$e->globalLock();$embeddingBefore=$e->control();
 $d->setControl('start',true);$o->setControl('start',true);verifyPipeline($d->control()['requested']&&$o->control()['requested']&&$e->control()===$embeddingBefore,'Running embeddings blocked description/OCR start');
 $o->setProcessingPaused(true);$o->setControl('pause');verifyPipeline($d->control()['requested']&&!$d->processingPaused()&&!$e->processingPaused(),'OCR admin pause affected other pipelines');\OCA\Nextgallery\AI\Service\State::releaseLock($embeddingGate);
 $pathLock=$state->rootDirectory().'/processing-descriptions.lock';chmod($pathLock,0400);try{$d->globalLock();verifyPipeline(false,'Readonly gate accepted');}catch(\OCP\AppFramework\OCS\OCSBadRequestException $error){verifyPipeline(str_contains($error->getMessage(),'nicht beschreibbar'),'Permission failure was reported as an active pipeline');}finally{chmod($pathLock,0600);}
 echo "$checks independent pipeline state/gate/migration assertions passed\n";
}finally{
 $it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($path);
}
}
