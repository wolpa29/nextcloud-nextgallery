<?php
/** Isolated synthetic share lifecycle; fresh HTTP requests verify live permissions. */
declare(strict_types=1);
require '/var/www/html/lib/base.php';
set_exception_handler(function(Throwable $e):void {fwrite(STDERR,get_class($e).': '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine()."\n");exit(1);});
if(!is_file('/test-output/ISOLATED_SYNTHETIC_TEST'))throw new RuntimeException('Synthetic guard missing');
use OCP\Server;use OCP\{IUserManager,IUserSession,Constants};use OCP\Files\IRootFolder;use OCP\Share\IManager;
$phase=$argv[1]??'prepare';$users=Server::get(IUserManager::class);$manager=Server::get(IManager::class);$owner='sql-share-owner';$reader='sql-share-reader';
if($phase==='prepare'){
 foreach([$owner,$reader] as $uid)if(!$users->get($uid))$users->createUser($uid,'SyntheticShareOnly2026!');
 Server::get(IUserSession::class)->setUser($users->get($owner));$home=Server::get(IRootFolder::class)->getUserFolder($owner);$folder=$home->newFolder('SqlShare');$file=$folder->newFile('SQSHARED-car.png',file_get_contents('/samples/bike.png'));
 Server::get(IUserSession::class)->setUser($users->get($reader));Server::get(\OCA\Nextgallery\Service\SearchIndex::class)->ensure($reader);
 Server::get(IUserSession::class)->setUser($users->get($owner));
 $share=$manager->newShare()->setNode($folder)->setShareType(\OCP\Share\IShare::TYPE_USER)->setSharedBy($owner)->setSharedWith($reader)->setPermissions(Constants::PERMISSION_READ);
 $share=$manager->createShare($share);
 file_put_contents('/test-output/sql-share.json',json_encode(['share_id'=>$share->getFullId(),'file_id'=>$file->getId(),'reader'=>$reader],JSON_PRETTY_PRINT));echo "Shared synthetic folder after the recipient index was already created\n";
}elseif($phase==='revoke'){
 Server::get(IUserSession::class)->setUser($users->get($owner));$data=json_decode(file_get_contents('/test-output/sql-share.json'),true);$manager->deleteShare($manager->getShareById($data['share_id']));echo "Synthetic share revoked\n";
}elseif($phase==='cleanup')foreach([$owner,$reader] as $uid)$users->get($uid)?->delete();
else throw new RuntimeException('Invalid isolated test phase');
