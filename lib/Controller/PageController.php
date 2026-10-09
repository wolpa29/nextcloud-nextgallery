<?php
declare(strict_types=1);
namespace OCA\Nextgallery\Controller;
use OCP\{IRequest,IUserSession,IURLGenerator,IConfig,IGroupManager};
use OCP\L10N\IFactory;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\{TemplateResponse,StandaloneTemplateResponse,Response,ContentSecurityPolicy,EmptyContentSecurityPolicy};
use OCP\AppFramework\Http\Attribute\{NoAdminRequired,NoCSRFRequired};
use OCA\Nextgallery\Service\Library;
use OCA\Nextgallery\Http\Bytes;
class PageController extends Controller {
 public function __construct(IRequest $request,private IUserSession $session,private Library $library,private IURLGenerator $url,private IAppManager $apps,private IFactory $languages,private IConfig $config,private IGroupManager $groups,private \OCA\Nextgallery\AI\Service\Settings $aiSettings) {parent::__construct('nextgallery',$request);}
 private function canManageAi():bool { $uid=$this->session->getUser()?->getUID();return $uid!==null && ($this->groups->isAdmin($uid) || $uid===$this->aiSettings->get()['user_id']); }
 private function data(bool $offline):array {
  $uid=$this->session->getUser()->getUID();
  // Resolve the user's Nextcloud language independently of app l10n files.
  // This frontend supplies its own translations; app-specific IL10N would
  // otherwise fall back to English when no Nextcloud l10n/de.json exists.
  return ['context'=>['base'=>$this->url->linkToRoute('nextgallery.page.index'),'aiUrl'=>!$offline && $this->canManageAi()?$this->url->linkToRoute('nextgallery.page.ai'):null,'assets'=>$this->url->linkTo('nextgallery',''),'sourceUrl'=>$this->config->getAppValue('nextgallery','source_url',''),'version'=>$this->apps->getAppVersion('nextgallery'),'language'=>$this->library->settings($uid)['language']==='system'?$this->languages->findLanguage():$this->library->settings($uid)['language'],'embedded'=>$this->request->isUserAgent([IRequest::USER_AGENT_CLIENT_ANDROID]),'accountKey'=>$this->library->cacheKey($uid,'default'),'cacheKey'=>$this->library->cacheKey($uid),'offline'=>$offline,'settings'=>$this->library->settings($uid)]];
 }
 #[NoAdminRequired] #[NoCSRFRequired]
 public function ai():Response {
  if (!$this->canManageAi()) return new \OCP\AppFramework\Http\JSONResponse(['error'=>'KI-Verarbeitung ist für dieses Konto nicht freigegeben.'],403);
  return new TemplateResponse('nextgallery','ai-index',['galleryUrl'=>$this->url->linkToRoute('nextgallery.page.index'),'appearance'=>$this->library->settings($this->session->getUser()->getUID())['appearance'],'language'=>$this->library->settings($this->session->getUser()->getUID())['language']==='system'?$this->languages->findLanguage():$this->library->settings($this->session->getUser()->getUID())['language']]);
 }
 #[NoAdminRequired] #[NoCSRFRequired]
 public function index():TemplateResponse {
  $response=new TemplateResponse('nextgallery','index',$this->data(false));
  $policy=new ContentSecurityPolicy();$policy->addAllowedWorkerSrcDomain("'self'");
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
