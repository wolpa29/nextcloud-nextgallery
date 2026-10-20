<?php
/** Real DB quality policy, synthetic images only. */
declare(strict_types=1);
require '/var/www/html/lib/base.php';
use OCP\Server;
use OCA\Nextgallery\AI\Service\{Settings,Faces,FaceArtifacts};
use OCA\Nextgallery\Service\Library;
if(!is_file('/test-output/ISOLATED_SYNTHETIC_TEST'))throw new RuntimeException('Synthetic guard missing');
set_exception_handler(function(Throwable $e){fwrite(STDERR,get_class($e).': '.$e->getMessage().' '.$e->getFile().':'.$e->getLine()."\n".$e->getTraceAsString()."\n");exit(1);});
$checks=0;function qualityCheck(bool $ok,string $label):void {global $checks;$checks++;if(!$ok)throw new RuntimeException($label);}
$users=Server::get(\OCP\IUserManager::class);$session=Server::get(\OCP\IUserSession::class);$root=Server::get(\OCP\Files\IRootFolder::class);$settings=Server::get(Settings::class);$faces=Server::get(Faces::class);$art=Server::get(FaceArtifacts::class);$library=Server::get(Library::class);$cfg=Server::get(\OCP\IConfig::class);
$old=$settings->get();$oldShare=$cfg->getAppValue('nextgallery','ai_share_results','yes');$uids=['quality-owner','quality-reader'];
foreach($uids as $uid)$users->get($uid)?->delete();
try {
 $c=$old;$c['faces']['enabled']=true;$settings->save($c);$cfg->setAppValue('nextgallery','ai_share_results','yes');
 foreach($uids as $uid)$users->createUser($uid,'SyntheticQualityOnly2026!');
 $session->setUser($users->get($uids[0]));$folder=$root->getUserFolder($uids[0])->newFolder('QualityQa');$files=[];
 foreach(['good-a','good-b','good-c','small','blur','rare-a','rare-b','mixed'] as $name)$files[$name]=$folder->newFile($name.'.png',file_get_contents('/samples/dog.png'));
 $manager=Server::get(\OCP\Share\IManager::class);$share=$manager->newShare()->setNode($folder)->setShareType(\OCP\Share\IShare::TYPE_USER)->setSharedBy($uids[0])->setSharedWith($uids[1])->setPermissions(\OCP\Constants::PERMISSION_READ);$manager->createShare($share);
 foreach($uids as $uid){$session->setUser($users->get($uid));$root->getUserFolder($uid);$settings->saveAccount($uid,['enabled'=>false,'folders'=>['/QualityQa'],'folder_stages'=>['/QualityQa'=>['faces']],'faces_filters'=>Settings::DEFAULT_FACE_FILTERS]);$library->save($uid,['roots'=>['/QualityQa']]);do{$step=$library->step($uid,false,100,10);}while($step['running']);}
 $account=$faces->accountState($uids[0]);$rev=$art->currentRevision();$vec=array_merge([1.0],array_fill(0,127,0.0));$dets=[];$groups=['good'=>str_repeat('a',32),'small'=>str_repeat('b',32),'blur'=>str_repeat('c',32),'rare'=>str_repeat('d',32)];
 foreach($files as $name=>$file){
  $gid=str_starts_with($name,'good')||$name==='mixed'?$groups['good']:(str_starts_with($name,'rare')?$groups['rare']:$groups[$name]);
  $raw=['bbox'=>$name==='small'?[.1,.1,.02,.02]:[.1,.1,.2,.2],'vector'=>$vec,'score'=>.95,'sharpness'=>in_array($name,['blur','mixed'])?2.0:150.0,'group_id'=>$gid];
  $out=$faces->publish($uids[0],$file->getId(),$file->getEtag(),$account['epoch'],$account['generation'],$account['rules_revision'],Faces::MODEL_SPACE,[$raw],$rev);$dets[$name]=$out[0];
 }
 qualityCheck($dets['good-a']['eligible']===true,'sharp foreground face eligible');
 qualityCheck($dets['small']['eligible']===false,'small background face excluded');
 qualityCheck($dets['blur']['eligible']===false,'blurred face excluded');
 $list=$faces->subjects($uids[0]);qualityCheck(count($list['items'])===1&&$list['items'][0]['photo_count']===3,'normal overview requires three quality photos');
 $media=$faces->media($uids[0],$groups['good']);qualityCheck(count($media['items'])===3,'normal detail count matches filtered list');
 $rare=$faces->subjects($uids[0],'',0,60,'single');qualityCheck(count($rare['items'])===1&&$rare['items'][0]['photo_count']===2,'rare view retains two-photo person');
 $weak=$faces->subjects($uids[0],'',0,60,'filtered');qualityCheck(count($weak['items'])===3,'filtered view retains small, blurry and mixed-group subsets');
 $mixed=$faces->media($uids[0],$groups['good'],'',0,60,'filtered');qualityCheck(count($mixed['items'])===1&&$mixed['items'][0]['id']===$files['mixed']->getId(),'filtered detail includes only weak subset');
 qualityCheck(!str_contains(json_encode($weak),'vector'),'quality list never exposes vectors');
 $state=$faces->state($uids[0]);$by=[];foreach($state['items'] as $item)$by[$item['id']]=$item;
 qualityCheck($by[$dets['small']['id']]['eligible']===false,'worker state excludes weak anchors');
 qualityCheck($by[$dets['good-a']['id']]['sharpness']===150.0,'quality metric preserved in worker state');
 $revision=$faces->accountState($uids[0])['rules_revision'];$faces->action($uids[0],$groups['small'],'resolve',null,[$dets['small']['id']],$revision);
 $state=$faces->state($uids[0]);$by=[];foreach($state['items'] as $item)$by[$item['id']]=$item;
 qualityCheck($by[$dets['small']['id']]['eligible']===true,'explicit confirmation overrides quality exclusion');
 qualityCheck(count($faces->subjects($uids[0],'',0,60,'filtered')['items'])===2,'confirmed face leaves filtered section');
 qualityCheck(count($faces->subjects($uids[0],'',0,60,'single')['items'])===2,'confirmed singleton remains accessible in rare section');
 $settings->saveAccount($uids[1],['faces_filters'=>['min_face_area'=>0.0,'min_sharpness'=>0.0,'min_group_photos'=>1]]);
 qualityCheck($art->currentRevision()===$rev,'personal thresholds never invalidate shared raw inference');
 $raw=$art->find($files['blur']->getId(),$files['blur']->getEtag(),$rev);qualityCheck(count($raw)===1&&$raw[0]['sharpness']===2.0,'blur metric remains available in shared raw artifact');
 qualityCheck(!array_diff(array_keys($raw[0]),['bbox','vector','score','sharpness']),'shared cache has no account eligibility or corrections');
 $b=$faces->accountState($uids[1]);$raw[0]['group_id']=str_repeat('e',32);$out=$faces->publish($uids[1],$files['blur']->getId(),$files['blur']->getEtag(),$b['epoch'],$b['generation'],$b['rules_revision'],Faces::MODEL_SPACE,$raw,$rev);
 qualityCheck($out[0]['eligible']===true,'second account may admit same raw blurred face independently');
 qualityCheck($out[0]['group_id']!==$dets['blur']['group_id'],'shared raw metric never transfers groups');
 // Tighten filters after correction; the manual choice survives.
 $settings->saveAccount($uids[0],['faces_filters'=>['min_face_area'=>.1,'min_sharpness'=>10000.0,'min_group_photos'=>3]]);
 $state=$faces->state($uids[0]);$by=[];foreach($state['items'] as $item)$by[$item['id']]=$item;
 qualityCheck($by[$dets['small']['id']]['eligible']===true,'manual override survives stricter account policy');
 qualityCheck($by[$dets['good-a']['id']]['eligible']===false,'new policy immediately affects unconfirmed stored detections');
 foreach([['min_face_area'=>-.1],['min_sharpness'=>INF],['min_group_photos'=>3.5],['unknown'=>1]] as $invalid){try{$settings->saveAccount($uids[0],['faces_filters'=>$invalid]);qualityCheck(false,'invalid policy accepted');}catch(\OCP\AppFramework\OCS\OCSBadRequestException){qualityCheck(true,'invalid personal policy rejected');}}
 try{FaceArtifacts::sanitizeRawDetections([['bbox'=>[.1,.1,.2,.2],'vector'=>$vec,'score'=>.95,'sharpness'=>NAN]]);qualityCheck(false,'nonfinite sharpness accepted');}catch(\OCP\AppFramework\OCS\OCSBadRequestException){qualityCheck(true,'invalid metric rejected');}
 $gen=$faces->accountState($uids[0])['generation'];$faces->reset($uids[0]);qualityCheck($faces->count($uids[0])===0&&$faces->accountState($uids[0])['generation']===$gen+1,'scoped reset clears filtered and visible face data');
 qualityCheck($faces->count($uids[1])===1,'reset preserves other account projections');
 qualityCheck($art->find($files['blur']->getId(),$files['blur']->getEtag(),$rev)!==null,'reset retains artifact referenced by other account');
 $faces->reset($uids[1]);qualityCheck($art->find($files['blur']->getId(),$files['blur']->getEtag(),$rev)===null,'last reset erases raw quality artifact');
 echo "$checks real face-quality policy/storage/lifecycle assertions passed\n";
}finally{foreach($uids as $uid)$users->get($uid)?->delete();$settings->save($old);$cfg->setAppValue('nextgallery','ai_share_results',$oldShare);}
