<?php
declare(strict_types=1);
namespace OCA\MediaAI\Service;

/** Read-only model discovery. Only administrator-configured endpoints are used. */
class ModelConnection {
    protected function fetch(string $url, string $secret): array {
        $handle = curl_init($url);
        $body = ''; $tooLarge = false;
        $headers = ['Accept: application/json'];
        if ($secret !== '') { $headers[] = 'Authorization: Bearer ' . $secret; }
        curl_setopt_array($handle, [CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROXY => '',
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_WRITEFUNCTION => function($curl, $chunk) use (&$body, &$tooLarge) {
                if (strlen($body) + strlen($chunk) > 1048576) { $tooLarge = true; return 0; }
                $body .= $chunk; return strlen($chunk);
            }]);
        $ok = curl_exec($handle) !== false;
        $code = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);
        return ['http_status' => $code, 'data' => json_decode($body, true),
            'error' => $tooLarge ? 'response_too_large' : (!$ok ? 'connection_failed' : null)];
    }

    private static function text($value): ?string {
        if (!is_string($value) || $value === '') { return null; }
        return preg_match('/\A.{1,500}/us', $value, $match) === 1 ? $match[0] : null;
    }
    private static function tokens($value): ?int {
        return is_int($value) && $value > 0 ? $value : null;
    }

    public static function nativeModels(array $rows): array {
        $models = [];
        foreach ($rows as $row) {
            if (!is_array($row) || ($row['type'] ?? '') !== 'llm' || !self::text($row['key'] ?? null)) { continue; }
            $instances = [];
            foreach (is_array($row['loaded_instances'] ?? null) ? $row['loaded_instances'] : [] as $instance) {
                if (!is_array($instance) || !self::text($instance['id'] ?? null)) { continue; }
                $instances[] = ['id' => self::text($instance['id']),
                    'context_length' => self::tokens($instance['config']['context_length'] ?? null)];
            }
            $models[] = ['id' => self::text($row['key']), 'name' => self::text($row['display_name'] ?? null) ?? self::text($row['key']),
                'architecture' => self::text($row['architecture'] ?? null),
                'quantization' => self::text($row['quantization']['name'] ?? null),
                'vision' => is_bool($row['capabilities']['vision'] ?? null) ? $row['capabilities']['vision'] : null,
                'max_context_length' => self::tokens($row['max_context_length'] ?? null), 'instances' => $instances];
        }
        return $models;
    }

    public static function legacyModels(array $rows): array {
        $models = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !in_array($row['type'] ?? '', ['llm','vlm'], true) || !self::text($row['id'] ?? null)) { continue; }
            $models[] = ['id' => self::text($row['id']), 'name' => self::text($row['id']),
                'architecture' => self::text($row['arch'] ?? null), 'quantization' => self::text($row['quantization'] ?? null),
                'vision' => $row['type'] === 'vlm', 'max_context_length' => self::tokens($row['max_context_length'] ?? null),
                'instances' => ($row['state'] ?? '') === 'loaded' ? [['id' => self::text($row['id']),
                    'context_length' => self::tokens($row['loaded_context_length'] ?? null)]] : []];
        }
        return $models;
    }

    private function discover(array $config): array {
        $base = rtrim($config['url'], '/');
        $secret = $config['api_key'] ?? '';
        $root = preg_replace('~/v1$~', '', $base);
        $response = $this->fetch($root . '/api/v1/models', $secret);
        $source = 'lmstudio_v1';
        if (in_array($response['http_status'], [404,405], true) && !$response['error']) {
            $response = $this->fetch($root . '/api/v0/models', $secret); $source = 'lmstudio_v0';
        }
        if (in_array($response['http_status'], [404,405], true) && !$response['error']) {
            $response = $this->fetch($base . '/models', $secret); $source = 'openai';
        }
        $data = $response['data'];
        $rows = is_array($data) ? ($data[$source === 'lmstudio_v1' ? 'models' : 'data'] ?? null) : null;
        $error = $response['error'];
        if (!$error && $response['http_status'] !== 200) {
            $error = in_array($response['http_status'], [401,403], true) ? 'authentication_failed' : 'server_error';
        }
        if (!$error && (!is_array($rows) || !array_is_list($rows))) { $error = 'invalid_response'; }
        $models = [];
        if (!$error) {
            if ($source === 'lmstudio_v1') { $models = self::nativeModels($rows); }
            elseif ($source === 'lmstudio_v0') { $models = self::legacyModels($rows); }
            else {
                foreach ($rows as $row) {
                    if (is_array($row) && self::text($row['id'] ?? null)) {
                        $models[] = ['id' => self::text($row['id']), 'name' => self::text($row['id']), 'architecture' => null,
                            'quantization' => null, 'vision' => null, 'max_context_length' => null, 'instances' => null];
                    }
                }
            }
        }
        return ['reachable' => !$error, 'source' => $source, 'http_status' => $response['http_status'],
            'error' => $error, 'model_details' => $models];
    }

    public function inspect(array $config): array {
        $result = []; $endpoints = [];
        foreach (['vision','ocr'] as $stage) {
            $settings = $config[$stage];
            $key = hash('sha256', $settings['url'] . "\0" . ($settings['api_key'] ?? ''));
            $endpoints[$key] ??= $this->discover($settings);
            $connection = $endpoints[$key];
            $selected = null; $loadedModels = [];
            foreach ($connection['model_details'] as $model) {
                if ($model['id'] === $settings['model']) { $selected = $model; }
                foreach ($model['instances'] ?? [] as $instance) {
                    $loaded = $model; unset($loaded['instances']);
                    $loaded['instance_id'] = $instance['id']; $loaded['context_length'] = $instance['context_length'];
                    $loadedModels[] = $loaded;
                    if ($instance['id'] === $settings['model']) { $selected = $model + ['selected_instance' => $instance]; }
                }
            }
            $instance = $selected['selected_instance'] ?? ($selected['instances'][0] ?? null);
            $known = $connection['source'] !== 'openai';
            unset($connection['model_details']);
            $result[$stage] = $connection + ['configured_model' => $settings['model'], 'available' => $selected !== null,
                'loaded' => $known ? $instance !== null : null, 'context_length' => $instance['context_length'] ?? null,
                'max_context_length' => $selected['max_context_length'] ?? null, 'vision' => $selected['vision'] ?? null,
                'quantization' => $selected['quantization'] ?? null, 'architecture' => $selected['architecture'] ?? null,
                'models' => array_column($endpoints[$key]['model_details'], 'id'), 'loaded_models' => $loadedModels,
                'model_max_pixels' => $settings['model_max_pixels'] ?? 0];
        }
        return $result + ['checked_at' => time(), 'video_frames' => $config['video']['frame_count'] ?? 10,
            'ocr_batch_images' => $config['ocr']['max_batch_images'] ?? 20];
    }
}
