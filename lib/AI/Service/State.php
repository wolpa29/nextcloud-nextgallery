<?php
declare(strict_types=1);
namespace OCA\Nextgallery\AI\Service;
use OCP\IConfig;
use OCP\AppFramework\OCS\OCSBadRequestException;

class State {
    private ?string $account=null;
    public function __construct(private IConfig $config) {}
    public function forUser(string $uid):self {$copy=clone $this;$copy->account=$uid;return $copy;}
    public function rootDirectory():string {$copy=clone $this;$copy->account=null;return $copy->directory();}
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
    public function anyRunning():bool {
        $root=$this->rootDirectory();$service=is_file($root.'/service.json')?json_decode((string)file_get_contents($root.'/service.json'),true):[];
        return (!empty($service['active_user_id'])&&time()-(float)($service['heartbeat_at']??0)<20)||($this->status()['state']??'')==='running';
    }
    private function read(string $name, array $fallback): array {
        $path = $this->directory() . '/' . $name;
        if (!is_file($path)) { return $fallback; }
        if (filesize($path) > 1048576) { throw new \RuntimeException('Statusdatei zu groß'); }
        $value = json_decode((string)file_get_contents($path), true);
        return is_array($value) ? $value : $fallback;
    }
    public function control(): array { return $this->read('control.json', ['paused' => true, 'requested' => false]); }
    public function setControl(string $action, bool $validate = false, string $rebuildConfirmation='',string $kind='both'): array {
        if(!in_array($kind,['both','descriptions','embeddings'],true))throw new OCSBadRequestException('Verarbeitungsauswahl ungültig');
        if (!in_array($action, ['start','pause','resume','retry','reset'], true)) { throw new OCSBadRequestException('Unbekannte Aktion'); }
        $lock = fopen($this->directory() . '/control.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) { throw new \RuntimeException('Steuerung ist gesperrt'); }
        try {
            $value = $this->control();
            if ($validate) {
                $status=$this->status(); $running=($status['state']??'')==='running';
                $waiting=!empty($value['requested']); $paused=!empty($value['paused']);
                $rebuild=$status['embedding_rebuild']??[];$saved=json_decode($this->config->getAppValue('nextgallery','ai_settings','{}'),true)??[];
                $needsRebuild=!empty($rebuild['required'])&&!empty($saved['embedding']['enabled'])&&($action==='start'?$kind:($value['run_kind']??'both'))!=='descriptions';
                $allowed=$status['online'] && empty($value['reset_requested']) && match ($action) {
                    'start' => !$running && (!$waiting||$needsRebuild),
                    'pause' => !$paused,
                    'resume' => !$running && !$waiting && $paused && ($status['stats']['pending']??0)>0,
                    'retry' => !$running && !$waiting && ($status['stats']['error']??0)>0,
                    default => false,
                };
                if (!$allowed) { throw new OCSBadRequestException('Diese Aktion ist im aktuellen Zustand nicht verfügbar.'); }
                if($needsRebuild&&in_array($action,['start','resume','retry'],true)){
                    if(!hash_equals($rebuild['request_id']??'', $rebuildConfirmation)||$rebuildConfirmation==='')throw new OCSBadRequestException('Modellwechsel: Bitte zuerst den Neuaufbau der Bildvektoren bestätigen.');
                    $rebuild['required']=false;$rebuild['approved']=true;$rebuild['approved_space']=$rebuild['target_space']??null;
                    $this->atomic('embedding-rebuild.json',$rebuild);
                }
            }
            if ($action === 'reset') { $value['paused']=true; $value['requested']=false; $value['reset_requested']=true; $value['reset_request_id']=bin2hex(random_bytes(12)); }
            elseif ($action === 'pause') { $value['paused'] = true; $value['requested']=false; unset($value['retry_errors']); }
            else { $value['paused'] = false; $value['requested'] = true; $value['request_id'] = bin2hex(random_bytes(12)); }
            if ($action === 'retry') { $value['retry_errors'] = true; }
            if($action==='start')$value['run_kind']=$kind;
            unset($value['pause_reason']);
            $value['updated_at'] = time();
            $this->atomic('control.json', $value);
            return $value;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
    public function atomic(string $name, array $value): void {
        $target = $this->directory() . '/' . $name;
        $temp = $target . '.' . bin2hex(random_bytes(8));
        file_put_contents($temp, json_encode($value, JSON_THROW_ON_ERROR), LOCK_EX);
        chmod($temp, 0600);
        if (!rename($temp, $target)) { throw new \RuntimeException('Status konnte nicht gespeichert werden'); }
    }
    public function status(): array {
        $value = $this->read('status.json', ['state' => 'not_started', 'stats' => [], 'message' => 'Worker noch nicht gestartet']);
        $value['online'] = isset($value['heartbeat_at']) && time() - (float)$value['heartbeat_at'] < 20;
        if($this->account!==null){
            $root=$this->rootDirectory();$service=is_file($root.'/service.json')?json_decode((string)file_get_contents($root.'/service.json'),true):[];
            if($root!==$this->directory())$value['online']=isset($service['heartbeat_at'])&&time()-(float)$service['heartbeat_at']<20;
            $value['worker_busy']=!empty($service['active_user_id'])&&$service['active_user_id']!==$this->account;
            if(($value['state']??'not_started')==='not_started')$value['state']='paused';
        }
        $value['control'] = $this->control();
        $install=$this->read('embedding-install.json', []);$value['embedding_install_requested']=!empty($install['requested']);$value['embedding_install_profile']=$install['profile']??'lite';
        $value['embedding_remove']=$this->read('embedding-remove.json',[]);
        $value['embedding_rebuild']=$this->read('embedding-rebuild.json',[]);
        return $value;
    }
    public function requestReset(string $kind):array {
        if(!in_array($kind,['descriptions','embeddings','all'],true))throw new OCSBadRequestException('Lösch-Auswahl ungültig');
        $lock=fopen($this->directory().'/control.lock','c');
        if(!$lock||!flock($lock,LOCK_EX))throw new \RuntimeException('Steuerung ist gesperrt');
        try{$value=$this->control();if(empty($value['paused'])||!empty($value['requested'])||!empty($value['reset_requested'])||($this->status()['state']??'')==='running')throw new OCSBadRequestException('Bitte zuerst pausieren.');$value['reset_kind']=$kind;$value['reset_requested']=true;$value['reset_request_id']=bin2hex(random_bytes(12));$value['paused']=true;$value['requested']=false;$value['updated_at']=time();$this->atomic('control.json',$value);return $value;}
        finally{flock($lock,LOCK_UN);fclose($lock);}
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
            if($this->anyRunning())throw new OCSBadRequestException('Zum Entfernen zuerst die laufende Verarbeitung pausieren.');
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
