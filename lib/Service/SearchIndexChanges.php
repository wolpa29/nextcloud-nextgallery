<?php
declare(strict_types=1);
namespace OCA\Nextgallery\Service;
use OCP\IConfig;

/** Durable invalidation of a disposable index; never touches AI controls or results. */
class SearchIndexChanges {
 public function __construct(private IConfig $config,private ?\Psr\Log\LoggerInterface $logger=null) {}
 public function directory(?string $uid=null):string {
  $root=rtrim($this->config->getSystemValueString('datadirectory'),'/').'/.nextgallery_search';
  $paths=[$root];if($uid!==null)$paths[]=$root.'/'.hash('sha256',$uid);
  foreach($paths as $path){if(is_link($path))throw new \RuntimeException('Unsafe search directory');if(!is_dir($path)&&!mkdir($path,0700)&&!is_dir($path))throw new \RuntimeException('Search directory unavailable');}
  return end($paths);
 }
 private function write(string $file,string $value):void {
  if(is_link($file))throw new \RuntimeException('Unsafe search marker');
  $tmp=$file.'.'.bin2hex(random_bytes(8));
  if(file_put_contents($tmp,$value,LOCK_EX)===false)throw new \RuntimeException('Search marker unavailable');
  chmod($tmp,0600);if(!rename($tmp,$file)){@unlink($tmp);throw new \RuntimeException('Search marker unavailable');}
 }
 private function mark(callable $write):void {
  try{$write();}catch(\Throwable $error){$this->logger?->warning('NextGallery search invalidation failed; the periodic index refresh will retry.',['app'=>'nextgallery','exception'=>$error]);}
 }
 public function file(string $uid,int $id):void {$this->mark(function()use($uid,$id){
  $dir=$this->directory($uid).'/dirty';if(is_link($dir))throw new \RuntimeException('Unsafe search markers');
  if(!is_dir($dir)&&!mkdir($dir,0700)&&!is_dir($dir))throw new \RuntimeException('Search markers unavailable');
  $this->write($dir.'/'.max(1,$id),bin2hex(random_bytes(16)));
 });}
 public function account(string $uid):void {$this->mark(fn()=>$this->write($this->directory($uid).'/revision',bin2hex(random_bytes(16))));}
 public function catalog():void {$this->mark(fn()=>$this->write($this->directory().'/revision',bin2hex(random_bytes(16))));}
 public function revision(string $uid):string {
  $root=$this->directory();$own=$this->directory($uid);$values=[];
  foreach([$root.'/revision',$own.'/revision'] as $p){if(is_link($p))throw new \RuntimeException('Unsafe search marker');$values[]=is_file($p)?file_get_contents($p):'';}
  return hash('sha256',implode('|',$values));
 }
 public function pending(string $uid):array {
  $out=[];$dir=$this->directory($uid).'/dirty';if(is_link($dir))throw new \RuntimeException('Unsafe search markers');
  foreach(glob($dir.'/*')?:[] as $p){if(is_link($p)||!ctype_digit(basename($p)))throw new \RuntimeException('Unsafe search marker');$out[(int)basename($p)]=(string)file_get_contents($p);}
  return $out;
 }
 public function acknowledge(string $uid,array $pending):void {
  $dir=$this->directory($uid).'/dirty';
  foreach($pending as $id=>$value){$p=$dir.'/'.$id;if(is_file($p)&&!is_link($p)&&file_get_contents($p)===$value)unlink($p);}
 }
 public function requestBuild(string $uid):void {
  $this->mark(function()use($uid){
   $requests=$this->directory().'/requests';if(is_link($requests))throw new \RuntimeException('Unsafe search requests');
   if(!is_dir($requests)&&!mkdir($requests,0700)&&!is_dir($requests))throw new \RuntimeException('Search requests unavailable');
   $file=$requests.'/'.hash('sha256',$uid);
   if(is_link($file))throw new \RuntimeException('Unsafe search request');
   if(is_file($file)&&file_get_contents($file)===$uid)return;
   $this->write($file,$uid);
  });
 }
 public function clearBuildRequest(string $uid):void {
  $requests=$this->directory().'/requests';$file=$requests.'/'.hash('sha256',$uid);
  if(is_file($file)&&!is_link($file))@unlink($file);
 }
 public function requestedBuilds():array {
  $requests=$this->directory().'/requests';if(!is_dir($requests)||is_link($requests))return [];
  $uids=[];
  foreach(glob($requests.'/*')?:[] as $p){
   if(is_link($p)||!preg_match('/^[a-f0-9]{64}$/D',basename($p)))continue;
   $uid=(string)@file_get_contents($p);
   if($uid!==''&&hash('sha256',$uid)===basename($p))$uids[]=$uid;
  }
  return array_values(array_unique($uids));
 }
 /** Delete only this account's derived index and short-lived search sessions. */
 public function forget(string $uid):void {
  $this->clearBuildRequest($uid);
  $root=rtrim($this->config->getSystemValueString('datadirectory'),'/').'/.nextgallery_search';$dir=$root.'/'.hash('sha256',$uid);
  if(!is_dir($dir))return;
  if(is_link($root)||is_link($dir)||is_link($dir.'/index.lock'))throw new \RuntimeException('Unsafe search directory');
  $lock=fopen($dir.'/index.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new \RuntimeException('Search cleanup unavailable');
  try{
   $entries=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
   foreach($entries as $entry){$path=$entry->getPathname();if($path===$dir.'/index.lock')continue;if($entry->isDir()&&!$entry->isLink())rmdir($path);else unlink($path);}
   unlink($dir.'/index.lock');rmdir($dir);
  }finally{flock($lock,LOCK_UN);fclose($lock);}
 }
}
