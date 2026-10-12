<?php
declare(strict_types=1);
namespace OCA\Nextgallery\AI\Service;

use OCP\IConfig;
use OCP\Security\ICrypto;
use OCP\AppFramework\OCS\OCSBadRequestException;

class Settings {
    private ?string $account = null;
    public function forUser(string $uid):self { $copy=clone $this;$copy->account=$uid;return $copy; }
    public function __construct(private IConfig $config, private ICrypto $crypto) {}
    private function stored(string $key, string $default): string {
        return $this->config->getAppValue('nextgallery', 'ai_' . $key, $default);
    }
    public function defaults(): array {
        return ['enabled' => false, 'analysis_enabled' => true, 'ocr_enabled'=>true, 'embedding' => ['enabled'=>false, 'location'=>'local', 'local_auto_setup'=>true, 'local_profile'=>'lite', 'transport'=>'multimodal', 'family'=>'gemma2', 'model'=>'embeddinggemma-2', 'url'=>'http://10.1.10.78:9379/v1', 'local_model_path'=>'/opt/nextgallery-ai-models/embeddinggemma-2-text-vision-440m.litertlm', 'local_qwen_source'=>'', 'model_revision'=>'', 'max_edge'=>2048, 'video_frames'=>10, 'timeout_seconds'=>120], 'user_id' => 'paul', 'folders' => ['/'], 'exclude_folders' => [],
            'schedule_time' => '03:00', 'timezone' => 'Europe/Vienna', 'image_timeout_seconds' => 600, 'log_days' => 30, 'max_download_bytes' => 67108864,
            'max_image_pixels' => 80000000, 'max_video_download_bytes' => 536870912, 'video' => ['frame_count' => 10],
            'vision' => ['url' => 'http://10.1.10.78:1234/v1', 'model' => 'qwen/qwen3.8-27b',
                'model_revision' => 'qwen3.8-27b-Q4_K_M-20261002', 'max_edge' => 8192, 'model_max_pixels' => 4194304, 'transport' => 'lmstudio',
                'max_tokens' => 0, 'timeout_seconds' => 7200, 'temperature' => 0.2,
                'allow_reasoning_json' => true, 'prompt' => '', 'stream' => true,
                'loop_repetitions' => 5, 'numeric_loop_repetitions' => 40, 'loop_retries' => 3, 'retry_temperature' => 1.0, 'retry_prompt' => ''],
            'ocr' => ['tile_overlap_percent' => 10, 'max_batch_images' => 20, 'url' => 'http://10.1.10.78:1234/v1', 'model' => 'qwen/qwen3.8-27b',
                'model_revision' => 'qwen3.8-27b-Q4_K_M-20261002', 'max_edge' => 8192, 'model_max_pixels' => 4194304, 'transport' => 'lmstudio',
                'max_tokens' => 0, 'timeout_seconds' => 7200, 'temperature' => 1.0, 'prompt' => '',
                'allow_reasoning_json' => true, 'stream' => true, 'loop_repetitions' => 5,
                'numeric_loop_repetitions' => 40, 'loop_retries' => 3, 'retry_temperature' => 1.0, 'retry_prompt' => '']];
    }
    public function get(): array {
        $saved = json_decode($this->stored('settings', '{}'), true, 64, JSON_THROW_ON_ERROR);
        $value = array_replace_recursive($this->defaults(), $saved);
        $value['ocr_enabled']=$saved['ocr_enabled']??$value['analysis_enabled'];
        // Existing installations with a configured model keep their explicit setup.
        if(isset($saved['embedding']['local_model_path'])&&!array_key_exists('local_auto_setup',$saved['embedding'])&&$saved['embedding']['local_model_path']!==$this->defaults()['embedding']['local_model_path'])$value['embedding']['local_auto_setup']=false;
        unset($value['limit'], $value['max_seconds']); // Former batch limits no longer apply.
        if($this->account!==null){
            $uid=$this->account;
            $personal=json_decode($this->config->getUserValue($uid,'nextgallery','ai_processing','{}'),true,64,JSON_THROW_ON_ERROR);
            $base=$uid===$value['user_id']?$value:['enabled'=>false,'folders'=>['/'],'exclude_folders'=>[],'schedule_time'=>$value['schedule_time'],'timezone'=>$value['timezone']];
            foreach(['enabled','folders','exclude_folders','schedule_time','timezone'] as $key)$value[$key]=$personal[$key]??$base[$key];
            $value['user_id']=$uid;
        }
        return $value;
    }
    public function saveAccount(string $uid,array $value,?callable $validateFolders=null):array {
        $result=$this->forUser($uid)->get();
        foreach(['enabled','folders','exclude_folders','schedule_time','timezone'] as $key)if(array_key_exists($key,$value))$result[$key]=$value[$key];
        if(!is_bool($result['enabled'])||!is_string($result['schedule_time'])||!is_string($result['timezone'])||!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$result['schedule_time'])||!in_array($result['timezone'],\DateTimeZone::listIdentifiers(),true))throw new OCSBadRequestException('Zeitplan ungültig');
        $result['folders']=$this->paths($result['folders']);$result['exclude_folders']=$this->paths($result['exclude_folders']);
        if(!$result['folders'])throw new OCSBadRequestException('Mindestens einen Ordner auswählen');
        if($validateFolders)$validateFolders($result);
        $safe=array_intersect_key($result,array_flip(['enabled','folders','exclude_folders','schedule_time','timezone']));
        $this->config->setUserValue($uid,'nextgallery','ai_processing',json_encode($safe,JSON_THROW_ON_ERROR));
        return $safe+['user_id'=>$uid];
    }
    private function secret(string $key): string {
        $raw = $this->stored($key, '');
        return $raw === '' ? '' : $this->crypto->decrypt($raw);
    }
    private function setSecret(string $key, string $value): void {
        $this->config->setAppValue('nextgallery', 'ai_' . $key, $value === '' ? '' : $this->crypto->encrypt($value));
    }
    public static function embeddingSignature(array $value):string {
        $data=$value['embedding'];$keys=['location','family','model','local_auto_setup','local_profile','local_model_path','local_qwen_source','model_revision','url','max_edge','video_frames'];
        $signature=['user_id'=>$value['user_id']];foreach($keys as $key)$signature[$key]=$data[$key]??null;
        return hash('sha256',json_encode($signature,JSON_THROW_ON_ERROR));
    }
    public function workerToken(): string {
        $token = $this->secret('worker_token');
        if ($token === '') { $token = bin2hex(random_bytes(32)); $this->setSecret('worker_token', $token); }
        return $token;
    }
    public function verifyWorker(string $header): bool {
        $token = $this->secret('worker_token');
        return $token !== '' && hash_equals('Bearer ' . $token, $header);
    }
    public function publicConfig(): array {
        $value = $this->get();
        $prompts = json_decode(file_get_contents(dirname(__DIR__, 3) . '/worker/default_prompts.json'), true, 64, JSON_THROW_ON_ERROR);
        foreach (['vision', 'ocr'] as $stage) {
            $value[$stage]['has_api_key'] = $this->secret($stage . '_api_key') !== '';
            $value[$stage]['default_prompt'] = $prompts[$stage]['prompt'];
            $value[$stage]['default_retry_prompt'] = $prompts[$stage]['retry_prompt'];
        }
        $value['embedding']['has_api_key'] = $this->secret('embedding_api_key') !== '';
        $value['embedding_config_id']=self::embeddingSignature($value);
        return $value;
    }
    public function workerConfig(): array {
        $value = $this->get();
        foreach (['vision', 'ocr', 'embedding'] as $stage) { $value[$stage]['api_key'] = $this->secret($stage . '_api_key'); }
        $value['embedding_config_id']=self::embeddingSignature($value);
        return $value;
    }
    /** Validate an unsaved form and resolve its keys without persisting anything. */
    public function probeConfig(array $value = []): array {
        $saved = $this->workerConfig();
        if (!$value) { return $saved; }
        $candidate = $this->save($value, null, false);
        foreach (['vision', 'ocr', 'embedding'] as $stage) {
            $candidate[$stage]['api_key'] = $value[$stage]['api_key'] ?? '';
            if ($candidate[$stage]['api_key'] === '' && empty($value[$stage]['clear_api_key'])) {
                $candidate[$stage]['api_key'] = $saved[$stage]['api_key'];
            }
        }
        return $candidate;
    }
    private function paths($value): array {
        if (!is_array($value) || !array_is_list($value) || count($value) > 100) { throw new OCSBadRequestException('Ordnerliste ungültig'); }
        $result = [];
        foreach ($value as $path) {
            if (!is_string($path) || strlen($path) > 2000 || str_contains($path, "\0") || str_contains($path, '\\') || preg_match('~(^|/)(\.|\.\.)(/|$)~', $path)) {
                throw new OCSBadRequestException('Ungültiger Ordnerpfad');
            }
            $result[] = '/' . trim($path, '/');
        }
        return array_values(array_unique($result));
    }
    public static function endpoint(string $value): string {
        $parts = parse_url($value);
        if (!$parts || !isset($parts['host']) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || strlen($value) > 2000) {
            throw new OCSBadRequestException('API-Adresse muss eine HTTP(S)-URL ohne Passwort sein');
        }
        $value = rtrim($value, '/');
        return empty($parts['path']) || $parts['path'] === '/' ? $value . '/v1' : $value;
    }
    private function integer($value, int $min, int $max, string $name): int {
        if (!is_int($value) || $value < $min || $value > $max) { throw new OCSBadRequestException("Ungültiger Wert für $name"); }
        return $value;
    }
    public function save(array $value, ?callable $validateFolders = null, bool $persist = true): array {
        $result = $this->defaults();
        if (!is_bool($value['enabled'] ?? null) || !is_string($value['user_id'] ?? null)
            || !preg_match('/^[^\/\\\\\x00]{1,64}$/uD', $value['user_id'])
            || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $value['schedule_time'] ?? '')) {
            throw new OCSBadRequestException('Benutzer, Zeitplan oder Aktivierung ungültig');
        }
        try { new \DateTimeZone($value['timezone'] ?? ''); } catch (\Exception $e) { throw new OCSBadRequestException('Zeitzone ungültig'); }
        foreach (['enabled','user_id','schedule_time','timezone'] as $key) { $result[$key] = $value[$key]; }
        $result['folders'] = $this->paths($value['folders'] ?? []);
        $result['exclude_folders'] = $this->paths($value['exclude_folders'] ?? []);
        if (!$result['folders']) { throw new OCSBadRequestException('Mindestens einen Ordner auswählen'); }
        foreach (['image_timeout_seconds' => [0,86400], 'log_days' => [1,365],
                  'max_download_bytes' => [1048576,536870912], 'max_image_pixels' => [1000000,200000000]] as $key => [$min,$max]) {
            $result[$key] = $this->integer($value[$key] ?? ($key === 'image_timeout_seconds' ? 600 : null), $min, $max, $key);
        }
        $result['max_video_download_bytes'] = $this->integer($value['max_video_download_bytes'] ?? 536870912, 1048576, 2147483648, 'max_video_download_bytes');
        if (!is_array($value['video'] ?? []) || !is_array($value['ocr'] ?? [])) { throw new OCSBadRequestException('Medien-Einstellungen ungültig'); }
        $result['video']['frame_count'] = $this->integer($value['video']['frame_count'] ?? 10, 1, 20, 'video/frame_count');
        $result['ocr']['tile_overlap_percent'] = $this->integer($value['ocr']['tile_overlap_percent'] ?? 10, 1, 25, 'ocr/tile_overlap_percent');
        $result['ocr']['max_batch_images'] = $this->integer($value['ocr']['max_batch_images'] ?? 20, 2, 64, 'ocr/max_batch_images');
        $secrets = [];
        foreach (['vision','ocr'] as $stage) {
            $data = $value[$stage] ?? [];
            if (!is_array($data)) { throw new OCSBadRequestException('Modell-Einstellungen ungültig'); }
            $result[$stage]['url'] = self::endpoint($data['url'] ?? '');
            foreach (['model','model_revision','prompt','retry_prompt'] as $key) {
                $text = $data[$key] ?? '';
                if (!is_string($text) || strlen($text) > (str_ends_with($key, 'prompt') ? 20000 : 500)
                    || (!str_ends_with($key, 'prompt') && trim($text) === '')) { throw new OCSBadRequestException("Ungültige Einstellung: $stage/$key"); }
                $result[$stage][$key] = $text;
            }
            if (!in_array($data['transport'] ?? 'lmstudio', ['lmstudio','openai'], true)) { throw new OCSBadRequestException('Ungültiger Modellzugang'); }
            $result[$stage]['transport'] = $data['transport'] ?? 'lmstudio';
            $result[$stage]['model_max_pixels'] = $this->integer($data['model_max_pixels'] ?? 4194304, 0, 67108864, "$stage/model_max_pixels");
            if ($result[$stage]['model_max_pixels'] > 0 && $result[$stage]['model_max_pixels'] < 1024) { throw new OCSBadRequestException('Bildbudget zu klein'); }
            foreach (['max_edge' => [256,8192], 'max_tokens' => [0,32768], 'timeout_seconds' => [15,7200]] as $key => [$min,$max]) {
                $result[$stage][$key] = $this->integer($data[$key] ?? null, $min, $max, "$stage/$key");
            }
            foreach (['loop_repetitions' => [3,20], 'numeric_loop_repetitions' => [20,200], 'loop_retries' => [0,3]] as $key => [$min,$max]) {
                $result[$stage][$key] = $this->integer((array_key_exists($key,$data) ? $data[$key] : $result[$stage][$key]), $min, $max, "$stage/$key");
            }
            foreach (['stream','allow_reasoning_json'] as $key) {
                $flag = array_key_exists($key,$data) ? $data[$key] : $result[$stage][$key];
                if (!is_bool($flag)) { throw new OCSBadRequestException("Ungültige Einstellung: $stage/$key"); }
                $result[$stage][$key] = $flag;
            }
            $temperature = $data['temperature'] ?? null;
            if (!is_numeric($temperature) || $temperature < 0 || $temperature > 2) { throw new OCSBadRequestException('Temperatur ungültig'); }
            $result[$stage]['temperature'] = (float)$temperature;
            $retryTemperature = $data['retry_temperature'] ?? $result[$stage]['retry_temperature'];
            if (!is_numeric($retryTemperature) || $retryTemperature < 0 || $retryTemperature > 2) { throw new OCSBadRequestException('Korrektur-Temperatur ungültig'); }
            $result[$stage]['retry_temperature'] = (float)$retryTemperature;
            $secret = $data['api_key'] ?? '';
            if (!is_string($secret) || strlen($secret) > 4096 || str_contains($secret, "\n") || str_contains($secret, "\r")) { throw new OCSBadRequestException('API-Key ungültig'); }
            if ($secret !== '' || !empty($data['clear_api_key'])) { $secrets[$stage . '_api_key'] = $secret; }
        }
        $result['ocr_enabled']=$value['ocr_enabled']??($value['analysis_enabled']??true);
        if(!is_bool($result['ocr_enabled']))throw new OCSBadRequestException('Verarbeitungsauswahl ungültig');
        $result['analysis_enabled']=$value['analysis_enabled']??true;
        if(!is_bool($result['analysis_enabled']))throw new OCSBadRequestException('Verarbeitungsauswahl ungültig');
        $embedding=$value['embedding']??$result['embedding'];
        if(!is_array($embedding))throw new OCSBadRequestException('Embedding-Einstellungen ungültig');
        foreach(['enabled'=>[true,false],'local_auto_setup'=>[true,false],'local_profile'=>['lite','full'],'location'=>['local','external'],'transport'=>['multimodal','lmstudio'],'family'=>['gemma2','qwen3_vl','custom']] as $key=>$allowed){
            $setting=$embedding[$key]??$result['embedding'][$key];if(!in_array($setting,$allowed,true))throw new OCSBadRequestException('Embedding-Auswahl ungültig');$result['embedding'][$key]=$setting;
        }
        if($result['embedding']['family']==='custom'&&$result['embedding']['location']!=='external')throw new OCSBadRequestException('Eigene Embedding-Modelle benötigen einen externen Dienst.');
        $embeddingUrl=$embedding['url']??$result['embedding']['url'];
        if($result['embedding']['location']==='local'&&$embeddingUrl==='')$embeddingUrl=$result['embedding']['url'];
        $result['embedding']['url']=self::endpoint($embeddingUrl);
        foreach(['model','local_model_path','local_qwen_source','model_revision'] as $key){$text=$embedding[$key]??$result['embedding'][$key];if(!is_string($text)||strlen($text)>2000||str_contains($text,"\0")||str_contains($text,"\n")||str_contains($text,"\r")||($key==='model'&&trim($text)===''))throw new OCSBadRequestException('Embedding-Modellangabe ungültig');$result['embedding'][$key]=$text;}
        if($result['embedding']['enabled']&&$result['embedding']['location']==='local'){
            $automatic=$result['embedding']['family']==='gemma2'&&$result['embedding']['local_auto_setup'];
            if(!$automatic||$result['embedding']['local_model_path']!=='')if(!str_starts_with($result['embedding']['local_model_path'],'/'))throw new OCSBadRequestException('Lokales Modellpaket benötigt einen absoluten Pfad');
            if($automatic)$result['embedding']['model']=$result['embedding']['local_profile']==='full'?'embeddinggemma-2-full':'embeddinggemma-2';
            if($result['embedding']['family']==='qwen3_vl'&&(!str_starts_with($result['embedding']['local_qwen_source'],'/')||trim($result['embedding']['model_revision'])===''))throw new OCSBadRequestException('Qwen benötigt einen absoluten Quellcode-Pfad und einen festgelegten Modellstand');
        }
        if($result['embedding']['location']==='local'&&$result['embedding']['family']==='gemma2'&&$result['embedding']['local_auto_setup'])$result['embedding']['model']=$result['embedding']['local_profile']==='full'?'embeddinggemma-2-full':'embeddinggemma-2';
        foreach(['max_edge'=>[256,4096],'video_frames'=>[1,20],'timeout_seconds'=>[15,7200]] as $key=>[$min,$max])$result['embedding'][$key]=$this->integer($embedding[$key]??$result['embedding'][$key],$min,$max,'embedding/'.$key);
        $secret=$embedding['api_key']??'';
        if(!is_string($secret)||strlen($secret)>4096||str_contains($secret,"\n")||str_contains($secret,"\r"))throw new OCSBadRequestException('API-Key ungültig');
        if($secret!==''||!empty($embedding['clear_api_key']))$secrets['embedding_api_key']=$secret;
        // Validate the entire form before writing any value or encrypted secret.
        if ($validateFolders !== null) { $validateFolders($result); }
        if (!$persist) { return $result; }
        $this->config->setAppValue('nextgallery', 'ai_settings', json_encode($result, JSON_THROW_ON_ERROR));
        foreach ($secrets as $key => $secret) { $this->setSecret($key, $secret); }
        return $this->publicConfig();
    }
}
