<?php
declare(strict_types=1);
namespace OCA\MediaAI\Controller;
use OCP\{IRequest};
use OCP\App\IAppManager;
use OCP\AppFramework\OCSController;
use OCP\AppFramework\Http\{DataResponse,StreamResponse};
use OCP\AppFramework\Http\Attribute\{NoAdminRequired,NoCSRFRequired,PublicPage};
/** Forward legacy addresses with the same route arguments and authorization attributes. */
class MetadataController extends OCSController {
 use Forwarding;
 public function __construct(IRequest $request,private IAppManager $apps) {parent::__construct('media_ai',$request);}

 #[NoAdminRequired]
    #[NoCSRFRequired]
 public function resolve(string $path): DataResponse {
  if($response=$this->unavailable()) return $response;
  return $this->delegate('Metadata')->resolve($path);
 }

 #[NoAdminRequired]
    #[NoCSRFRequired]
 public function show(int $file_id): DataResponse {
  if($response=$this->unavailable()) return $response;
  return $this->delegate('Metadata')->show($file_id);
 }

 #[NoAdminRequired]
    #[NoCSRFRequired]
 public function statuses(array $file_ids): DataResponse {
  if($response=$this->unavailable()) return $response;
  return $this->delegate('Metadata')->statuses($file_ids);
 }

 #[NoAdminRequired]
    #[NoCSRFRequired]
 public function upsert(int $file_id, string $etag, string $sha256, string $revision, array $metadata, array $exif = []): DataResponse {
  if($response=$this->unavailable()) return $response;
  return $this->delegate('Metadata')->upsert($file_id,$etag,$sha256,$revision,$metadata,$exif);
 }
}
