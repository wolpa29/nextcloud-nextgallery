<?php
declare(strict_types=1);
namespace OCA\Chronicle\Controller;
use OCP\{IRequest,IUserSession,IPreview};
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\Attribute\{NoAdminRequired,NoCSRFRequired};
use OCA\Chronicle\Service\Library;
use OCA\Chronicle\Http\{Bytes,MediaStream};
class MediaController extends Controller {
 public function __construct(IRequest $request,private IUserSession $session,private Library $library,private IPreview $previews) {parent::__construct('chronicle',$request);}
 #[NoAdminRequired] #[NoCSRFRequired]
 public function preview(int $id,int $size=384):Response {
  try {
   $file=$this->library->file($this->session->getUser()->getUID(),$id);
   $size=$size<=384?384:1024;$preview=$this->previews->getPreview($file,$size,$size,false,IPreview::MODE_COVER);
   $r=new Bytes($preview->getContent(),$preview->getMimeType(),200,['Cache-Control'=>'private, max-age=86400','X-Content-Type-Options'=>'nosniff']);$r->setETag($file->getEtag().'-'.$size);return $r;
  } catch (\OCP\Files\NotFoundException) {return new Bytes('','text/plain',404,['Cache-Control'=>'no-store']);}
  catch (\Throwable) {return new Bytes('','text/plain',415,['Cache-Control'=>'no-store']);}
 }
 #[NoAdminRequired] #[NoCSRFRequired]
 public function original(int $id):Response {
  try {return new MediaStream($this->library->file($this->session->getUser()->getUID(),$id),(string)$this->request->getHeader('Range'));}
  catch (\OCP\Files\NotFoundException) {return new Bytes('','text/plain',404);}
 }
}
