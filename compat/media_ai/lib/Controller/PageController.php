<?php
declare(strict_types=1);
namespace OCA\MediaAI\Controller;
use OCP\{IRequest,IURLGenerator};
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\{RedirectResponse,Response,DataResponse};
use OCP\AppFramework\Http\Attribute\{NoAdminRequired,NoCSRFRequired};
class PageController extends Controller {
 public function __construct(IRequest $request,private IURLGenerator $url,private IAppManager $apps) {parent::__construct('media_ai',$request);}
 #[NoAdminRequired] #[NoCSRFRequired]
 public function index():Response {if(!$this->apps->isEnabledForUser('chronicle'))return new DataResponse(['error'=>'NextGallery Server must be enabled.'],503);return new RedirectResponse($this->url->linkToRoute('chronicle.page.ai'));}
}
