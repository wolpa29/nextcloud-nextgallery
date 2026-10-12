<?php
declare(strict_types=1);
namespace OCA\Nextgallery\Controller;
use OCP\{IRequest,IUserSession,IURLGenerator,IConfig,IGroupManager};
use OCP\L10N\IFactory;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\{TemplateResponse,StandaloneTemplateResponse,Response,ContentSecurityPolicy,EmptyContentSecurityPolicy};
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Attribute\{NoAdminRequired,NoCSRFRequired};
use OCA\Nextgallery\Service\Library;
use OCA\Nextgallery\Http\Bytes;
class PageController extends Controller {
 public function __construct(IRequest $request,private IUserSession $session,private Library $library,private IURLGenerator $url,private IAppManager $apps,private IFactory $languages,private IConfig $config,private IGroupManager $groups,private \OCA\Nextgallery\AI\Service\Settings $aiSettings) {parent::__construct('nextgallery',$request);}
 private function canManageAi():bool { return $this->session->getUser()!==null; }
 private function data(bool $offline):array {
  $uid=$this->session->getUser()->getUID();
  // Resolve the user's Nextcloud language independently of app l10n files.
  // This frontend supplies its own translations; app-specific IL10N would
  // otherwise fall back to English when no Nextcloud l10n/de.json exists.
  return ['context'=>['base'=>$this->url->linkToRoute('nextgallery.page.index'),'aiUrl'=>!$offline && $this->canManageAi()?$this->url->linkToRoute('nextgallery.page.ai'):null,'assets'=>$this->url->linkTo('nextgallery',''),'sourceUrl'=>$this->config->getAppValue('nextgallery','source_url',''),'version'=>$this->apps->getAppVersion('nextgallery'),'language'=>$this->library->settings($uid)['language']==='system'?$this->languages->findLanguage():$this->library->settings($uid)['language'],'embedded'=>$this->request->isUserAgent([IRequest::USER_AGENT_CLIENT_ANDROID]),'accountKey'=>$this->library->cacheKey($uid,'default'),'cacheKey'=>$this->library->cacheKey($uid),'offline'=>$offline,'settings'=>$this->library->settings($uid)]];
 }
 #[NoAdminRequired] #[NoCSRFRequired]
 public function ai(int $embedded=0):Response {
  if (!$this->canManageAi()) return new \OCP\AppFramework\Http\JSONResponse(['error'=>'KI-Verarbeitung ist für dieses Konto nicht freigegeben.'],403);
  return $this->aiResponse(['admin'=>$this->groups->isAdmin($this->session->getUser()->getUID()),'galleryUrl'=>$this->url->linkToRoute('nextgallery.page.gallery'),'accountUrl'=>$this->url->linkToRoute('nextgallery.page.processing'),'appearance'=>$this->library->settings($this->session->getUser()->getUID())['appearance'],'language'=>$this->library->settings($this->session->getUser()->getUID())['language']==='system'?$this->languages->findLanguage():$this->library->settings($this->session->getUser()->getUID())['language']],$embedded);
 }
 #[NoAdminRequired] #[NoCSRFRequired]
 public function processing(int $embedded=0):Response {
  $data=['galleryUrl'=>$this->url->linkToRoute('nextgallery.page.gallery'),'admin'=>false,'adminUrl'=>$this->groups->isAdmin($this->session->getUser()->getUID())?$this->url->linkToRoute('nextgallery.page.ai'):null,'appearance'=>$this->library->settings($this->session->getUser()->getUID())['appearance'],'language'=>$this->library->settings($this->session->getUser()->getUID())['language']==='system'?$this->languages->findLanguage():$this->library->settings($this->session->getUser()->getUID())['language']];
  return $this->aiResponse($data,$embedded);
 }
 private function aiResponse(array $data,int $embedded):TemplateResponse {
  $data['embedded']=$embedded===1;
  if($embedded===1){
   $data['assets']=$this->url->linkTo('nextgallery','');
   $data['version']=$this->apps->getAppVersion('nextgallery');
   $data['routeRoot']=rtrim($this->url->linkToRoute('nextgallery.page.index'),'/');
  }
  $response=$embedded===1
   ?new StandaloneTemplateResponse('nextgallery','ai-standalone',$data,TemplateResponse::RENDER_AS_BLANK,200,['Cache-Control'=>'private, no-store'])
   :new TemplateResponse('nextgallery','ai-index',$data);
  if($embedded===1){$policy=new ContentSecurityPolicy();$policy->addAllowedFrameAncestorDomain("'self'");$response->setContentSecurityPolicy($policy);}
  return $response;
 }
 #[NoAdminRequired] #[NoCSRFRequired]
 public function index():Response {
  if(!$this->request->isUserAgent([IRequest::USER_AGENT_CLIENT_ANDROID]) && $this->groups->isAdmin($this->session->getUser()->getUID()))return new RedirectResponse($this->url->linkToRoute('nextgallery.page.ai').'#settings');
  return $this->gallery();
 }
 #[NoAdminRequired] #[NoCSRFRequired]
 public function gallery():TemplateResponse {
  $data=$this->data(false);
  // The Android WebView has a client UA, which the core browser guard rejects.
  $response=$data['context']['embedded']
   ?new StandaloneTemplateResponse('nextgallery','gallery-standalone',$data,TemplateResponse::RENDER_AS_BLANK,200,['Cache-Control'=>'private, no-store'])
   :new TemplateResponse('nextgallery','index',$data);
  $policy=new ContentSecurityPolicy();$policy->addAllowedWorkerSrcDomain("'self'");$policy->addAllowedFrameDomain("'self'");
  $response->setContentSecurityPolicy($policy);return $response;
 }
 #[NoAdminRequired] #[NoCSRFRequired]
 public function offline():Response {
  // The blank template supplies the matching CSP nonce without loading core UI.
  $response=new StandaloneTemplateResponse('nextgallery','offline',$this->data(true),TemplateResponse::RENDER_AS_BLANK,200,['Cache-Control'=>'private, no-store']);
  $policy=new ContentSecurityPolicy();$policy->addAllowedWorkerSrcDomain("'self'");
  $response->setContentSecurityPolicy($policy);return $response;
 }
 #[NoAdminRequired] #[NoCSRFRequired]
 public function worker():Response {
  $response=new Bytes(file_get_contents(__DIR__.'/../../js/sw.js'),'application/javascript',200,['Cache-Control'=>'no-cache','Service-Worker-Allowed'=>$this->url->linkToRoute('nextgallery.page.index')]);
  $policy=new EmptyContentSecurityPolicy();$policy->addAllowedConnectDomain("'self'");
  $response->setContentSecurityPolicy($policy);return $response;
 }
}
