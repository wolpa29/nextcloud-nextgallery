<?php
/** Disposable HTTP fixture with an actual shared folder; never run on production. */
declare(strict_types=1);
require '/var/www/html/lib/base.php';
use OCP\Server;
use OCA\Nextgallery\AI\Service\{Settings,Faces};
if(!is_file('/test-output/ISOLATED_SYNTHETIC_TEST')||!str_contains(Server::get(\OCP\IConfig::class)->getSystemValueString('dbhost'),'faces34'))throw new RuntimeException('Disposable fixture guard failed');
set_exception_handler(function(Throwable $e){fwrite(STDERR,get_class($e).': '.$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);});
$users=Server::get(\OCP\IUserManager::class);$session=Server::get(\OCP\IUserSession::class);$settings=Server::get(Settings::class);$shares=Server::get(\OCP\Share\IManager::class);$config=Server::get(\OCP\IConfig::class);$root=Server::get(\OCP\Files\IRootFolder::class);
$uids=['face-http-owner','face-http-reader','face-http-outsider'];$path='/test-output/face-artifacts-http.json';$phase=$argv[1]??'';
if($phase==='prepare'){
 foreach($uids as $uid)$users->get($uid)?->delete();
 $old=$settings->get();$oldShare=$config->getAppValue('nextgallery','ai_share_results','yes');$c=$old;$c['faces']['enabled']=true;$settings->save($c);$config->setAppValue('nextgallery','ai_share_results','yes');
 foreach($uids as $uid)$users->createUser($uid,'SyntheticFaceHttpOnly2026!');
 $session->setUser($users->get($uids[0]));$folder=$root->getUserFolder($uids[0])->newFolder('FaceCacheQa');$files=[];
 foreach(['face.png','empty.png'] as $name){$f=$folder->newFile($name,file_get_contents('/samples/dog.png'));$files[]=['id'=>$f->getId(),'etag'=>$f->getEtag()];}
 $share=$shares->newShare()->setNode($folder)->setShareType(\OCP\Share\IShare::TYPE_USER)->setSharedBy($uids[0])->setSharedWith($uids[1])->setPermissions(\OCP\Constants::PERMISSION_READ);$share=$shares->createShare($share);
 foreach($uids as $uid){$session->setUser($users->get($uid));$root->getUserFolder($uid);$selected=$uid===$uids[2]?'/':'/FaceCacheQa';$settings->saveAccount($uid,['folders'=>[$selected],'folder_stages'=>[$selected=>['faces']],'enabled'=>false]);}
 $data=['uids'=>$uids,'files'=>$files,'share'=>$share->getFullId(),'old_settings'=>$old,'old_share'=>$oldShare];file_put_contents($path,json_encode($data,JSON_THROW_ON_ERROR));echo json_encode(['uids'=>$uids,'files'=>$files])."\n";
}elseif($phase==='revoke'){$data=json_decode(file_get_contents($path),true);$session->setUser($users->get($uids[0]));$shares->deleteShare($shares->getShareById($data['share']));echo "revoked\n";
}elseif($phase==='cleanup'){$data=json_decode(file_get_contents($path),true);foreach($uids as $uid)$users->get($uid)?->delete();$settings->save($data['old_settings']);$config->setAppValue('nextgallery','ai_share_results',$data['old_share']);unlink($path);echo "cleaned\n";
}else throw new RuntimeException('Invalid phase');
