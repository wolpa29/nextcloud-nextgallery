<?php
declare(strict_types=1);
namespace OCA\Nextgallery\Http;
use OCP\AppFramework\Http\{Response,ICallbackResponse,IOutput};
use OCP\Files\File;
class MediaStream extends Response implements ICallbackResponse {
 private int $start=0;
 private int $length;
 public function __construct(private File $file,string $range='') {
  parent::__construct();$size=(int)$file->getSize();$this->length=$size;
  $this->addHeader('Content-Type',$file->getMimeType());$this->addHeader('Cache-Control','private, no-store');$this->addHeader('Accept-Ranges','bytes');$this->addHeader('X-Content-Type-Options','nosniff');
  if ($range) {
   if (!preg_match('/^bytes=(\d*)-(\d*)$/D',$range,$m) || ($m[1]===''&&$m[2]==='')) {$this->setStatus(416);$this->length=0;}
   else {
    $this->start=$m[1]===''?max(0,$size-(int)$m[2]):(int)$m[1];$end=$m[1]===''||$m[2]===''?$size-1:min($size-1,(int)$m[2]);
    if ($this->start>=$size || $end<$this->start) {$this->setStatus(416);$this->length=0;}
    else {$this->setStatus(206);$this->length=$end-$this->start+1;$this->addHeader('Content-Range',"bytes {$this->start}-$end/$size");}
   }
   if ($this->getStatus()===416) $this->addHeader('Content-Range','bytes */'.$size);
  }
  $this->addHeader('Content-Length',(string)$this->length);
 }
 public function callback(IOutput $output):void {
  if (!$this->length) return;$handle=$this->file->fopen('rb');if (!is_resource($handle)) return;
  try {
   if ($this->start && fseek($handle,$this->start)!==0) {$output->setHttpResponseCode(416);return;}
   $remaining=$this->length;while($remaining>0&&!feof($handle)&&!connection_aborted()) {$chunk=fread($handle,min(131072,$remaining));if ($chunk===false||$chunk==='') break;echo $chunk;$remaining-=strlen($chunk);}
  } finally {fclose($handle);}
 }
}
