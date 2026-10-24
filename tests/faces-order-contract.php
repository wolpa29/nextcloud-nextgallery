<?php
/** Real DB person order: named people first, alphabetical; unnamed by photo count. Synthetic images only. */
declare(strict_types=1);
require '/var/www/html/lib/base.php';
use OCP\Server;
use OCA\Nextgallery\AI\Service\{Settings,Faces,FaceArtifacts};
use OCA\Nextgallery\Service\Library;
if(!is_file('/test-output/ISOLATED_SYNTHETIC_TEST'))throw new RuntimeException('Synthetic guard missing');
set_exception_handler(function(Throwable $e){fwrite(STDERR,get_class($e).': '.$e->getMessage().' '.$e->getFile().':'.$e->getLine()."\n".$e->getTraceAsString()."\n");exit(1);});
$checks=0;function orderCheck(bool $ok,string $label):void {global $checks;$checks++;if(!$ok)throw new RuntimeException($label);}
$users=Server::get(\OCP\IUserManager::class);$session=Server::get(\OCP\IUserSession::class);$root=Server::get(\OCP\Files\IRootFolder::class);$settings=Server::get(Settings::class);$faces=Server::get(Faces::class);$art=Server::get(FaceArtifacts::class);$library=Server::get(Library::class);
$old=$settings->get();$uid='order-owner';$users->get($uid)?->delete();
try {
 $c=$old;$c['faces']['enabled']=true;$settings->save($c);
 $session->setUser($users->createUser($uid,'SyntheticOrderOnly2026!'));$folder=$root->getUserFolder($uid)->newFolder('OrderQa');
 $settings->saveAccount($uid,['enabled'=>false,'folders'=>['/OrderQa'],'folder_stages'=>['/OrderQa'=>['faces']],'faces_filters'=>['min_face_area'=>0.0,'min_sharpness'=>0.0,'min_group_photos'=>1]]);
 // Photos per group; small and big stay unnamed, the rest is named below.
 $groups=['zoe'=>[str_repeat('a',32),1],'big'=>[str_repeat('b',32),4],'aerne'=>[str_repeat('c',32),1],'small'=>[str_repeat('d',32),2],'anna'=>[str_repeat('e',32),1]];
 $files=[];foreach($groups as $key=>[$gid,$n])for($i=0;$i<$n;$i++)$files[]=[$gid,$folder->newFile("$key-$i.png",file_get_contents('/samples/dog.png'))];
 $library->save($uid,['roots'=>['/OrderQa']]);do{$step=$library->step($uid,false,100,10);}while($step['running']);
 $account=$faces->accountState($uid);$rev=$art->currentRevision();$vec=array_merge([1.0],array_fill(0,127,0.0));
 $dets=[];foreach($files as [$gid,$file])$dets[$gid]=$faces->publish($uid,$file->getId(),$file->getEtag(),$account['epoch'],$account['generation'],$account['rules_revision'],Faces::MODEL_SPACE,[['bbox'=>[.1,.1,.2,.2],'vector'=>$vec,'score'=>.95,'sharpness'=>150.0,'group_id'=>$gid]],$rev)[0]['id'];
 foreach(['zoe'=>'Zoe','aerne'=>'Ärne','anna'=>'anna'] as $key=>$label)$faces->action($uid,$groups[$key][0],'rename',null,null,$faces->accountState($uid)['rules_revision'],$label);
 $expected=[$groups['anna'][0],$groups['aerne'][0],$groups['zoe'][0],$groups['big'][0],$groups['small'][0]];
 orderCheck(array_column($faces->subjects($uid)['items'],'id')===$expected,'named people first in alphabetical order, then unnamed people by photo count');
 // Editing a person does not move it.
 $faces->action($uid,$groups['small'][0],'set_cover',$dets[$groups['small'][0]],null,$faces->accountState($uid)['rules_revision']);
 $faces->action($uid,$groups['zoe'][0],'rename',null,null,$faces->accountState($uid)['rules_revision'],'Zoe');
 orderCheck(array_column($faces->subjects($uid)['items'],'id')===$expected,'a recent change does not move a person to the top');
 $paged=array_merge(array_column($faces->subjects($uid,'',0,2)['items'],'id'),array_column($faces->subjects($uid,'',2,3)['items'],'id'));
 orderCheck($paged===$expected,'the order is the same across pages');
 echo "$checks real person-order assertions passed\n";
}finally{$users->get($uid)?->delete();$settings->save($old);}
