<?php
declare(strict_types=1);
namespace OCA\Chronicle\Controller;
use OCP\{IRequest,IUserSession};
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Attribute\{NoAdminRequired,NoCSRFRequired};
use OCA\Chronicle\Service\Library;
class ApiController extends Controller {
 public function __construct(IRequest $request,private IUserSession $session,private Library $library) {parent::__construct('chronicle',$request);}
 private function uid():string {return $this->session->getUser()->getUID();}
 private function response(array $v):JSONResponse {return new JSONResponse($v,200,['Cache-Control'=>'private, no-store']);}
 #[NoAdminRequired] #[NoCSRFRequired]
 public function settings():JSONResponse {return $this->response(['settings'=>$this->library->settings($this->uid()),'cacheKey'=>$this->library->cacheKey($this->uid())]);}
 #[NoAdminRequired]
 public function save(array $settings=[]):JSONResponse {
  try {$p=$this->library->save($this->uid(),$settings);return $this->response(['settings'=>$p,'cacheKey'=>$this->library->cacheKey($this->uid())]);}
  catch (\InvalidArgumentException $e) {return new JSONResponse(['error'=>$e->getMessage()],400);}
 }
 #[NoAdminRequired] #[NoCSRFRequired]
 public function folders(string $path='/'):JSONResponse {try{return $this->response(['folders'=>$this->library->folders($this->uid(),$path)]);}catch(\Throwable){return new JSONResponse(['error'=>'Ordner nicht verfügbar.'],404);}}
 #[NoAdminRequired] #[NoCSRFRequired]
 public function summary(string $kind=''):JSONResponse {return $this->response($this->library->summary($this->uid(),$kind));}
 #[NoAdminRequired] #[NoCSRFRequired]
 public function items(string $kind='',string $period='',int $offset=0,int $limit=150):JSONResponse {return $this->response($this->library->items($this->uid(),$kind,$period,$offset,$limit));}
 #[NoAdminRequired] #[NoCSRFRequired]
 public function map(int $offset=0):JSONResponse {return $this->response($this->library->items($this->uid(),'','',$offset,200,true));}
 #[NoAdminRequired]
 public function scan(bool $restart=false):JSONResponse {try{return $this->response($this->library->step($this->uid(),$restart,80,3));}catch(\OCP\Lock\LockedException){return new JSONResponse(['error'=>'Indexierung läuft bereits.'],409);}}
}
