<?php
declare(strict_types=1);
namespace OCA\Nextgallery\Controller;
use OCP\IRequest;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\OCS\OCSException;
use OCA\Nextgallery\AI\Service\{Settings,Inventory,Metadata,State};
class AiWorkerController extends Controller {
    public function __construct(IRequest $request, private Settings $settings, private Inventory $inventory, private Metadata $metadata, private ?State $state = null, private ?\OCA\Nextgallery\AI\Service\Embeddings $embeddings = null) { parent::__construct('nextgallery', $request); }
    private function denied(): ?DataResponse {
        if (!$this->settings->verifyWorker($this->request->getHeader('Authorization'))) { return new DataResponse(['error'=>'Worker authentication required'],401); }
        $uid=$this->request->getHeader('X-Nextgallery-User');
        if($uid!==''){
            if(!\OCP\Server::get(\OCP\IUserManager::class)->userExists($uid))return new DataResponse(['error'=>'Account unavailable'],404);
            $this->settings=$this->settings->forUser($uid);$this->inventory=$this->inventory->forUser($uid);$this->state=$this->state?->forUser($uid);
        }
        return null;
    }
    #[PublicPage] #[NoCSRFRequired]
    public function config(): DataResponse { if ($denied = $this->denied()) { return $denied; } $response = new DataResponse($this->settings->workerConfig()); $response->addHeader('Cache-Control','no-store'); return $response; }
    #[PublicPage] #[NoCSRFRequired]
    public function inventory(int $cursor = 0): DataResponse { if ($denied = $this->denied()) { return $denied; } return new DataResponse($this->inventory->page($cursor)); }
    #[PublicPage] #[NoCSRFRequired]
    public function resolve(int $file_id): DataResponse {
        if ($denied = $this->denied()) { return $denied; } $file = $this->inventory->allowedFile($file_id);
        return new DataResponse(['file_id'=>$file->getId(), 'etag'=>$file->getEtag(), 'size'=>$file->getSize()]);
    }
    #[PublicPage] #[NoCSRFRequired]
    public function image(int $file_id, string $etag): StreamResponse|DataResponse {
        if ($denied = $this->denied()) { return $denied; } $file = $this->inventory->allowedFile($file_id);
        if (!hash_equals($file->getEtag(), $etag)) { throw new OCSException('Image changed', 409); }
        $config = $this->settings->get();
        $maximum = str_starts_with($file->getMimeType(), 'video/') ? $config['max_video_download_bytes'] : $config['max_download_bytes'];
        if ($file->getSize() > $maximum) { throw new OCSException('Image exceeds download limit', 413); }
        $stream = $file->fopen('rb');
        if (!is_resource($stream)) { throw new OCSException('Image unavailable', 503); }
        $response = new StreamResponse($stream);
        $response->addHeader('Content-Type', $file->getMimeType());
        $response->addHeader('Content-Length', (string)$file->getSize());
        $response->addHeader('ETag', '"'.$file->getEtag().'"');
        $response->addHeader('Cache-Control', 'no-store');
        return $response;
    }
    #[PublicPage] #[NoCSRFRequired]
    public function reset(): DataResponse {
        if($denied=$this->denied())return $denied;
        $control=$this->state?->control()??[];
        if(empty($control['reset_requested']) || empty($control['paused']))return new DataResponse(['error'=>'Explicit paused reset request required'],409);
        $uid=$this->settings->get()['user_id'];$kind=$control['reset_kind']??'all';
        if(in_array($kind,['all','descriptions'],true))$this->metadata->reset($uid);
        if(in_array($kind,['all','embeddings'],true))$this->embeddings?->reset($uid);
        return new DataResponse(['reset'=>true]);
    }
    #[PublicPage] #[NoCSRFRequired]
    public function publishEmbedding(int $file_id,string $etag,string $space,array $vectors):DataResponse {
        if($denied=$this->denied())return $denied;
        if(!$this->settings->get()['embedding']['enabled'])return new DataResponse(['error'=>'Embeddings disabled'],409);
        $file=$this->inventory->allowedFile($file_id);if(!hash_equals($file->getEtag(),$etag))throw new OCSException('Media changed',409);
        if(!$this->embeddings)throw new \RuntimeException('Embedding storage unavailable');
        $this->embeddings->publish($this->settings->get()['user_id'],$file_id,$etag,$space,$vectors);return new DataResponse(['stored'=>true]);
    }
    #[PublicPage] #[NoCSRFRequired]
    public function publish(int $file_id, string $etag, string $sha256, string $revision, array $metadata, array $exif = []): DataResponse {
        if ($denied = $this->denied()) { return $denied; } $data = Metadata::validate($file_id,$etag,$sha256,$revision,$metadata,$exif);
        $file = $this->inventory->allowedFile($file_id);
        if (!hash_equals($file->getEtag(),$etag)) { throw new OCSException('Image changed',409); }
        $this->metadata->upsert($this->settings->get()['user_id'],$data);
        return new DataResponse(['stored'=>true,'file_id'=>$file_id]);
    }
}
