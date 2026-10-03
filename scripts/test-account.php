<?php
if(PHP_SAPI!=='cli')exit(1);define('OC_CONSOLE',1);require '/var/www/nextcloud/lib/base.php';\OC_App::loadApp('chronicle');
$um=\OC::$server->get(\OCP\IUserManager::class);$uid='chronicle-test-20261003';$path='/tmp/chronicle-test-auth.json';
if($um->userExists($uid))throw new RuntimeException('Test user exists; refusing to change it.');
$password=bin2hex(random_bytes(24));$user=$um->createUser($uid,$password);$user->setDisplayName('Chronik test');
$h=\OC::$server->get(\OCP\Files\IRootFolder::class)->getUserFolder($uid);$r=$h->newFolder('Chronik-Test');$y=$r->newFolder('2024');$m=$y->newFolder('08');
$cases=['2024/08/conflict_DEMO.jpg','undated/metadata_DEMO.jpg','2026/10/VID_20261012_120000_DEMO.mp4','2026/10/IMG_20261013_193000_DEMO.jpg'];
$ids=[];
foreach($cases as $case){$file=$m->newFile(basename($case),fopen('/tmp/chronicle-fixtures/'.$case,'rb'));$ids[basename($case)]=$file->getId();}
$blocked=$h->newFolder('Unselected')->newFile('BLOCKED_DEMO.jpg',fopen('/tmp/chronicle-fixtures/undated/metadata_DEMO.jpg','rb'));
\OC::$server->get(\OCA\Chronicle\Service\Library::class)->save($uid,['roots'=>['/Chronik-Test']]);
file_put_contents($path,json_encode(['user'=>$uid,'password'=>$password,'ids'=>$ids,'blocked'=>$blocked->getId()]));chmod($path,0600);echo "Created isolated test account with 5 synthetic files\n";
