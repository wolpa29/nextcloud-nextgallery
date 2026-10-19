<?php
declare(strict_types=1);
namespace OCA\Nextgallery\Controller;
use OCP\{IRequest,IUserSession};
use OCP\Files\{IRootFolder,Folder};
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCA\Nextgallery\AI\Service\{Settings,State,Library,Metadata,Embeddings,Files};

/** Account identity always comes from the authenticated session, never a form. */
class AiAccountController extends Controller {
    public function __construct(IRequest $request,private IUserSession $session,private Settings $settings,private State $state,private Library $library,private Metadata $metadata,private Embeddings $embeddings,private Files $files,private IRootFolder $root,private ?\OCA\Nextgallery\Service\SearchIndex $searchIndex=null){parent::__construct('nextgallery',$request);}
    private function uid():string { $user=$this->session->getUser();if(!$user)throw new \OCP\AppFramework\OCS\OCSException('Anmeldung erforderlich',401);return $user->getUID(); }
    private function account():State {$state=$this->state->forUser($this->uid());$state->registerAccount();return $state;}
    #[NoAdminRequired]
    public function config():DataResponse {
        $uid=$this->uid();$config=$this->settings->forUser($uid)->get();$own=$this->account();$counts=$this->metadata->counts($uid);
        $lane=$own->forPipeline('embeddings')->status();$rebuild=$lane['embedding_rebuild']??[];
        $space=$rebuild['target_space']??$rebuild['approved_space']??$lane['embedding_space']??$lane['last_run']['embedding']['space']??null;
        $embeddingTotal=$this->embeddings->count($uid);
        return new DataResponse(['user_id'=>$uid,'folders'=>$config['folders'],'exclude_folders'=>$config['exclude_folders'],'folder_stages'=>$config['folder_stages'],
            'analysis_enabled'=>$config['analysis_enabled'],'ocr_enabled'=>$config['ocr_enabled'],'embedding_enabled'=>$config['embedding']['enabled'],
            'search_index'=>$this->searchIndex?->status($uid),
            'metadata_count'=>$counts['metadata'],'description_count'=>$counts['descriptions'],'ocr_count'=>$counts['ocr'],'embedding_count'=>$embeddingTotal,
            'embedding_current_count'=>is_string($space)&&preg_match('/^[a-f0-9]{64}$/D',$space)?$this->embeddings->count($uid,$space):null,
            'schedule'=>['enabled'=>$config['enabled'],'time'=>$config['schedule_time'],'timezone'=>$config['timezone']]]);
    }
    /** Only personal selection and schedule are writable; model options remain admin-only. */
    #[NoAdminRequired]
    public function saveConfig(array $config):DataResponse {
        $state=$this->account();$state->initializePipelines();$lock=fopen($state->directory().'/control.lock','c');
        if(!$lock||!flock($lock,LOCK_EX))throw new \RuntimeException('Verarbeitungseinstellungen konnten nicht gesperrt werden');
        try{
            $status=$state->status();
            if($status['state']==='running'||!empty($status['control']['requested'])||!empty($status['control']['reset_requested']))return new DataResponse(['error'=>'Bitte zuerst pausieren und das Ende der aktuellen Verarbeitung abwarten.'],409);
            $uid=$this->uid();$home=$this->root->getUserFolder($uid);
            $this->settings->saveAccount($uid,$config,function(array $candidate)use($home){
                foreach(array_merge($candidate['folders'],$candidate['exclude_folders']) as $path){$folder=$path==='/'?$home:$home->get(ltrim($path,'/'));if(!$folder instanceof Folder||!$folder->isReadable())throw new \OCP\AppFramework\OCS\OCSBadRequestException('Ein Ordner fehlt oder ist nicht lesbar.');}
            });
            return $this->config();
        }catch(\OCP\AppFramework\OCS\OCSBadRequestException|\OCP\Files\NotFoundException|\OCP\Files\NotPermittedException $e){return new DataResponse(['error'=>'Ordnerauswahl oder Zeitplan ungültig. Bitte die Eingaben prüfen.'],400);}
        finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    #[NoAdminRequired]
    public function folders(string $path='/'):DataResponse {
        if(strlen($path)>2000||str_contains($path,"\0")||str_contains($path,'\\')||preg_match('~(^|/)(\.|\.\.)(/|$)~',$path))return new DataResponse(['error'=>'Ungültiger Ordnerpfad'],400);
        try{
            $home=$this->root->getUserFolder($this->uid());$folder=trim($path,'/')===''?$home:$home->get(trim($path,'/'));
            if(!$folder instanceof Folder||!$folder->isReadable())return new DataResponse(['error'=>'Ordner nicht verfügbar'],404);
            $rows=[];foreach($folder->getDirectoryListing() as $child)if($child instanceof Folder&&$child->isReadable())$rows[]=['name'=>$child->getName(),'path'=>'/'.trim($home->getRelativePath($child->getPath()),'/')];
            usort($rows,fn($a,$b)=>strnatcasecmp($a['name'],$b['name']));return new DataResponse(['path'=>'/'.trim($path,'/'),'folders'=>$rows]);
        }catch(\OCP\Files\NotFoundException|\OCP\Files\NotPermittedException $e){return new DataResponse(['error'=>'Ordner nicht verfügbar'],404);}
    }
    #[NoAdminRequired]
    public function status():DataResponse {
        $own=$this->account();$own->initializePipelines();$status=$own->status();
        $clean=function(array $value):array {
            foreach(['active_user_id','embedding_models','embedding_install_requested','embedding_install_profile','embedding_remove','embedding_setup_supported','embedding_setup_profiles','embedding_remove_supported','embedding_service'] as $key)unset($value[$key]);
            if(isset($value['embedding_rebuild']))$value['embedding_rebuild']=array_intersect_key($value['embedding_rebuild'],array_flip(['required','request_id','file_count','old_dimensions','new_dimensions']));
            return $value;
        };
        $status=$clean($status);foreach($status['pipelines']??[] as $kind=>$lane)$status['pipelines'][$kind]=$clean($lane);
        $status['server_time']=microtime(true);
        return new DataResponse($status);
    }
    #[NoAdminRequired]
    public function library(string $path='',string $filter='all',string $query='',int $offset=0,string $embedding_filter='all',string $ocr_filter='all'):DataResponse {
        try{return new DataResponse($this->library->forUser($this->uid())->browse($path,$filter,$query,$offset,$embedding_filter,$ocr_filter));
        }catch(\OCP\AppFramework\OCS\OCSBadRequestException $e){return new DataResponse(['error'=>$e->getMessage()],400);}
    }
    #[NoAdminRequired]
    public function inventory():DataResponse {
        try{return new DataResponse($this->library->forUser($this->uid())->counts());
        }catch(\OCP\AppFramework\OCS\OCSBadRequestException $e){return new DataResponse(['error'=>$e->getMessage()],400);}
    }
    #[NoAdminRequired]
    public function logs(string $date,int $cursor=0,string $level='all',string $kind='all'):DataResponse {
        try{return new DataResponse($this->account()->logs($date,$cursor,$level,$kind));}
        catch(\OCP\AppFramework\OCS\OCSBadRequestException $e){return new DataResponse(['error'=>$e->getMessage()],400);}
    }
    #[NoAdminRequired]
    public function control(string $action,string $kind='both',string $embedding_rebuild_id=''):DataResponse {
        if(!in_array($action,['start','pause','resume','retry'],true))return new DataResponse(['error'=>'Unbekannte Aktion'],400);
        $config=$this->settings->forUser($this->uid())->get();
        if($action==='start'&&(($kind==='descriptions'&&!$config['analysis_enabled'])||($kind==='ocr'&&!$config['ocr_enabled'])||($kind==='embeddings'&&!$config['embedding']['enabled'])||($kind==='both'&&(!$config['analysis_enabled']||!$config['ocr_enabled']||!$config['embedding']['enabled']))))return new DataResponse(['error'=>'Diese Verarbeitung ist vom Administrator noch nicht eingerichtet.'],409);
        try{return new DataResponse($this->account()->setControl($action,true,$embedding_rebuild_id,$kind));}
        catch(\OCP\AppFramework\OCS\OCSBadRequestException $e){return new DataResponse(['error'=>$e->getMessage()],409);}
    }
    #[NoAdminRequired]
    public function delete(string $kind,string $confirmation,int $expected_count=-1):DataResponse {
        if($confirmation!==$kind||!in_array($kind,['descriptions','ocr','embeddings','all'],true))return new DataResponse(['error'=>'Bestätigung fehlt'],400);
        $state=$this->account();if($kind!=='all')$state=$state->forPipeline($kind);$status=$state->status();
        if(!$status['online']||empty($status['control']['paused'])||!empty($status['control']['requested'])||!empty($status['control']['reset_requested'])||$status['state']==='running')return new DataResponse(['error'=>'Bitte zuerst pausieren und das Ende der aktuellen Datei abwarten.'],409);
        $uid=$this->uid();$count=$kind==='all'?$this->metadata->count($uid)+$this->embeddings->count($uid):($kind==='embeddings'?$this->embeddings->count($uid):$this->metadata->count($uid,$kind));
        if($expected_count!==$count)return new DataResponse(['error'=>'Die Anzahl hat sich geändert. Bitte die Bestätigung erneut öffnen.'],409);
        try{$state->requestReset($kind);return new DataResponse(['requested'=>true]);}
        catch(\OCP\AppFramework\OCS\OCSBadRequestException $e){return new DataResponse(['error'=>$e->getMessage()],409);}
    }
    #[NoAdminRequired]
    public function result(int $file_id):DataResponse {
        try{$file=$this->files->byId($this->uid(),$file_id);}catch(\OCP\AppFramework\OCS\OCSNotFoundException|\OCP\Files\NotFoundException|\OCP\Files\NotPermittedException){return new DataResponse(['available'=>false],404);}
        $row=$this->metadata->get($this->uid(),$file_id);return new DataResponse($row?['available'=>true,'stale'=>!hash_equals($row['etag'],$file->getEtag()),'metadata'=>json_decode($row['metadata_json'],true)]:['available'=>false]);
    }
}
