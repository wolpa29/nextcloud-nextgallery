<?php
declare(strict_types=1);
namespace OCA\Nextgallery\AI\Service;
use OCP\IConfig;
use OCP\AppFramework\OCS\OCSBadRequestException;

class State {
    public const PIPELINES=['descriptions','ocr','embeddings','faces','audio'];
    public const DEFAULT_PIPELINES=['descriptions','ocr','embeddings'];
    private ?string $account=null;
    private ?string $pipeline=null;
    public function __construct(private IConfig $config) {}
    public function forUser(string $uid):self {$copy=clone $this;$copy->account=$uid;return $copy;}
    public function forPipeline(string $kind):self {
        if(!in_array($kind,self::PIPELINES,true))throw new OCSBadRequestException('Verarbeitungsauswahl ungültig');
        $this->initializePipelines();$copy=clone $this;$copy->pipeline=$kind;return $copy;
    }
    /** Seed once under the same lock used by the old worker; caches stay in place. */
    public function initializePipelines():void {
        $root=clone $this;$root->pipeline=null;
        if(($root->read('pipelines.json',[])['version']??0)>=4)return;
        $lock=fopen($root->directory().'/control.lock','c');
        if(!$lock||!flock($lock,LOCK_EX))throw new \RuntimeException('Steuerung ist gesperrt');
        try{
            if(($root->read('pipelines.json',[])['version']??0)>=4)return;
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
            if($version<2){
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
            }
            if($version<3){
            $root->atomic('control-faces.json',['paused'=>true,'requested'=>false,'run_kind'=>'faces']);
            $root->atomic('status-faces.json',['state'=>'paused','current'=>null,'stats'=>['done'=>0,'pending'=>0,'error'=>0]]);
            $root->atomic('schedule-faces.json',[]);
            $root->atomic('pipelines.json',['version'=>3]);
            }
            if($version<4){
            $root->atomic('control-audio.json',['paused'=>true,'requested'=>false,'run_kind'=>'audio']);
            $root->atomic('status-audio.json',['state'=>'paused','current'=>null,'stats'=>['done'=>0,'pending'=>0,'error'=>0]]);
            $root->atomic('schedule-audio.json',[]);
            $root->atomic('pipelines.json',['version'=>4]);
            }
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
        if($kind===null){foreach(self::PIPELINES as $lane)if($this->anyRunning($lane))return true;return false;}
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
            $locks=[];try{foreach(self::PIPELINES as $lane)$locks[]=$this->globalLock($exclusive,$lane);return $locks;}
            catch(\Throwable $e){self::releaseLock($locks);throw $e;}
        }
        if(!in_array($kind,self::PIPELINES,true))throw new OCSBadRequestException('Verarbeitungsauswahl ungültig');
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
        if($kind===null){foreach(self::PIPELINES as $lane)if(!$this->processingPaused($lane))return false;return true;}
        $root=clone $this;$root->account=null;$root->pipeline=null;
        $path='processing-admin-'.$kind.'.json';
        return !empty($root->read($path,$root->read('processing-admin.json',[]))['paused']);
    }
    public function setProcessingPaused(bool $paused,?string $kind=null):void {
        $kind=$kind??$this->pipeline;
        if($kind===null){foreach(self::PIPELINES as $lane)$this->setProcessingPaused($paused,$lane);return;}
        if(!in_array($kind,self::PIPELINES,true))throw new OCSBadRequestException('Verarbeitungsauswahl ungültig');
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
            $controls=array_map(fn($kind)=>$this->forPipeline($kind)->control(),self::PIPELINES);
            return ['paused'=>!in_array(false,array_map(fn($c)=>!empty($c['paused']),$controls),true),'requested'=>in_array(true,array_map(fn($c)=>!empty($c['requested']),$controls),true),'reset_requested'=>in_array(true,array_map(fn($c)=>!empty($c['reset_requested']),$controls),true),'run_kind'=>'both'];
        }
        return $this->read('control.json', ['paused' => true, 'requested' => false]);
    }
    /** Prepare without writing so a combined request cannot partially start/reset. */
    private function prepareControl(string $action,bool $validate,string $confirmation):array {
        $kind=$this->pipeline;$value=$this->control();$pendingRebuild=null;
        if($action!=='pause'&&$action!=='reset'&&$this->processingPaused())throw new OCSBadRequestException($kind==='embeddings'?'Embedding-Läufe wurden vom Administrator pausiert.':($kind==='ocr'?'OCR-Läufe wurden vom Administrator pausiert.':($kind==='faces'?'Personenerkennung wurde vom Administrator pausiert.':($kind==='audio'?'Audio-Transkriptionen wurden vom Administrator pausiert.':'Beschreibungs-Läufe wurden vom Administrator pausiert.'))));
        if($validate){
            $status=$this->status();$running=$status['state']==='running';$waiting=!empty($value['requested']);$paused=!empty($value['paused']);
            $rebuild=$status['embedding_rebuild']??[];$saved=json_decode($this->config->getAppValue('nextgallery','ai_settings','{}'),true)??[];
            $needsRebuild=$kind==='embeddings'&&!empty($rebuild['required'])&&!empty($saved['embedding']['enabled']);
            if(!$status['online'])throw new OCSBadRequestException('Der Hintergrund-Worker ist aktuell offline.');
            if(!empty($value['reset_requested']))throw new OCSBadRequestException('Bitte warten, bis das Löschen der KI-Daten abgeschlossen ist.');
            $allowed=match($action){
                'start','resume','retry'=>!$running&&(!$waiting||$needsRebuild),
                'pause'=>!$paused||$running||$waiting,
                default=>false,
            };
            if(!$allowed)throw new OCSBadRequestException($action==='pause'?'Dieser Lauf ist bereits pausiert.':'Dieser Lauf ist bereits aktiv oder in der Warteschlange.');
            if($needsRebuild&&in_array($action,['start','resume','retry'],true)){
                if($confirmation===''||!hash_equals($rebuild['request_id']??'',$confirmation))throw new OCSBadRequestException('Modellwechsel: Bitte zuerst den Neuaufbau der Bildvektoren bestätigen.');
                $rebuild['required']=false;$rebuild['approved']=true;$rebuild['approved_space']=$rebuild['target_space']??null;$pendingRebuild=$rebuild;
            }
        }
        if($action==='reset'){
            if(!empty($value['requested'])||!empty($value['reset_requested'])||$this->status()['state']==='running')throw new OCSBadRequestException('Bitte zuerst pausieren.');
            $value['paused']=true;$value['requested']=false;$value['reset_kind']=$kind;$value['reset_requested']=true;$value['reset_request_id']=bin2hex(random_bytes(12));
        }elseif($action==='pause'){$value['paused']=true;$value['requested']=false;unset($value['retry_errors']);}
        else{$value['paused']=false;$value['requested']=true;$value['request_id']=bin2hex(random_bytes(12));}
        if($action==='retry')$value['retry_errors']=true;
        $value['run_kind']=$kind;unset($value['pause_reason']);$value['updated_at']=time();
        return ['control'=>$value,'rebuild'=>$pendingRebuild];
    }
    public function setControl(string $action,bool $validate=false,string $rebuildConfirmation='',string $kind='both'):array {
        if(!in_array($kind,['both','descriptions','ocr','embeddings','faces','audio'],true))throw new OCSBadRequestException('Verarbeitungsauswahl ungültig');
        if(!in_array($action,['start','pause','resume','retry','reset'],true))throw new OCSBadRequestException('Unbekannte Aktion');
        $this->initializePipelines();$kinds=$this->pipeline!==null?[$this->pipeline]:($kind==='both'?(in_array($action,['pause','reset'],true)?self::PIPELINES:self::DEFAULT_PIPELINES):[$kind]);
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
            $pipelines=[];foreach(self::PIPELINES as $kind)$pipelines[$kind]=$this->forPipeline($kind)->status();
            $value=$pipelines['embeddings'];$value['pipelines']=$pipelines;
            $value['online']=in_array(true,array_column($pipelines,'online'),true);
            $states=array_column($pipelines,'state');$value['state']=in_array('running',$states,true)?'running':(in_array('error',$states,true)?'error':$value['state']);
            $value['control']=$this->control();$value['description_stats']=$pipelines['descriptions']['stats'];$value['ocr_stats']=$pipelines['ocr']['stats'];$value['embedding_stats']=$pipelines['embeddings']['stats'];$value['faces_stats']=$pipelines['faces']['stats'];$value['audio_stats']=$pipelines['audio']['stats']??['done'=>0,'pending'=>0,'error'=>0];
            $value['analysis_stats']=[];$value['stats']=[];foreach(['done','pending','error'] as $key){$value['analysis_stats'][$key]=($value['description_stats'][$key]??0)+($value['ocr_stats'][$key]??0);$value['stats'][$key]=$value['analysis_stats'][$key]+($value['embedding_stats'][$key]??0)+($value['faces_stats'][$key]??0)+($value['audio_stats'][$key]??0);}
            $root=clone $this;$root->account=null;$root->pipeline=null;$value['faces_setup']=$root->read('faces-setup.json',[]);$value['audio_setup']=$root->audioSetup();
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
        if($this->pipeline==='faces'){$root=clone $this;$root->account=null;$root->pipeline=null;$value['faces_setup']=$root->read('faces-setup.json',[]);}
        if($this->pipeline==='audio'){$root=clone $this;$root->account=null;$root->pipeline=null;$value['audio_setup']=$root->audioSetup();}
        return $value;
    }
    public function audioSetup(): array {
        $dir = $this->rootDirectory() . '/audio-local';
        $setupPath = $dir . '/setup.json';
        $readyPath = $dir . '/ready.json';
        $value = is_file($setupPath) && !is_link($setupPath) && filesize($setupPath) < 1048576
            ? json_decode((string)file_get_contents($setupPath), true)
            : [];
        if (!is_array($value)) $value = [];
        $ready = is_file($readyPath) && !is_link($readyPath);
        $state = $value['state'] ?? ($ready ? 'ready' : 'not_installed');
        $installed = !empty($value['installed']) || $ready || $state === 'ready';
        $isReady = $ready || $state === 'ready';
        $phase = $value['phase'] ?? $state;
        return array_merge([
            'phase' => $phase,
            'state' => $state,
            'installed' => $installed,
            'ready' => $isReady,
            'message' => $value['message'] ?? ($isReady ? 'Bereit für Spracherkennung' : 'Audio-Transkription noch nicht eingerichtet'),
        ], $value, [
            'phase' => $phase,
            'state' => $state,
            'installed' => $installed,
            'ready' => $isReady,
        ]);
    }
    public function requestReset(string $kind):array {
        if(!in_array($kind,['descriptions','ocr','embeddings','faces','audio','all'],true))throw new OCSBadRequestException('Lösch-Auswahl ungültig');
        return $this->setControl('reset',false,'',$kind==='all'?'both':$kind);
    }
    /** Purge local worker queue entries for specific files so they are not treated as cached/done. */
    public function forgetFiles(array $fileIds,string $kind='all'):void {
        $ids=array_values(array_unique(array_filter(array_map('intval',$fileIds),fn($id)=>$id>0)));
        if(!$ids)return;
        $dir=$this->directory();
        if(in_array($kind,['all','descriptions','ocr'],true)){
            $names=$kind==='all'?['native-descriptions.sqlite3','native-ocr.sqlite3','native.sqlite3']:['native-'.$kind.'.sqlite3','native.sqlite3'];
            foreach($names as $name){
                $path=$dir.'/'.$name;if(!is_file($path)||is_link($path))continue;
                try{
                    $db=new \PDO('sqlite:'.$path,null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);$db->exec('PRAGMA busy_timeout=1000');
                    foreach(array_chunk($ids,200) as $chunk){
                        $marks=implode(',',array_fill(0,count($chunk),'?'));$locators=array_map('strval',$chunk);
                        try{$db->prepare('DELETE FROM results WHERE sha256 IN (SELECT sha256 FROM files WHERE locator IN ('.$marks.') AND sha256 IS NOT NULL)')->execute($locators);}catch(\Throwable){}
                        try{$db->prepare('DELETE FROM shared WHERE sha256 IN (SELECT sha256 FROM files WHERE locator IN ('.$marks.') AND sha256 IS NOT NULL)')->execute($locators);}catch(\Throwable){}
                        try{$db->prepare('DELETE FROM search WHERE id IN (SELECT id FROM files WHERE locator IN ('.$marks.'))')->execute($locators);}catch(\Throwable){}
                        try{$db->prepare('DELETE FROM files WHERE locator IN ('.$marks.')')->execute($locators);}catch(\Throwable){}
                    }
                }catch(\Throwable){}
            }
        }
        if(in_array($kind,['all','embeddings'],true)){
            $path=$dir.'/embeddings.sqlite3';
            if(is_file($path)&&!is_link($path)){
                try{
                    $db=new \PDO('sqlite:'.$path,null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);$db->exec('PRAGMA busy_timeout=1000');
                    $stmt=$db->prepare('DELETE FROM embeddings WHERE id LIKE ?');
                    foreach($ids as $id)$stmt->execute(['%:'.$id]);
                }catch(\Throwable){}
            }
        }
        if(in_array($kind,['all','faces'],true)){
            $path=$dir.'/faces.sqlite3';
            if(is_file($path)&&!is_link($path)){
                try{
                    $db=new \PDO('sqlite:'.$path,null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);$db->exec('PRAGMA busy_timeout=1000');
                    foreach(array_chunk($ids,200) as $chunk){
                        $marks=implode(',',array_fill(0,count($chunk),'?'));
                        try{$db->prepare('DELETE FROM detections WHERE file_id IN ('.$marks.')')->execute($chunk);}catch(\Throwable){}
                        try{$db->prepare('DELETE FROM files WHERE file_id IN ('.$marks.')')->execute($chunk);}catch(\Throwable){}
                    }
                }catch(\Throwable){}
            }
        }
        if(in_array($kind,['all','audio'],true)){
            $path=$dir.'/audio.sqlite3';
            if(is_file($path)&&!is_link($path)){
                try{
                    $db=new \PDO('sqlite:'.$path,null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);$db->exec('PRAGMA busy_timeout=1000');
                    foreach(array_chunk($ids,200) as $chunk){
                        $marks=implode(',',array_fill(0,count($chunk),'?'));
                        try{$db->prepare('DELETE FROM results WHERE file_id IN ('.$marks.')')->execute($chunk);}catch(\Throwable){}
                        try{$db->prepare('DELETE FROM files WHERE file_id IN ('.$marks.')')->execute($chunk);}catch(\Throwable){}
                    }
                }catch(\Throwable){}
            }
        }
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
    public function logs(string $date, int $cursor = 0, string $level = 'all', string $kind = 'all'): array {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) || $cursor < 0 || !in_array($level,['all','error'],true) || !in_array($kind,['all','descriptions','ocr','embeddings','faces','audio','both'],true)) { throw new OCSBadRequestException('Log-Auswahl ungültig'); }
        $path = $this->directory() . '/logs/' . $date . '.jsonl';
        if (!is_file($path)) { return ['entries' => [], 'cursor' => null]; }
        $stream = fopen($path, 'rb');
        if (!$stream) { throw new \RuntimeException('Logdatei nicht lesbar'); }
        try {
            $size = fstat($stream)['size'];
            $end = $cursor === 0 ? $size : min($cursor, $size);
            $rows = [];
            // Filter before pagination. Read backwards with a bounded budget so older
            // errors remain reachable even after many successful files.
            for($block=0;$end>0&&$block<8;$block++){
                $start = max(0, $end - 262144);
                fseek($stream, $start);$bytes = (string)fread($stream, $end - $start);
                if ($start > 0) {
                    $newline = strpos($bytes, "\n");
                    if ($newline === false) { $end=$start;continue; }
                    $start += $newline + 1;$bytes = substr($bytes, $newline + 1);
                }
                $offset=$start;$lines=[];
                foreach(explode("\n",$bytes) as $line){$lines[]=[$offset,$line];$offset+=strlen($line)+1;}
                foreach(array_reverse($lines) as [$position,$line]){
                    $row=json_decode($line,true);if(!is_array($row))continue;
                    $error=($row['level']??'')==='error'||str_ends_with($row['event']??'','_error')||!empty($row['error'])||!empty($row['reason']);
                    if(($level==='error'&&!$error)||($kind!=='all'&&($row['kind']??'')!==$kind))continue;
                    $rows[]=$row;
                    if(count($rows)>=200)return ['entries'=>$rows,'cursor'=>$position>0?$position:null];
                }
                $end=$start;
            }
            return ['entries' => $rows, 'cursor' => $end > 0 ? $end : null];
        } finally { fclose($stream); }
    }
}
