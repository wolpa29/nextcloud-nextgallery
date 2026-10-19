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
    public function __construct(IRequest $request, private Settings $settings, private Inventory $inventory, private Metadata $metadata, private ?State $state = null, private ?\OCA\Nextgallery\AI\Service\Embeddings $embeddings = null, private ?\OCA\Nextgallery\AI\Service\SharedResults $shared = null) { parent::__construct('nextgallery', $request); }
    private function denied(): ?DataResponse {
        if (!$this->settings->verifyWorker($this->request->getHeader('Authorization'))) { return new DataResponse(['error'=>'Worker authentication required'],401); }
        $uid=$this->request->getHeader('X-Nextgallery-User');
        if($uid!==''){
            if(!\OCP\Server::get(\OCP\IUserManager::class)->userExists($uid))return new DataResponse(['error'=>'Account unavailable'],404);
            $this->settings=$this->settings->forUser($uid);$this->inventory=$this->inventory->forUser($uid);$this->state=$this->state?->forUser($uid);
        }
        return null;
    }
    private function sourceFile(int $id,?string $kind=null):\OCP\Files\File|DataResponse {
        try{return $this->inventory->allowedFile($id,$kind);}
        catch(\OCP\AppFramework\OCS\OCSNotFoundException|\OCP\Files\NotFoundException|\OCP\Files\NotPermittedException){return new DataResponse(['error'=>'Media unavailable'],404);}
    }
    #[PublicPage] #[NoCSRFRequired]
    public function config(): DataResponse { if ($denied = $this->denied()) { return $denied; } $response = new DataResponse($this->settings->workerConfig()); $response->addHeader('Cache-Control','no-store'); return $response; }
    #[PublicPage] #[NoCSRFRequired]
    public function inventory(int $cursor = 0,string $kind='descriptions'): DataResponse { if ($denied = $this->denied()) { return $denied; } return new DataResponse($this->inventory->page($cursor,$kind)); }
    #[PublicPage] #[NoCSRFRequired]
    public function resolve(int $file_id, string $etag = '', bool $local_media = false): DataResponse {
        if ($denied = $this->denied()) { return $denied; } $file = $this->sourceFile($file_id);if($file instanceof DataResponse)return $file;
        if ($etag !== '' && !hash_equals($file->getEtag(), $etag)) { return new DataResponse(['error'=>'Media changed'],409); }
        $data = ['file_id'=>$file->getId(), 'etag'=>$file->getEtag(), 'size'=>$file->getSize()];
        if ($local_media) {
            // Only authenticated embedding workers may read an allowed, current
            // local video directly. Do not materialize remote/encrypted storage.
            $data['local_path'] = null;
            if ($etag === '' || !$this->settings->get()['embedding']['enabled'] || !str_starts_with($file->getMimeType(), 'video/')) {
                return new DataResponse(['error'=>'Current embedding video required'],400);
            }
            $storage = $file->getStorage();
            if ($storage->isLocal() && !$file->isEncrypted()) {
                // A jailed node's internal path may omit its underlying folder.
                $entry = $storage->getCache()->get($file->getId());
                if ($entry && empty($entry['encrypted'])) {
                    $path = $storage->getLocalFile($entry->getPath());
                    $path = is_string($path) ? realpath($path) : false;
                    if ($path !== false && is_file($path) && is_readable($path) && filesize($path) === $file->getSize()) {
                        $data['local_path'] = $path;
                    }
                }
            }
        }
        $response = new DataResponse($data);$response->addHeader('Cache-Control','no-store');return $response;
    }
    #[PublicPage] #[NoCSRFRequired]
    public function image(int $file_id, string $etag): StreamResponse|DataResponse {
        if ($denied = $this->denied()) { return $denied; } $file = $this->sourceFile($file_id);if($file instanceof DataResponse)return $file;
        if (!hash_equals($file->getEtag(), $etag)) { return new DataResponse(['error'=>'Image changed'],409); }
        $config = $this->settings->get();
        $maximum = str_starts_with($file->getMimeType(), 'video/') ? $config['max_video_download_bytes'] : $config['max_download_bytes'];
        if ($file->getSize() > $maximum) { return new DataResponse(['error'=>'Image exceeds download limit'],413); }
        $stream = $file->fopen('rb');
        if (!is_resource($stream)) { return new DataResponse(['error'=>'Image unavailable'],503); }
        $response = new StreamResponse($stream);
        $response->addHeader('Content-Type', $file->getMimeType());
        $response->addHeader('Content-Length', (string)$file->getSize());
        $response->addHeader('ETag', '"'.$file->getEtag().'"');
        $response->addHeader('Cache-Control', 'no-store');
        return $response;
    }
    #[PublicPage] #[NoCSRFRequired]
    public function reset(string $kind='all'): DataResponse {
        if($denied=$this->denied())return $denied;
        if(!in_array($kind,['all','descriptions','ocr','embeddings'],true))return new DataResponse(['error'=>'Invalid reset kind'],400);
        $state=$kind==='all'?$this->state:$this->state?->forPipeline($kind);
        $control=$state?->control()??[];
        if($kind==='all'&&$state&&is_file($state->directory().'/pipelines.json'))foreach(['descriptions','ocr','embeddings'] as $lane){$c=$state->forPipeline($lane)->control();if(empty($c['reset_requested'])||empty($c['paused']))return new DataResponse(['error'=>'Explicit paused reset request required for all pipelines'],409);}
        if(empty($control['reset_requested']) || empty($control['paused']))return new DataResponse(['error'=>'Explicit paused reset request required'],409);
        $uid=$this->settings->get()['user_id'];$kind=$control['reset_kind']??$kind;
        if(in_array($kind,['all','descriptions','ocr'],true)){
            $lock=fopen($state->directory().'/publication.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new \RuntimeException('Publication gate unavailable');
            try{$this->metadata->reset($uid,$kind==='all'?null:$kind);}finally{flock($lock,LOCK_UN);fclose($lock);}
        }
        if(in_array($kind,['all','embeddings'],true))$this->embeddings?->reset($uid);
        // A reset asks for fresh inference: withdraw this account's shared contributions too.
        $this->shared?->forget($uid,$kind);
        return new DataResponse(['reset'=>true]);
    }
    /** Shared stage results only for a current file this account may process. */
    private function sharedTarget(int $file_id,string $etag,string $sha256,string $kind,string $revision):?DataResponse {
        if(!$this->shared||!$this->shared->enabled())return new DataResponse(['result'=>null,'stored'=>false,'disabled'=>true]);
        try{\OCA\Nextgallery\AI\Service\SharedResults::key($sha256,$kind,$revision);}catch(\OCP\AppFramework\OCS\OCSBadRequestException $e){return new DataResponse(['error'=>$e->getMessage()],400);}
        $config=$this->settings->get();
        $active=['vision'=>$config['analysis_enabled'],'ocr'=>$config['ocr_enabled'],'embedding'=>$config['embedding']['enabled']][$kind];
        if(!$active)return new DataResponse(['error'=>'Stage disabled'],409);
        $file=$this->sourceFile($file_id,['vision'=>'descriptions','ocr'=>'ocr','embedding'=>'embeddings'][$kind]);if($file instanceof DataResponse)return $file;
        if($etag===''||!hash_equals($file->getEtag(),$etag))return new DataResponse(['error'=>'Media changed'],409);
        return null;
    }
    #[PublicPage] #[NoCSRFRequired]
    public function sharedResult(int $file_id,string $etag,string $sha256,string $kind,string $revision):DataResponse {
        if($denied=$this->denied())return $denied;
        if($refused=$this->sharedTarget($file_id,$etag,$sha256,$kind,$revision))return $refused;
        // Descriptions/OCR: any revision, preferring the requested one. Vectors: same space only.
        $found=$this->shared->find($sha256,$kind,$revision,true);
        // Same file and version (e.g. a group mount): vectors published before sharing existed.
        if($found===null&&$kind==='embedding'&&($frames=$this->embeddings?->sameFile($file_id,$etag,$revision,$this->settings->get()['user_id'])))$found=['result'=>$frames,'revision'=>$revision,'model'=>null];
        $response=new DataResponse($found??['result'=>null]);$response->addHeader('Cache-Control','no-store');return $response;
    }
    #[PublicPage] #[NoCSRFRequired]
    public function shareResult(int $file_id,string $etag,string $sha256,string $kind,string $revision,mixed $result=null,mixed $model=null):DataResponse {
        if($denied=$this->denied())return $denied;
        if($refused=$this->sharedTarget($file_id,$etag,$sha256,$kind,$revision))return $refused;
        try{$stored=$this->shared->store($this->settings->get()['user_id'],$sha256,$kind,$revision,$result,$model);}
        catch(\OCP\AppFramework\OCS\OCSBadRequestException $e){return new DataResponse(['error'=>$e->getMessage()],400);}
        return new DataResponse(['stored'=>$stored]);
    }
    #[PublicPage] #[NoCSRFRequired]
    public function publishEmbedding(int $file_id,string $etag,string $space,array $vectors):DataResponse {
        if($denied=$this->denied())return $denied;
        if(!$this->settings->get()['embedding']['enabled'])return new DataResponse(['error'=>'Embeddings disabled'],409);
        $file=$this->sourceFile($file_id,'embeddings');if($file instanceof DataResponse)return $file;if(!hash_equals($file->getEtag(),$etag))return new DataResponse(['error'=>'Media changed'],409);
        if(!$this->embeddings)throw new \RuntimeException('Embedding storage unavailable');
        $this->embeddings->publish($this->settings->get()['user_id'],$file_id,$etag,$space,$vectors);return new DataResponse(['stored'=>true]);
    }
    #[PublicPage] #[NoCSRFRequired]
    public function publish(int $file_id, string $etag, string $sha256, string $revision, array $metadata, array $exif = [],string $stage=''): DataResponse {
        if ($denied = $this->denied()) { return $denied; } try{$data = Metadata::validate($file_id,$etag,$sha256,$revision,$metadata,$exif,$stage);}catch(\OCP\AppFramework\OCS\OCSBadRequestException $e){return new DataResponse(['error'=>$e->getMessage()],400);}
        $file = $this->sourceFile($file_id,$stage===''?null:$stage);if($file instanceof DataResponse)return $file;
        if (!hash_equals($file->getEtag(),$etag)) { return new DataResponse(['error'=>'Image changed'],409); }
        if($stage!==''&&!$this->state)throw new \RuntimeException('Publication state unavailable');
        $lock=$this->state?fopen($this->state->directory().'/publication.lock','c'):null;
        if($this->state&&!$lock)throw new \RuntimeException('Publication gate unavailable');
        if($lock&&!flock($lock,LOCK_EX))throw new \RuntimeException('Publication gate unavailable');
        try{$this->metadata->upsert($this->settings->get()['user_id'],$data,$stage);}finally{if($lock){flock($lock,LOCK_UN);fclose($lock);}}
        return new DataResponse(['stored'=>true,'file_id'=>$file_id]);
    }
}
