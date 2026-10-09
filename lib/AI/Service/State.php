<?php
declare(strict_types=1);
namespace OCA\Nextgallery\AI\Service;
use OCP\IConfig;
use OCP\AppFramework\OCS\OCSBadRequestException;

class State {
    public function __construct(private IConfig $config) {}
    public function directory(): string {
        $path = rtrim($this->config->getSystemValueString('datadirectory'), '/') . '/.nextgallery_ai';
        if (is_link($path)) { throw new \RuntimeException('Worker-Verzeichnis darf kein Symlink sein'); }
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) { throw new \RuntimeException('Worker-Verzeichnis nicht beschreibbar'); }
        return $path;
    }
    private function read(string $name, array $fallback): array {
        $path = $this->directory() . '/' . $name;
        if (!is_file($path)) { return $fallback; }
        if (filesize($path) > 1048576) { throw new \RuntimeException('Statusdatei zu groß'); }
        $value = json_decode((string)file_get_contents($path), true);
        return is_array($value) ? $value : $fallback;
    }
    public function control(): array { return $this->read('control.json', ['paused' => true, 'requested' => false]); }
    public function setControl(string $action, bool $validate = false): array {
        if (!in_array($action, ['start','pause','resume','retry','reset'], true)) { throw new OCSBadRequestException('Unbekannte Aktion'); }
        $lock = fopen($this->directory() . '/control.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) { throw new \RuntimeException('Steuerung ist gesperrt'); }
        try {
            $value = $this->control();
            if ($validate) {
                $status=$this->status(); $running=($status['state']??'')==='running';
                $waiting=!empty($value['requested']); $paused=!empty($value['paused']);
                $allowed=$status['online'] && empty($value['reset_requested']) && match ($action) {
                    'start' => !$running && !$waiting,
                    'pause' => ($running || $waiting) && !$paused,
                    'resume' => !$running && !$waiting && $paused && ($status['stats']['pending']??0)>0,
                    'retry' => !$running && !$waiting && ($status['stats']['error']??0)>0,
                    default => false,
                };
                if (!$allowed) { throw new OCSBadRequestException('Diese Aktion ist im aktuellen Zustand nicht verfügbar.'); }
            }
            if ($action === 'reset') { $value['paused']=true; $value['requested']=false; $value['reset_requested']=true; $value['reset_request_id']=bin2hex(random_bytes(12)); }
            elseif ($action === 'pause') { $value['paused'] = true; }
            else { $value['paused'] = false; $value['requested'] = true; $value['request_id'] = bin2hex(random_bytes(12)); }
            if ($action === 'retry') { $value['retry_errors'] = true; }
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
        $value['control'] = $this->control();
        return $value;
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
