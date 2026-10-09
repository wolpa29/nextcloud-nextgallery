<?php
declare(strict_types=1);
namespace OCA\MediaAI\Controller;
use OCP\{IRequest};
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\{DataResponse,StreamResponse};
use OCP\AppFramework\Http\Attribute\{NoAdminRequired,NoCSRFRequired,PublicPage};
/** Forward legacy addresses with the same route arguments and authorization attributes. */
class PreferencesController extends Controller {
 use Forwarding;
 public function __construct(IRequest $request,private IAppManager $apps) {parent::__construct('media_ai',$request);}

 #[NoAdminRequired] #[NoCSRFRequired]
 public function get(): DataResponse {
  if($response=$this->unavailable()) return $response;
  return $this->delegate('Preferences')->get();
 }

 #[NoAdminRequired]
 public function save(string $language): DataResponse {
  if($response=$this->unavailable()) return $response;
  return $this->delegate('Preferences')->save($language);
 }
}
