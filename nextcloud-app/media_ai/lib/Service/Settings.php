<?php
declare(strict_types=1);
namespace OCA\MediaAI\Service;

use OCP\IConfig;
use OCP\Security\ICrypto;
use OCP\AppFramework\OCS\OCSBadRequestException;

class Settings {
    public function __construct(private IConfig $config, private ICrypto $crypto) {}
    public function defaults(): array {
        return ['enabled' => false, 'user_id' => 'paul', 'folders' => ['/'], 'exclude_folders' => [],
            'schedule_time' => '03:00', 'timezone' => 'Europe/Vienna', 'image_timeout_seconds' => 600, 'log_days' => 30, 'max_download_bytes' => 67108864,
            'max_image_pixels' => 80000000,
            'vision' => ['url' => 'http://10.1.10.78:1234/v1', 'model' => 'qwen/qwen3.8-27b',
                'model_revision' => 'qwen3.8-27b-Q4_K_M-20261002', 'max_edge' => 2048,
                'max_tokens' => 0, 'timeout_seconds' => 7200, 'temperature' => 0.2,
                'allow_reasoning_json' => true, 'prompt' => '', 'stream' => true,
                'loop_repetitions' => 5, 'numeric_loop_repetitions' => 40, 'loop_retries' => 3, 'retry_temperature' => 1.0, 'retry_prompt' => ''],
            'ocr' => ['url' => 'http://10.1.10.78:1234/v1', 'model' => 'qwen/qwen3.8-27b',
                'model_revision' => 'qwen3.8-27b-Q4_K_M-20261002', 'max_edge' => 4096,
                'max_tokens' => 0, 'timeout_seconds' => 7200, 'temperature' => 1.0, 'prompt' => '',
                'allow_reasoning_json' => true, 'stream' => true, 'loop_repetitions' => 5,
                'numeric_loop_repetitions' => 40, 'loop_retries' => 3, 'retry_temperature' => 1.0, 'retry_prompt' => '']];
    }
    public function get(): array {
        $saved = json_decode($this->config->getAppValue('media_ai', 'settings', '{}'), true, 64, JSON_THROW_ON_ERROR);
        $value = array_replace_recursive($this->defaults(), $saved);
        unset($value['limit'], $value['max_seconds']); // Former batch limits no longer apply.
        return $value;
    }
    private function secret(string $key): string {
        $raw = $this->config->getAppValue('media_ai', $key, '');
        return $raw === '' ? '' : $this->crypto->decrypt($raw);
    }
    private function setSecret(string $key, string $value): void {
        $this->config->setAppValue('media_ai', $key, $value === '' ? '' : $this->crypto->encrypt($value));
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
        $prompts = json_decode(file_get_contents(dirname(__DIR__, 2) . '/worker/default_prompts.json'), true, 64, JSON_THROW_ON_ERROR);
        foreach (['vision', 'ocr'] as $stage) {
            $value[$stage]['has_api_key'] = $this->secret($stage . '_api_key') !== '';
            $value[$stage]['default_prompt'] = $prompts[$stage]['prompt'];
            $value[$stage]['default_retry_prompt'] = $prompts[$stage]['retry_prompt'];
        }
        return $value;
    }
    public function workerConfig(): array {
        $value = $this->get();
        foreach (['vision', 'ocr'] as $stage) { $value[$stage]['api_key'] = $this->secret($stage . '_api_key'); }
        return $value;
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
    public function save(array $value, ?callable $validateFolders = null): array {
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
        // Validate the entire form before writing any value or encrypted secret.
        if ($validateFolders !== null) { $validateFolders($result); }
        $this->config->setAppValue('media_ai', 'settings', json_encode($result, JSON_THROW_ON_ERROR));
        foreach ($secrets as $key => $secret) { $this->setSecret($key, $secret); }
        return $this->publicConfig();
    }
}
