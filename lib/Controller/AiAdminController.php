<?php
declare(strict_types=1);
namespace OCA\Nextgallery\Controller;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IGroupManager;
use OCP\Files\{IRootFolder,Folder};
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCA\Nextgallery\AI\Service\{Settings,State};
class AiAdminController extends Controller {
    public function __construct(IRequest $request, private Settings $settings, private State $state, private IUserSession $session, private IGroupManager $groups, private IRootFolder $root, private ?\OCA\Nextgallery\AI\Service\Metadata $metadata = null, private ?\OCA\Nextgallery\AI\Service\Files $files = null, private ?\OCA\Nextgallery\AI\Service\ModelConnection $modelConnection = null, private ?\OCA\Nextgallery\AI\Service\Library $library = null) { parent::__construct('nextgallery',$request); }
    private function admin(): bool { $uid=$this->session->getUser()?->getUID(); return $uid!==null && $this->groups->isAdmin($uid); }
    private function denied(): ?DataResponse {
        $uid=$this->session->getUser()?->getUID();
        return $uid!==null && ($this->admin() || $uid===$this->settings->get()['user_id']) ? null : new DataResponse(['error'=>'Diese Pipeline gehört einem anderen Benutzer'],403);
    }
    private function publicConfig(): array { return $this->settings->publicConfig()+['can_configure_models'=>$this->admin()]; }
    #[NoAdminRequired]
    public function config(): DataResponse { if($denied=$this->denied())return $denied; return new DataResponse($this->publicConfig()); }
    #[NoAdminRequired]
    public function save(array $config): DataResponse {
        if($denied=$this->denied())return $denied;
        if (($this->state->status()['state'] ?? '') === 'running') {
            return new DataResponse(['error'=>'Bitte zuerst pausieren und das Ende der aktuellen Datei abwarten.'],409);
        }
        if (!$this->admin()) {
            $safe=$this->settings->get();
            foreach(['enabled','folders','exclude_folders','schedule_time','timezone','image_timeout_seconds','log_days','max_download_bytes','max_image_pixels','max_video_download_bytes','video'] as $key) { if(array_key_exists($key,$config))$safe[$key]=$config[$key]; }
            $config=$safe;
        }
        try {
            $this->settings->save($config,function(array $value): void {
                $home=$this->root->getUserFolder($value['user_id']);
                foreach(array_merge($value['folders'],$value['exclude_folders']) as $path){$node=$path==='/'?$home:$home->get(ltrim($path,'/'));if(!$node instanceof Folder || !$node->isReadable())throw new \OCP\Files\NotFoundException('Folder unavailable');}
            });
        } catch (\OCP\AppFramework\OCS\OCSBadRequestException $e) {return new DataResponse(['error'=>$e->getMessage()],400);}
        catch (\OCP\Files\NotFoundException|\OCP\Files\NotPermittedException $e) {return new DataResponse(['error'=>'Ein Ordner fehlt oder ist nicht lesbar.'],400);}
        return new DataResponse($this->publicConfig());
    }
    #[NoAdminRequired]
    public function library(string $path = '', string $filter = 'all', string $query = '', int $offset = 0): DataResponse {
        if ($denied=$this->denied()) { return $denied; }
        try { return new DataResponse($this->library->browse($path, $filter, $query, $offset)); }
        catch (\OCP\AppFramework\OCS\OCSBadRequestException $e) { return new DataResponse(['error'=>$e->getMessage()],400); }
    }
    #[NoAdminRequired]
    public function status(): DataResponse { if($denied=$this->denied())return $denied; return new DataResponse($this->state->status()); }
    #[NoAdminRequired]
    public function control(string $action): DataResponse {
        if($denied=$this->denied())return $denied;
        try { return new DataResponse($this->state->setControl($action, true)); }
        catch (\OCP\AppFramework\OCS\OCSBadRequestException $e) { return new DataResponse(['error'=>$e->getMessage()],409); }
    }
    #[NoAdminRequired]
    public function logs(string $date, int $cursor = 0): DataResponse { if($denied=$this->denied())return $denied; return new DataResponse($this->state->logs($date,$cursor)); }
    #[NoAdminRequired]
    public function folders(string $path = '/'): DataResponse {
        if($denied=$this->denied())return $denied;
        if(str_contains($path,"\0") || str_contains($path,'\\') || preg_match('~(^|/)(\.|\.\.)(/|$)~',$path))return new DataResponse(['error'=>'Ungültiger Pfad'],400);
        $home=$this->root->getUserFolder($this->settings->get()['user_id']);
        $folder=$path==='/'?$home:$home->get(ltrim($path,'/'));
        if(!$folder instanceof Folder || !$folder->isReadable())return new DataResponse(['error'=>'Ordner nicht verfügbar'],404);
        $rows=[];
        foreach($folder->getDirectoryListing() as $child){if($child instanceof Folder && $child->isReadable())$rows[]=['name'=>$child->getName(),'path'=>$home->getRelativePath($child->getPath())];}
        usort($rows,fn($a,$b)=>strnatcasecmp($a['name'],$b['name']));
        return new DataResponse(['path'=>$path,'folders'=>$rows]);
    }
    #[NoAdminRequired]
    public function result(int $file_id): DataResponse {
        if($denied=$this->denied())return $denied;
        try { $file=$this->files->byId($this->settings->get()['user_id'],$file_id); }
        catch(\OCP\AppFramework\OCS\OCSNotFoundException|\OCP\Files\NotFoundException|\OCP\Files\NotPermittedException $e){return new DataResponse(['available'=>false],404);}
        $row=$this->metadata->get($this->settings->get()['user_id'],$file_id);
        if(!$row || !hash_equals($row['etag'],$file->getEtag()))return new DataResponse(['available'=>false]);
        return new DataResponse(['available'=>true,'updated_at'=>$row['updated_at'],'metadata'=>json_decode($row['metadata_json'],true,64,JSON_THROW_ON_ERROR)]);
    }
    #[NoAdminRequired]
    public function maintenance(string $action, string $confirmation): DataResponse {
        if($denied=$this->denied())return $denied;
        if($confirmation!==$action || !in_array($action,['clear_logs','reset_ai'],true))return new DataResponse(['error'=>'Bestätigung fehlt'],400);
        $status=$this->state->status();
        if(!($status['control']['paused']??true) || $status['state']==='running')return new DataResponse(['error'=>'Bitte zuerst pausieren und das Ende der aktuellen Datei abwarten.'],409);
        if($action==='clear_logs')return new DataResponse(['cleared'=>$this->state->clearLogs()]);
        if(!$status['online'])return new DataResponse(['error'=>'Worker ist offline. Reset erst nach dem Start möglich.'],409);
        $this->state->setControl('reset');return new DataResponse(['requested'=>true]);
    }
    #[NoAdminRequired]
    public function test(): DataResponse {
        if ($this->session->getUser() === null) { return new DataResponse(['error'=>'Anmeldung erforderlich'],401); }
        // Every signed-in user may inspect model readiness, without configuration access.
        $connection = $this->modelConnection ?? new \OCA\Nextgallery\AI\Service\ModelConnection();
        return new DataResponse($connection->inspect($this->settings->workerConfig()));
    }
}
