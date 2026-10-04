<?php
declare(strict_types=1);
namespace OCA\Chronicle\Controller;
use OCP\{IRequest,IUserSession,IURLGenerator,IL10N};
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\{TemplateResponse,StandaloneTemplateResponse,Response,ContentSecurityPolicy,EmptyContentSecurityPolicy};
use OCP\AppFramework\Http\Attribute\{NoAdminRequired,NoCSRFRequired};
use OCA\Chronicle\Service\Library;
use OCA\Chronicle\Http\Bytes;
class PageController extends Controller {
 public function __construct(IRequest $request,private IUserSession $session,private Library $library,private IURLGenerator $url,private IAppManager $apps,private IL10N $l10n) {parent::__construct('chronicle',$request);}
 private function data(bool $offline):array {
  $uid=$this->session->getUser()->getUID();
  return ['context'=>['base'=>$this->url->linkToRoute('chronicle.page.index'),'assets'=>$this->url->linkTo('chronicle',''),'version'=>$this->apps->getAppVersion('chronicle'),'language'=>$this->l10n->getLanguageCode(),'embedded'=>$this->request->isUserAgent([IRequest::USER_AGENT_CLIENT_ANDROID]),'cacheKey'=>$this->library->cacheKey($uid),'offline'=>$offline,'settings'=>$this->library->settings($uid)]];
 }
 #[NoAdminRequired] #[NoCSRFRequired]
 public function index():TemplateResponse {
  $response=new TemplateResponse('chronicle','index',$this->data(false));
  $policy=new ContentSecurityPolicy();$policy->addAllowedWorkerSrcDomain("'self'");
  $response->setContentSecurityPolicy($policy);return $response;
 }
 #[NoAdminRequired] #[NoCSRFRequired]
 public function offline():Response {
  // The blank template supplies the matching CSP nonce without loading core UI.
  $response=new StandaloneTemplateResponse('chronicle','offline',$this->data(true),TemplateResponse::RENDER_AS_BLANK,200,['Cache-Control'=>'private, no-store']);
  $policy=new ContentSecurityPolicy();$policy->addAllowedWorkerSrcDomain("'self'");
  $response->setContentSecurityPolicy($policy);return $response;
 }
 #[NoAdminRequired] #[NoCSRFRequired]
 public function worker():Response {
  $response=new Bytes(file_get_contents(__DIR__.'/../../js/sw.js'),'application/javascript',200,['Cache-Control'=>'no-cache','Service-Worker-Allowed'=>$this->url->linkToRoute('chronicle.page.index')]);
  $policy=new EmptyContentSecurityPolicy();$policy->addAllowedConnectDomain("'self'");
  $response->setContentSecurityPolicy($policy);return $response;
 }
}
