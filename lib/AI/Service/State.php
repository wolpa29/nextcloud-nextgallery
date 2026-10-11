<?php
declare(strict_types=1);
namespace OCA\Nextgallery\AI\Service;
use OCP\IConfig;
use OCP\AppFramework\OCS\OCSBadRequestException;

class State {
    private ?string $account=null;
    private ?string $pipeline=null;
    public function __construct(private IConfig $config) {}
    public function forUser(string $uid):self {$copy=clone $this;$copy->account=$uid;return $copy;}
    public function forPipeline(string $kind):self {
        if(!in_array($kind,['descriptions','ocr','embeddings'],true))throw new OCSBadRequestException('Verarbeitungsauswahl ungültig');
        $this->initializePipelines();$copy=clone $this;$copy->pipeline=$kind;return $copy;
    }
    /** Seed once under the same lock used by the old worker; caches stay in place. */
    public function initializePipelines():void {
        $root=clone $this;$root->pipeline=null;
        if(($root->read('pipelines.json',[])['version']??0)>=2)return;
        $lock=fopen($root->directory().'/control.lock','c');
        if(!$lock||!flock($lock,LOCK_EX))throw new \RuntimeException('Steuerung ist gesperrt');
        try{
            if(($root->read('pipelines.json',[])['version']??0)>=2)return;
            $version=$root->read('pipelines.json',[])['version']??0;
            if($version<1){
            $old=$root->read('control.json',['paused'=>true,'requested'=>false]);$status=$root->read('status.json',[]);$schedule=$root->read('schedule.json',[]);
            foreach(['descriptions','embeddings'] as $kind){
                $selected=in_array($old['run_kind']??'both',['both',$kind],true);$value=$old;
                $value['run_kind']=$kind;
                if(!$selected){$value=['paused'=>true,'requested'=>false,'run_kind'=>$kind];}
                if(!empty($old['reset_requested'])){
                    $reset=$old['reset_kind']??'all';$value['reset_requested']=in_array($reset,['all',$kind],true);$value['reset_kind']=$kind;
                    if($value['reset_requested']){$value['paused']=true;$value['requested']=false;}
                }
                $root->atomic('control-'.$kind.'.json',$value);
                $stats=$status[$kind==='descriptions'?'analysis_stats':'embedding_stats']??($kind==='descriptions'?($status['stats']??[]):[]);
                $lane=['state'=>'paused','current'=>null,'stats'=>$stats,($kind==='descriptions'?'analysis_stats':'embedding_stats')=>$stats];
                if(($status['last_run']['kind']??$old['run_kind']??'both')===$kind)$lane['last_run']=$status['last_run']??null;
                if($kind==='embeddings')foreach(['embedding_space','embedding_models','embedding_service','embedding_setup_supported','embedding_setup_profiles','embedding_remove_supported'] as $key)if(isset($status[$key]))$lane[$key]=$status[$key];
                $root->atomic('status-'.$kind.'.json',$lane);
                $next=$schedule;if($kind==='descriptions')unset($next['embedding_pending']);$root->atomic('schedule-'.$kind.'.json',$next);
            }
            }
            $description=$root->read('control-descriptions.json',['paused'=>true,'requested'=>false]);
            $ocr=$description;$ocr['run_kind']='ocr';
            if(!empty($ocr['reset_requested']))$ocr['reset_kind']='ocr';
            $root->atomic('control-ocr.json',$ocr);
            $oldStatus=$root->read('status-descriptions.json',[]);
            $root->atomic('status-ocr.json',['state'=>'paused','current'=>null,'stats'=>[],'ocr_stats'=>[]]);
            $root->atomic('schedule-ocr.json',$root->read('schedule-descriptions.json',[]));
            // Old description suspension applied to both analysis stages.
            $adminFile=$root->rootDirectory().'/processing-admin-descriptions.json';
            if($root->directory()===$root->rootDirectory()&&is_file($adminFile))$root->atomic('processing-admin-ocr.json',$root->read('processing-admin-descriptions.json',[]));
            $root->atomic('pipelines.json',['version'=>2]);
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    private function filename(string $name):string {
        return $this->pipeline!==null&&in_array($name,['control.json','status.json','schedule.json','service.json'],true)?str_replace('.json','-'.$this->pipeline.'.json',$name):$name;
    }
    public function rootDirectory():string {$copy=clone $this;$copy->account=null;$copy->pipeline=null;return $copy->directory();}
    public function directory(): string {
        $path = rtrim($this->config->getSystemValueString('datadirectory'), '/') . '/.nextgallery_ai';
        $global=json_decode($this->config->getAppValue('nextgallery','ai_settings','{}'),true)??[];
        if($this->account!==null&&$this->account!==($global['user_id']??'paul')){
            $root=$this->rootDirectory();$parent=$root.'/accounts';
            if(is_link($parent))throw new \RuntimeException('Kontoverzeichnis darf kein Symlink sein');
            if(!is_dir($parent)&&!mkdir($parent,0700,true)&&!is_dir($parent))throw new \RuntimeException('Kontoverzeichnis nicht beschreibbar');
            $path=$parent.'/'.hash('sha256',$this->account);
        }
        if (is_link($path)) { throw new \RuntimeException('Worker-Verzeichnis darf kein Symlink sein'); }
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) { throw new \RuntimeException('Worker-Verzeichnis nicht beschreibbar'); }
        return $path;
    }
    public function registerAccount():void {
        if($this->account!==null&&$this->directory()!==$this->rootDirectory()){if(($this->read('account.json',[])['user_id']??null)!==$this->account)$this->atomic('account.json',['user_id'=>$this->account]);}
    }
    public function anyRunning(?string $kind=null):bool {
        $kind=$kind??$this->pipeline;
        if($kind===null)return $this->anyRunning('descriptions')||$this->anyRunning('ocr')||$this->anyRunning('embeddings');
        $file=$this->rootDirectory().'/service-'.$kind.'.json';
        $service=is_file($file)?json_decode((string)file_get_contents($file),true):[];
        if(!empty($service['active_user_id'])&&time()-(float)($service['heartbeat_at']??0)<20)return true;
        foreach($this->accountIds() as $uid){$s=$this->forUser($uid)->forPipeline($kind)->status();if($s['online']&&$s['state']==='running')return true;}
        return false;
    }
    /** Each pipeline has its own gate, also held throughout a worker run. */
    public function globalLock(bool $exclusive=false,?string $kind=null) {
        $kind=$kind??$this->pipeline;
        // An old daemon must be restarted before its global gate can be replaced.
        $legacyFile=$this->rootDirectory().'/service.json';$legacy=is_file($legacyFile)?json_decode((string)file_get_contents($legacyFile),true):[];
        if($exclusive&&!empty($legacy['active_user_id'])&&time()-(float)($legacy['heartbeat_at']??0)<20)throw new OCSBadRequestException('Bitte den Worker nach dem App-Update neu starten.');
        if($kind===null){
            $locks=[];try{foreach(['descriptions','ocr','embeddings'] as $lane)$locks[]=$this->globalLock($exclusive,$lane);return $locks;}
            catch(\Throwable $e){self::releaseLock($locks);throw $e;}
        }
        if(!in_array($kind,['descriptions','ocr','embeddings'],true))throw new OCSBadRequestException('Verarbeitungsauswahl ungültig');
        $lock=@fopen($this->rootDirectory().'/processing-'.$kind.'.lock','c');
        if(!$lock)throw new OCSBadRequestException('Die Verarbeitungssperre ist nicht beschreibbar. Bitte den Administrator die Worker-Dateirechte prüfen lassen.');
        if(!flock($lock,($exclusive?LOCK_EX:LOCK_SH)|LOCK_NB)){
            if($lock)fclose($lock);throw new OCSBadRequestException('Bitte die betroffene Verarbeitung pausieren und das Ende der aktuellen Datei abwarten.');
        }
        return $lock;
    }
    public static function releaseLock($locks):void {foreach(is_array($locks)?$locks:[$locks] as $lock){flock($lock,LOCK_UN);fclose($lock);}}
    public function processingPaused(?string $kind=null):bool {
        $kind=$kind??$this->pipeline;
        if($kind===null)return $this->processingPaused('descriptions')&&$this->processingPaused('ocr')&&$this->processingPaused('embeddings');
        $root=clone $this;$root->account=null;$root->pipeline=null;
        $path='processing-admin-'.$kind.'.json';
        return !empty($root->read($path,$root->read('processing-admin.json',[]))['paused']);
    }
    public function setProcessingPaused(bool $paused,?string $kind=null):void {
        $kind=$kind??$this->pipeline;
        if($kind===null){foreach(['descriptions','ocr','embeddings'] as $lane)$this->setProcessingPaused($paused,$lane);return;}
        if(!in_array($kind,['descriptions','ocr','embeddings'],true))throw new OCSBadRequestException('Verarbeitungsauswahl ungültig');
        $root=clone $this;$root->account=null;$root->pipeline=null;$root->atomic('processing-admin-'.$kind.'.json',['paused'=>$paused,'updated_at'=>time()]);
    }
    public function accountIds():array {
        $global=json_decode($this->config->getAppValue('nextgallery','ai_settings','{}'),true)??[];
        $ids=[$global['user_id']??'paul'];
        foreach(glob($this->rootDirectory().'/accounts/*/account.json')?:[] as $path){
            if(is_link(dirname($path))||is_link($path)||filesize($path)>4096)continue;
            $uid=(json_decode((string)file_get_contents($path),true)??[])['user_id']??null;
            if(is_string($uid)&&hash('sha256',$uid)===basename(dirname($path)))$ids[]=$uid;
        }
        return array_values(array_unique($ids));
    }
    private function read(string $name, array $fallback): array {
        $path = $this->directory() . '/' . $this->filename($name);
        if (!is_file($path)) { return $fallback; }
        if (filesize($path) > 1048576) { throw new \RuntimeException('Statusdatei zu groß'); }
        $value = json_decode((string)file_get_contents($path), true);
        return is_array($value) ? $value : $fallback;
    }
    public function control(): array {
        if($this->pipeline===null&&is_file($this->directory().'/pipelines.json')){
            $controls=array_map(fn($kind)=>$this->forPipeline($kind)->control(),['descriptions','ocr','embeddings']);
            return ['paused'=>!in_array(false,array_map(fn($c)=>!empty($c['paused']),$controls),true),'requested'=>in_array(true,array_map(fn($c)=>!empty($c['requested']),$controls),true),'reset_requested'=>in_array(true,array_map(fn($c)=>!empty($c['reset_requested']),$controls),true),'run_kind'=>'both'];
        }
        return $this->read('control.json', ['paused' => true, 'requested' => false]);
    }
    /** Prepare without writing so a combined request cannot partially start/reset. */
    private function prepareControl(string $action,bool $validate,string $confirmation):array {
        $kind=$this->pipeline;$value=$this->control();$pendingRebuild=null;
        if($action!=='pause'&&$action!=='reset'&&$this->processingPaused())throw new OCSBadRequestException($kind==='embeddings'?'Embedding-Läufe wurden vom Administrator pausiert.':($kind==='ocr'?'OCR-Läufe wurden vom Administrator pausiert.':'Beschreibungs-Läufe wurden vom Administrator pausiert.'));
        if($validate){
            $status=$this->status();$running=$status['state']==='running';$waiting=!empty($value['requested']);$paused=!empty($value['paused']);
            $rebuild=$status['embedding_rebuild']??[];$saved=json_decode($this->config->getAppValue('nextgallery','ai_settings','{}'),true)??[];
            $needsRebuild=$kind==='embeddings'&&!empty($rebuild['required'])&&!empty($saved['embedding']['enabled']);
            $allowed=$status['online']&&empty($value['reset_requested'])&&match($action){
                'start'=>!$running&&(!$waiting||$needsRebuild),
                'pause'=>!$paused,
                'resume'=>!$running&&!$waiting&&$paused&&($status['stats']['pending']??0)>0,
                'retry'=>!$running&&!$waiting&&($status['stats']['error']??0)>0,
                default=>false,
            };
            if(!$allowed)throw new OCSBadRequestException('Diese Aktion ist im aktuellen Zustand nicht verfügbar.');
            if($needsRebuild&&in_array($action,['start','resume','retry'],true)){
                if($confirmation===''||!hash_equals($rebuild['request_id']??'',$confirmation))throw new OCSBadRequestException('Modellwechsel: Bitte zuerst den Neuaufbau der Bildvektoren bestätigen.');
                $rebuild['required']=false;$rebuild['approved']=true;$rebuild['approved_space']=$rebuild['target_space']??null;$pendingRebuild=$rebuild;
            }
        }
        if($action==='reset'){
            if(empty($value['paused'])||!empty($value['requested'])||!empty($value['reset_requested'])||$this->status()['state']==='running')throw new OCSBadRequestException('Bitte zuerst pausieren.');
            $value['paused']=true;$value['requested']=false;$value['reset_kind']=$kind;$value['reset_requested']=true;$value['reset_request_id']=bin2hex(random_bytes(12));
        }elseif($action==='pause'){$value['paused']=true;$value['requested']=false;unset($value['retry_errors']);}
        else{$value['paused']=false;$value['requested']=true;$value['request_id']=bin2hex(random_bytes(12));}
        if($action==='retry')$value['retry_errors']=true;
        $value['run_kind']=$kind;unset($value['pause_reason']);$value['updated_at']=time();
        return ['control'=>$value,'rebuild'=>$pendingRebuild];
    }
    public function setControl(string $action,bool $validate=false,string $rebuildConfirmation='',string $kind='both'):array {
        if(!in_array($kind,['both','descriptions','ocr','embeddings'],true))throw new OCSBadRequestException('Verarbeitungsauswahl ungültig');
        if(!in_array($action,['start','pause','resume','retry','reset'],true))throw new OCSBadRequestException('Unbekannte Aktion');
        $this->initializePipelines();$kinds=$this->pipeline!==null?[$this->pipeline]:($kind==='both'?['descriptions','ocr','embeddings']:[$kind]);
        $gate=$this->globalLock(false,count($kinds)===1?$kinds[0]:null);$lock=fopen($this->directory().'/control.lock','c');
        if(!$lock||!flock($lock,LOCK_EX)){self::releaseLock($gate);throw new \RuntimeException('Steuerung ist gesperrt');}
        try{
            $plans=[];foreach($kinds as $lane)$plans[$lane]=$this->forPipeline($lane)->prepareControl($action,$validate,$rebuildConfirmation);
            foreach($plans as $lane=>$plan){$own=$this->forPipeline($lane);if($plan['rebuild']!==null)$own->atomic('embedding-rebuild.json',$plan['rebuild']);$own->atomic('control.json',$plan['control']);$result[$lane]=$plan['control'];}
            return count($kinds)===1?$result[$kinds[0]]:$result;
        }finally{flock($lock,LOCK_UN);fclose($lock);self::releaseLock($gate);}
    }
    public function atomic(string $name, array $value): void {
        $target = $this->directory() . '/' . $this->filename($name);
        $temp = $target . '.' . bin2hex(random_bytes(8));
        file_put_contents($temp, json_encode($value, JSON_THROW_ON_ERROR), LOCK_EX);
        chmod($temp, 0600);
        if (!rename($temp, $target)) { throw new \RuntimeException('Status konnte nicht gespeichert werden'); }
    }
    public function status(): array {
        if($this->pipeline===null&&is_file($this->directory().'/pipelines.json')){
            $pipelines=[];foreach(['descriptions','ocr','embeddings'] as $kind)$pipelines[$kind]=$this->forPipeline($kind)->status();
            $value=$pipelines['embeddings'];$value['pipelines']=$pipelines;
            $value['online']=in_array(true,array_column($pipelines,'online'),true);
            $states=array_column($pipelines,'state');$value['state']=in_array('running',$states,true)?'running':(in_array('error',$states,true)?'error':$value['state']);
            $value['control']=$this->control();$value['description_stats']=$pipelines['descriptions']['stats'];$value['ocr_stats']=$pipelines['ocr']['stats'];$value['embedding_stats']=$pipelines['embeddings']['stats'];
            $value['analysis_stats']=[];$value['stats']=[];foreach(['done','pending','error'] as $key){$value['analysis_stats'][$key]=($value['description_stats'][$key]??0)+($value['ocr_stats'][$key]??0);$value['stats'][$key]=$value['analysis_stats'][$key]+($value['embedding_stats'][$key]??0);}
            $value['admin_paused']=$this->processingPaused();return $value;
        }
        $value = $this->read('status.json', ['state' => 'not_started', 'stats' => [], 'message' => 'Worker noch nicht gestartet']);
        $value['online'] = isset($value['heartbeat_at']) && time() - (float)$value['heartbeat_at'] < 20;
        if($this->pipeline!==null){
            $file=$this->rootDirectory().'/service-'.$this->pipeline.'.json';$service=is_file($file)?json_decode((string)file_get_contents($file),true):[];
            $value['online']=isset($service['heartbeat_at'])&&time()-(float)$service['heartbeat_at']<20;
            $uid=$this->account??(json_decode($this->config->getAppValue('nextgallery','ai_settings','{}'),true)['user_id']??'paul');
            $value['worker_busy']=!empty($service['active_user_id'])&&$service['active_user_id']!==$uid;
            $value['pipeline']=$this->pipeline;
        }
        $value['state']=$value['state']??'paused';$value['stats']=$value['stats']??[];
        $value['control'] = $this->control();
        $value['admin_paused']=$this->processingPaused();
        $install=$this->read('embedding-install.json', []);$value['embedding_install_requested']=!empty($install['requested']);$value['embedding_install_profile']=$install['profile']??'lite';
        $value['embedding_remove']=$this->read('embedding-remove.json',[]);
        $value['embedding_rebuild']=$this->read('embedding-rebuild.json',[]);
        return $value;
    }
    public function requestReset(string $kind):array {
        if(!in_array($kind,['descriptions','ocr','embeddings','all'],true))throw new OCSBadRequestException('Lösch-Auswahl ungültig');
        return $this->setControl('reset',false,'',$kind==='all'?'both':$kind);
    }
    public function requireEmbeddingRebuild(string $signature,array $details=[]):void {
        $lock=fopen($this->directory().'/control.lock','c');
        if(!$lock||!flock($lock,LOCK_EX))throw new \RuntimeException('Steuerung ist gesperrt');
        try{$this->atomic('embedding-rebuild.json',['required'=>true,'approved'=>false,'signature'=>$signature,'request_id'=>bin2hex(random_bytes(12)),'updated_at'=>time()]+$details);}
        finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    public function requestEmbeddingInstall(string $profile='lite'): array {
        if(!in_array($profile,['lite','full'],true))throw new OCSBadRequestException('Gemma-Auswahl ungültig');
        $lock=fopen($this->directory().'/control.lock','c');
        if(!$lock||!flock($lock,LOCK_EX))throw new \RuntimeException('Steuerung ist gesperrt');
        try {
            $status=$this->status();
            if(!$status['online'])throw new OCSBadRequestException('Worker ist offline. Bitte zuerst starten.');
            if(empty($status['embedding_setup_supported'])&&empty($status['embedding_service']['setup_supported']))throw new OCSBadRequestException('Worker unterstützt die Installation noch nicht. Bitte nach dem App-Update neu starten.');
            if($profile==='full'&&!in_array('full',$status['embedding_setup_profiles']??[],true))throw new OCSBadRequestException('Worker unterstützt Gemma-Original noch nicht. Bitte nach dem App-Update neu starten.');
            if(($status['embedding_service']['profile']??'lite')===$profile&&!empty($status['embedding_service']['managed'])&&!empty($status['embedding_service']['installed']))throw new OCSBadRequestException('Gemma ist bereits installiert.');
            if(!empty($status['embedding_remove']['requested']))throw new OCSBadRequestException('Bitte zuerst das Entfernen des Modells abwarten.');
            $value=$this->read('embedding-install.json',[]);
            if(empty($value['requested'])||($value['profile']??'lite')!==$profile||($status['embedding_service']['phase']??'')==='error'){
                $value=['requested'=>true,'profile'=>$profile,'request_id'=>bin2hex(random_bytes(12)),'updated_at'=>time()];
                $this->atomic('embedding-install.json',$value);
            }
            return $value;
        } finally {flock($lock,LOCK_UN);fclose($lock);}
    }
    public function requestEmbeddingRemove(string $profile): array {
        if(!in_array($profile,['lite','full'],true))throw new OCSBadRequestException('Gemma-Auswahl ungültig');
        $lock=fopen($this->directory().'/control.lock','c');
        if(!$lock||!flock($lock,LOCK_EX))throw new \RuntimeException('Steuerung ist gesperrt');
        try {
            $status=$this->status();
            if(!$status['online']||empty($status['embedding_remove_supported']))throw new OCSBadRequestException('Zum Entfernen muss der aktualisierte Worker online sein.');
            if($this->anyRunning('embeddings'))throw new OCSBadRequestException('Zum Entfernen zuerst die laufende Verarbeitung pausieren.');
            if(empty($status['embedding_models'][$profile]['installed']))throw new OCSBadRequestException('Diese Modellvariante ist nicht installiert.');
            if(!empty($status['embedding_install_requested']))throw new OCSBadRequestException('Bitte zuerst die laufende Installation abwarten.');
            $value=$this->read('embedding-remove.json',[]);
            if(!empty($value['requested']))return $value;
            $value=['requested'=>true,'profile'=>$profile,'request_id'=>bin2hex(random_bytes(12)),'updated_at'=>time()];
            $this->atomic('embedding-remove.json',$value);return $value;
        } finally {flock($lock,LOCK_UN);fclose($lock);}
    }
    public function clearLogs(): int {
        $count=0;
        foreach(glob($this->directory().'/logs/????-??-??.jsonl') ?: [] as $path) { if(is_file($path) && !is_link($path) && unlink($path))$count++; }
        return $count;
    }
    public function logs(string $date, int $cursor = 0): array {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) || $cursor < 0) { throw new OCSBadRequestException('Log-Auswahl ungültig'); }
        $path = $this->directory() . '/logs/' . $date . '.jsonl';
        if (!is_file($path)) { return ['entries' => [], 'cursor' => null]; }
        $stream = fopen($path, 'rb');
        if (!$stream) { throw new \RuntimeException('Logdatei nicht lesbar'); }
        try {
            $size = fstat($stream)['size'];
            $end = $cursor === 0 ? $size : min($cursor, $size);
            $start = max(0, $end - 262144);
            fseek($stream, $start);
            $bytes = (string)fread($stream, $end - $start);
            if ($start > 0) {
                $newline = strpos($bytes, "\n");
                if ($newline !== false) { $start += $newline + 1; $bytes = substr($bytes, $newline + 1); }
            }
            $rows = [];
            foreach (explode("\n", $bytes) as $line) { $row = json_decode($line, true); if (is_array($row)) { $rows[] = $row; } }
            return ['entries' => array_reverse($rows), 'cursor' => $start > 0 ? $start : null];
        } finally { fclose($stream); }
    }
}
