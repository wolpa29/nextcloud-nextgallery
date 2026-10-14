<?php
declare(strict_types=1);
require '/var/www/html/lib/base.php';
set_exception_handler(function(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);});
if(!is_file('/test-output/ISOLATED_SYNTHETIC_TEST'))throw new RuntimeException('Synthetic guard missing');
$reflection=new ReflectionClass(OCA\Nextgallery\Service\SearchIndex::class);$index=$reflection->newInstanceWithoutConstructor();$method=$reflection->getMethod('input');
$dir='/test-output/export-contract-'.bin2hex(random_bytes(6));mkdir($dir,0700);$stream=null;$path=null;$t=microtime(true);$hash=hash_init('sha256');$checks=0;
function checkExport($ok,$message){global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
try{
 $records=(function()use($hash){for($id=0;$id<60000;$id++){$line=json_encode(['id'=>$id,'text'=>str_repeat('synthetic searchable text ',12)],JSON_THROW_ON_ERROR)."\n";hash_update($hash,$line);yield $line;}})();
 [$stream,$path]=$method->invoke($index,$records,$dir);$expected=hash_final($hash);
 checkExport(is_resource($stream)&&str_starts_with($path,$dir.'/'),'Export must be a private resource');
 checkExport((fileperms($path)&0777)===0600,'Export permission must be 0600');
 checkExport(memory_get_peak_usage(true)<128*1048576,'Export memory grew with the full collection');
 $child='echo json_encode(["sha256"=>hash_file("sha256","php://stdin")]);';
 $process=new Symfony\Component\Process\Process([PHP_BINARY,'-r',$child]);$process->setTimeout(15);$process->setInput($stream);$process->mustRun();
 checkExport(json_decode($process->getOutput(),true,512,JSON_THROW_ON_ERROR)['sha256']===$expected,'Large stdin export was truncated or reordered');
 checkExport(microtime(true)-$t<15,'Large export suffers per-record polling delay');
 $failure=(function(){yield "one\n";throw new RuntimeException('synthetic export failure');})();
 try{$method->invoke($index,$failure,$dir);throw new LogicException('Export failure was swallowed');}catch(RuntimeException $e){checkExport($e->getMessage()==='synthetic export failure','Export failure changed');}
 checkExport(count(glob($dir.'/input-*.ndjson'))===1,'Failed export left a private temporary file');
 echo $checks.' resource export contracts passed; 60000 records; seconds='.(microtime(true)-$t).'; peak_mib='.(memory_get_peak_usage(true)/1048576)."\n";
}finally{if(is_resource($stream))fclose($stream);foreach(glob($dir.'/*')?:[] as $p)unlink($p);rmdir($dir);}
