<?php
declare(strict_types=1);
namespace OCA\Chronicle\Controller;
use OCP\{IRequest,IUserSession,IURLGenerator};
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\{TemplateResponse,Response};
use OCP\AppFramework\Http\Attribute\{NoAdminRequired,NoCSRFRequired};
use OCA\Chronicle\Service\Library;
use OCA\Chronicle\Http\Bytes;
class PageController extends Controller {
 public function __construct(IRequest $request,private IUserSession $session,private Library $library,private IURLGenerator $url) {parent::__construct('chronicle',$request);}
 private function data(bool $offline):array {
  $uid=$this->session->getUser()->getUID();
  return ['context'=>['base'=>$this->url->linkToRoute('chronicle.page.index'),'assets'=>$this->url->linkTo('chronicle',''),'cacheKey'=>$this->library->cacheKey($uid),'offline'=>$offline,'settings'=>$this->library->settings($uid)]];
 }
 #[NoAdminRequired] #[NoCSRFRequired]
 public function index():TemplateResponse {return new TemplateResponse('chronicle','index',$this->data(false));}
 #[NoAdminRequired] #[NoCSRFRequired]
 public function offline():Response {
  $_=$this->data(true);ob_start();include __DIR__.'/../../templates/offline.php';$body=ob_get_clean();
  return new Bytes($body,'text/html; charset=utf-8',200,['Cache-Control'=>'private, no-store']);
 }
 #[NoAdminRequired] #[NoCSRFRequired]
 public function worker():Response {return new Bytes(file_get_contents(__DIR__.'/../../js/sw.js'),'application/javascript',200,['Cache-Control'=>'no-cache','Service-Worker-Allowed'=>$this->url->linkToRoute('chronicle.page.index')]);}
}
