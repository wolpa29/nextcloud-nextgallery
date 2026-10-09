<?php
declare(strict_types=1);
namespace OCA\MediaAI\Controller;
use OCP\{IRequest};
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\{DataResponse,StreamResponse};
use OCP\AppFramework\Http\Attribute\{NoAdminRequired,NoCSRFRequired,PublicPage};
/** Forward legacy addresses with the same route arguments and authorization attributes. */
class WorkerController extends Controller {
 use Forwarding;
 public function __construct(IRequest $request,private IAppManager $apps) {parent::__construct('media_ai',$request);}

 #[PublicPage] #[NoCSRFRequired]
 public function config(): DataResponse {
  if($response=$this->unavailable()) return $response;
  return $this->delegate('Worker')->config();
 }

 #[PublicPage] #[NoCSRFRequired]
 public function inventory(int $cursor = 0): DataResponse {
  if($response=$this->unavailable()) return $response;
  return $this->delegate('Worker')->inventory($cursor);
 }

 #[PublicPage] #[NoCSRFRequired]
 public function resolve(int $file_id): DataResponse {
  if($response=$this->unavailable()) return $response;
  return $this->delegate('Worker')->resolve($file_id);
 }

 #[PublicPage] #[NoCSRFRequired]
 public function image(int $file_id, string $etag): StreamResponse|DataResponse {
  if($response=$this->unavailable()) return $response;
  return $this->delegate('Worker')->image($file_id,$etag);
 }

 #[PublicPage] #[NoCSRFRequired]
 public function reset(): DataResponse {
  if($response=$this->unavailable()) return $response;
  return $this->delegate('Worker')->reset();
 }

 #[PublicPage] #[NoCSRFRequired]
 public function publish(int $file_id, string $etag, string $sha256, string $revision, array $metadata, array $exif = []): DataResponse {
  if($response=$this->unavailable()) return $response;
  return $this->delegate('Worker')->publish($file_id,$etag,$sha256,$revision,$metadata,$exif);
 }
}
