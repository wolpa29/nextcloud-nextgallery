<?php
declare(strict_types=1);
namespace OCA\MediaAI\Controller;
use OCP\IRequest;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\OCS\OCSException;
use OCA\MediaAI\Service\{Settings,Inventory,Metadata};
class WorkerController extends Controller {
    public function __construct(IRequest $request, private Settings $settings, private Inventory $inventory, private Metadata $metadata) { parent::__construct('media_ai', $request); }
    private function authorize(): void {
        if (!$this->settings->verifyWorker($this->request->getHeader('Authorization'))) { throw new OCSException('Worker authentication required', 401); }
    }
    #[PublicPage] #[NoCSRFRequired]
    public function config(): DataResponse { $this->authorize(); return new DataResponse($this->settings->workerConfig()); }
    #[PublicPage] #[NoCSRFRequired]
    public function inventory(int $cursor = 0): DataResponse { $this->authorize(); return new DataResponse($this->inventory->page($cursor)); }
    #[PublicPage] #[NoCSRFRequired]
    public function resolve(int $file_id): DataResponse {
        $this->authorize(); $file = $this->inventory->allowedFile($file_id);
        return new DataResponse(['file_id'=>$file->getId(), 'etag'=>$file->getEtag(), 'size'=>$file->getSize()]);
    }
    #[PublicPage] #[NoCSRFRequired]
    public function image(int $file_id, string $etag): DataDownloadResponse {
        $this->authorize(); $file = $this->inventory->allowedFile($file_id);
        if (!hash_equals($file->getEtag(), $etag)) { throw new OCSException('Image changed', 409); }
        $maximum = $this->settings->get()['max_download_bytes'];
        if ($file->getSize() > $maximum) { throw new OCSException('Image exceeds download limit', 413); }
        $stream = $file->fopen('rb');
        if (!is_resource($stream)) { throw new OCSException('Image unavailable', 503); }
        try { $data = stream_get_contents($stream, $maximum + 1); } finally { fclose($stream); }
        if ($data === false || strlen($data) > $maximum || !hash_equals($file->getEtag(), $etag)) { throw new OCSException('Image changed or exceeds limit', 409); }
        $response = new DataDownloadResponse($data, 'image', $file->getMimeType());
        $response->addHeader('ETag', '"'.$file->getEtag().'"');
        $response->addHeader('Cache-Control', 'no-store');
        return $response;
    }
    #[PublicPage] #[NoCSRFRequired]
    public function publish(int $file_id, string $etag, string $sha256, string $revision, array $metadata, array $exif = []): DataResponse {
        $this->authorize(); $data = Metadata::validate($file_id,$etag,$sha256,$revision,$metadata,$exif);
        $file = $this->inventory->allowedFile($file_id);
        if (!hash_equals($file->getEtag(),$etag)) { throw new OCSException('Image changed',409); }
        $this->metadata->upsert($this->settings->get()['user_id'],$data);
        return new DataResponse(['stored'=>true,'file_id'=>$file_id]);
    }
}
