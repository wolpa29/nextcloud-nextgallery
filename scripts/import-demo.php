<?php
// Run explicitly from CLI as www-data. Never touches personal folders.
if (PHP_SAPI!=='cli') exit(1);
define('OC_CONSOLE',1);
require '/var/www/nextcloud/lib/base.php';
set_exception_handler(static function($e){fwrite(STDERR,$e->getMessage()."\n");exit(1);});
\OC_App::loadApp('nextgallery');
$uid=$argv[1]??'paul';$source=$argv[2]??'/tmp/nextgallery-fixtures';
$home=\OC::$server->get(\OCP\Files\IRootFolder::class)->getUserFolder($uid);
$name='NextGallery-Demo';
if ($home->nodeExists($name) && !in_array('--resume',$argv,true)) throw new RuntimeException('Demo folder already exists; refusing to overwrite.');
$demo=$home->nodeExists($name)?$home->get($name):$home->newFolder($name);$count=0;
$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source,FilesystemIterator::SKIP_DOTS));
foreach($it as $file) {
 if (!$file->isFile())continue;$relative=substr($file->getPathname(),strlen(rtrim($source,'/'))+1);$parts=explode('/',$relative);$name=array_pop($parts);$parent=$demo;
 foreach($parts as $part)$parent=$parent->nodeExists($part)?$parent->get($part):$parent->newFolder($part);
 if ($parent->nodeExists($name)) {
  if (hash('sha256',$parent->get($name)->getContent())!==hash_file('sha256',$file->getPathname())) throw new RuntimeException('Existing demo file differs; refusing to overwrite.');
 } else {$stream=fopen($file->getPathname(),'rb');$parent->newFile($name,$stream);if(is_resource($stream))fclose($stream);}
 $count++;
}
$library=\OC::$server->get(\OCA\Nextgallery\Service\Library::class);
$library->save($uid,['roots'=>['/NextGallery-Demo'],'mode'=>'folder']);
echo json_encode(['user'=>$uid,'created'=>$count,'roots'=>['/NextGallery-Demo']])."\n";
