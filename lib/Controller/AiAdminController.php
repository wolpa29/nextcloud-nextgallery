<?php
declare(strict_types=1);
namespace OCA\Nextgallery\Controller;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IGroupManager;
use OCP\Files\{IRootFolder,Folder};
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCA\Nextgallery\AI\Service\{Settings,State};
class AiAdminController extends Controller {
    public function __construct(IRequest $request, private Settings $settings, private State $state, private IUserSession $session, private IGroupManager $groups, private IRootFolder $root, private ?\OCA\Nextgallery\AI\Service\Metadata $metadata = null, private ?\OCA\Nextgallery\AI\Service\Files $files = null, private ?\OCA\Nextgallery\AI\Service\ModelConnection $modelConnection = null, private ?\OCA\Nextgallery\AI\Service\Library $library = null, private ?\OCA\Nextgallery\AI\Service\Embeddings $embeddings = null, private ?\OCA\Nextgallery\AI\Service\EmbeddingClient $embeddingClient = null,private ?\OCA\Nextgallery\Service\SearchIndex $searchIndex=null) { parent::__construct('nextgallery',$request); }
    private function admin(): bool { $uid=$this->session->getUser()?->getUID(); return $uid!==null && $this->groups->isAdmin($uid); }
    private function denied(): ?DataResponse {
        $uid=$this->session->getUser()?->getUID();
        return $uid!==null && $this->admin() ? null : new DataResponse(['error'=>'Nur Administratoren dürfen diese Einstellungen öffnen.'],403);
    }
    private function publicConfig(): array {
        $accounts=$this->embeddings?->accounts()??[];$total=0;
        foreach($accounts as $uid)$total+=$this->embeddings->count($uid);
        return $this->settings->publicConfig()+['can_configure_models'=>$this->admin(),'embedding_count'=>$this->embeddings?->count($this->settings->get()['user_id'])??0,'embedding_total_count'=>$total,'embedding_accounts_count'=>count($accounts),'metadata_count'=>$this->metadata?->count($this->settings->get()['user_id'])??0,'search_index'=>$this->searchIndex?->status($this->session->getUser()->getUID())];
    }
    public function config(): DataResponse { if($denied=$this->denied())return $denied; return new DataResponse($this->publicConfig()); }
    public function save(array $config,string $embedding_change_id='',string $embedding_change_mode='',bool $embedding_auto_rebuild=false): DataResponse {
        if($denied=$this->denied())return $denied;
        $previous=$this->settings->get();
        if(($config['user_id']??null)!==$previous['user_id'])return new DataResponse(['error'=>'Die Verarbeitungsauswahl gehört zum eigenen Benutzerkonto.'],400);
        if($this->embeddings){
            try{$result=$this->processing()->save($config,$embedding_change_id,$embedding_change_mode,$embedding_auto_rebuild);return new DataResponse($this->publicConfig()+['model_change'=>$result]);}
            catch(\OCP\AppFramework\OCS\OCSBadRequestException $e){return new DataResponse(['error'=>$e->getMessage()],409);}
        }
        if($this->state->anyRunning())return new DataResponse(['error'=>'Bitte zuerst pausieren und das Ende der aktuellen Datei abwarten.'],409);
        $confirmationMissing=false;
        try {
            $this->settings->save($config,function(array $candidate)use($previous,$embedding_change_id,&$confirmationMissing){
                if(Settings::embeddingSignature($previous)!==Settings::embeddingSignature($candidate)&&($this->embeddings?->accounts()??[])&&!hash_equals(Settings::embeddingSignature($previous),$embedding_change_id)){
                    $confirmationMissing=true;throw new \OCP\AppFramework\OCS\OCSBadRequestException('Modellwechsel bitte erneut bestätigen. Die Einstellungen haben sich geändert.');
                }
            });
        } catch (\OCP\AppFramework\OCS\OCSBadRequestException $e) {return new DataResponse(['error'=>$e->getMessage()],$confirmationMissing?409:400);}
        catch (\OCP\Files\NotFoundException|\OCP\Files\NotPermittedException $e) {return new DataResponse(['error'=>'Ein Ordner fehlt oder ist nicht lesbar.'],400);}
        $current=$this->settings->get();
        if(Settings::embeddingSignature($previous)!==Settings::embeddingSignature($current))foreach($this->embeddings?->accounts()??[] as $uid){$account=$this->settings->forUser($uid)->get();$this->state->forUser($uid)->requireEmbeddingRebuild(Settings::embeddingSignature($account),['model'=>$current['embedding']['model'],'file_count'=>$this->embeddings->count($uid)]);}
        return new DataResponse($this->publicConfig());
    }
    private function processing():\OCA\Nextgallery\AI\Service\ProcessingAdministration {
        if(!$this->embeddings)throw new \RuntimeException('Embedding storage unavailable');
        return new \OCA\Nextgallery\AI\Service\ProcessingAdministration($this->settings,$this->state,$this->embeddings,$this->embeddingClient??new \OCA\Nextgallery\AI\Service\EmbeddingClient());
    }
    public function processingStatus():DataResponse {if($denied=$this->denied())return $denied;return new DataResponse($this->processing()->overview());}
    public function processingControl(string $action,string $kind='embeddings'):DataResponse {
        if($denied=$this->denied())return $denied;
        try{return new DataResponse(match($action){'pause_all'=>$this->processing()->pauseAll($kind),'allow_runs'=>$this->processing()->allowRuns($kind),default=>throw new \OCP\AppFramework\OCS\OCSBadRequestException('Unbekannte Aktion')});}
        catch(\OCP\AppFramework\OCS\OCSBadRequestException $e){return new DataResponse(['error'=>$e->getMessage()],409);}
    }
    public function embeddingChangePreview(array $config):DataResponse {
        if($denied=$this->denied())return $denied;
        try{return new DataResponse($this->processing()->preview($config));}
        catch(\OCP\AppFramework\OCS\OCSBadRequestException $e){return new DataResponse(['error'=>$e->getMessage()],400);}
    }
    public function library(string $path = '', string $filter = 'all', string $query = '', int $offset = 0): DataResponse {
        if ($denied=$this->denied()) { return $denied; }
        try { return new DataResponse($this->library->browse($path, $filter, $query, $offset)); }
        catch (\OCP\AppFramework\OCS\OCSBadRequestException $e) { return new DataResponse(['error'=>$e->getMessage()],400); }
    }
    public function status(): DataResponse { if($denied=$this->denied())return $denied; return new DataResponse($this->state->status()); }
    public function facesStatus():DataResponse {
        if($denied=$this->denied())return $denied;
        $path=$this->state->rootDirectory().'/faces-setup.json';
        $value=is_file($path)&&!is_link($path)&&filesize($path)<1048576?json_decode((string)file_get_contents($path),true):[];
        return new DataResponse((is_array($value)?$value:[])+['phase'=>'not_installed','installed'=>false,'ready'=>false,'message'=>'Personenerkennung noch nicht installiert']);
    }
    public function facesInstall():DataResponse {
        if($denied=$this->denied())return $denied;
        $status=$this->state->forPipeline('faces')->status();
        if(!$status['online'])return new DataResponse(['error'=>'Bitte den aktualisierten Worker zuerst starten.'],409);
        $this->state->atomic('faces-install.json',['requested'=>true,'request_id'=>bin2hex(random_bytes(12)),'updated_at'=>time()]);
        return new DataResponse(['requested'=>true]);
    }
    public function embeddingInstall():DataResponse {
        if(!$this->admin())return new DataResponse(['error'=>'Nur Nextcloud-Administratoren dürfen Gemma installieren.'],403);
        $embedding=$this->settings->get()['embedding'];
        if($embedding['location']!=='local'||$embedding['family']!=='gemma2'||!$embedding['local_auto_setup'])return new DataResponse(['error'=>'Bitte zuerst Gemma 2 auf dem Nextcloud-Host auswählen und speichern.'],409);
        try {return new DataResponse($this->state->requestEmbeddingInstall($embedding['local_profile']??'lite'));}
        catch(\OCP\AppFramework\OCS\OCSBadRequestException $e){return new DataResponse(['error'=>$e->getMessage()],409);}
    }
    public function embeddingRemove(string $profile):DataResponse {
        if(!$this->admin())return new DataResponse(['error'=>'Nur Nextcloud-Administratoren dürfen Modelle entfernen.'],403);
        try{return new DataResponse($this->state->requestEmbeddingRemove($profile));}
        catch(\OCP\AppFramework\OCS\OCSBadRequestException $e){return new DataResponse(['error'=>$e->getMessage()],409);}
    }
    public function control(string $action,string $embedding_rebuild_id='',string $kind='both'): DataResponse {
        if($denied=$this->denied())return $denied;
        try { return new DataResponse($this->state->setControl($action,true,$embedding_rebuild_id,$kind)); }
        catch (\OCP\AppFramework\OCS\OCSBadRequestException $e) { return new DataResponse(['error'=>$e->getMessage()],409); }
    }
    public function logs(string $date, int $cursor = 0): DataResponse { if($denied=$this->denied())return $denied; return new DataResponse($this->state->logs($date,$cursor)); }
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
    public function result(int $file_id): DataResponse {
        if($denied=$this->denied())return $denied;
        try { $file=$this->files->byId($this->settings->get()['user_id'],$file_id); }
        catch(\OCP\AppFramework\OCS\OCSNotFoundException|\OCP\Files\NotFoundException|\OCP\Files\NotPermittedException $e){return new DataResponse(['available'=>false],404);}
        $row=$this->metadata->get($this->settings->get()['user_id'],$file_id);
        if(!$row)return new DataResponse(['available'=>false]);
        return new DataResponse(['available'=>true,'stale'=>!hash_equals($row['etag'],$file->getEtag()),'updated_at'=>$row['updated_at'],'metadata'=>json_decode($row['metadata_json'],true,64,JSON_THROW_ON_ERROR)]);
    }
    public function maintenance(string $action, string $confirmation, int $expected_count = -1): DataResponse {
        if($denied=$this->denied())return $denied;
        if($confirmation!==$action || !in_array($action,['clear_logs','reset_ai'],true))return new DataResponse(['error'=>'Bestätigung fehlt'],400);
        $status=$this->state->status();
        if(!($status['control']['paused']??true) || $status['state']==='running')return new DataResponse(['error'=>'Bitte zuerst pausieren und das Ende der aktuellen Datei abwarten.'],409);
        if($action==='clear_logs')return new DataResponse(['cleared'=>$this->state->clearLogs()]);
        if(!$status['online'])return new DataResponse(['error'=>'Worker ist offline. Reset erst nach dem Start möglich.'],409);
        if($expected_count!==$this->metadata->count($this->settings->get()['user_id']))return new DataResponse(['error'=>'Die Anzahl der Analysen hat sich geändert. Bitte die Löschbestätigung erneut öffnen.'],409);
        $this->state->setControl('reset');return new DataResponse(['requested'=>true]);
    }
    public function embeddingTest(array $config=[]):DataResponse {
        if($denied=$this->denied())return $denied;
        try {
            if($this->embeddings)return new DataResponse($this->processing()->inspect($config?:$this->settings->get()));
            return new DataResponse(($this->embeddingClient??new \OCA\Nextgallery\AI\Service\EmbeddingClient())->inspect($this->settings->probeEmbedding($config)));
        }catch(\OCP\AppFramework\OCS\OCSBadRequestException $e){return new DataResponse(['error'=>$e->getMessage()],400);}
    }
    public function test(array $config=[]): DataResponse {
        if ($this->session->getUser() === null) { return new DataResponse(['error'=>'Anmeldung erforderlich'],401); }
        if($denied=$this->denied())return $denied;
        $connection = $this->modelConnection ?? new \OCA\Nextgallery\AI\Service\ModelConnection();
        try{return new DataResponse($connection->inspect($this->settings->probeConfig($config)));}
        catch(\OCP\AppFramework\OCS\OCSBadRequestException $e){return new DataResponse(['error'=>$e->getMessage()],400);}
    }
    public function accounts(string $query=''):DataResponse {
        if($denied=$this->denied())return $denied;
        $rows=[];foreach(\OCP\Server::get(\OCP\IUserManager::class)->search(mb_substr($query,0,100),50) as $user)$rows[]=['id'=>$user->getUID(),'name'=>$user->getDisplayName()];
        return new DataResponse(['accounts'=>$rows]);
    }
    private function availableAccount(string $uid):void {if(!\OCP\Server::get(\OCP\IUserManager::class)->userExists($uid))throw new \OCP\AppFramework\OCS\OCSBadRequestException('Benutzer nicht gefunden');}
    public function accountConfig(string $user_id):DataResponse {
        if($denied=$this->denied())return $denied;
        try{$this->availableAccount($user_id);$value=$this->settings->forUser($user_id)->get();return new DataResponse(array_intersect_key($value,array_flip(['user_id','folders','exclude_folders','folder_stages','enabled','schedule_time','timezone'])));}
        catch(\OCP\AppFramework\OCS\OCSBadRequestException $e){return new DataResponse(['error'=>$e->getMessage()],400);}
    }
    public function saveAccount(string $user_id,array $config):DataResponse {
        if($denied=$this->denied())return $denied;
        try{
            $this->availableAccount($user_id);$state=$this->state->forUser($user_id);$status=$state->status();
            if(($status['state']??'')==='running'||!empty($status['control']['requested']))return new DataResponse(['error'=>'Bitte zuerst die Verarbeitung dieses Benutzers pausieren.'],409);
            $value=$this->settings->saveAccount($user_id,$config,function(array $value){$home=$this->root->getUserFolder($value['user_id']);foreach(array_merge($value['folders'],$value['exclude_folders']) as $path){$node=$path==='/'?$home:$home->get(ltrim($path,'/'));if(!$node instanceof Folder||!$node->isReadable())throw new \OCP\Files\NotFoundException('Folder unavailable');}});
            $state->registerAccount();return new DataResponse($value);
        }catch(\OCP\AppFramework\OCS\OCSBadRequestException|\OCP\Files\NotFoundException|\OCP\Files\NotPermittedException $e){return new DataResponse(['error'=>'Benutzer, Ordner oder Zeitplan ungültig.'],400);}
    }
}
