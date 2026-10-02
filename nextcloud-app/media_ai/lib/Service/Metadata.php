<?php
declare(strict_types=1);
namespace OCA\MediaAI\Service;
use OCP\IDBConnection;
use OCP\AppFramework\OCS\OCSBadRequestException;
class Metadata {
    public function __construct(private IDBConnection $db) {}
    public static function validate(int $fileId, string $etag, string $sha256, string $revision, array $metadata, array $exif): array {
        $vision = $metadata['vision'] ?? [];
        $ocr = $metadata['ocr']['text'] ?? null;
        if ($fileId < 1 || strlen($etag) < 1 || strlen($etag) > 255 || !preg_match('/^[a-f0-9]{64}$/D', $sha256)
            || !preg_match('/^[a-f0-9]{64}$/D', $revision) || !is_string($ocr) || strlen($ocr) > 500000
            || !in_array($vision['type'] ?? null, ['foto', 'screenshot', 'dokument', 'meme', 'grafik', 'unklar'], true)
            || !is_string($vision['description'] ?? null) || strlen($vision['description']) < 1 || strlen($vision['description']) > 48000) {
            throw new OCSBadRequestException('Invalid metadata');
        }
        foreach (['tags', 'objects', 'uncertain_details'] as $field) {
            if (!isset($vision[$field]) || !is_array($vision[$field]) || !array_is_list($vision[$field]) || count($vision[$field]) > 64) {
                throw new OCSBadRequestException('Invalid keyword list');
            }
            foreach ($vision[$field] as $word) {
                if (!is_string($word) || strlen($word) > 2000) { throw new OCSBadRequestException('Invalid keyword'); }
            }
        }
        $json = json_encode(['analysis' => $metadata, 'source_exif' => $exif], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (strlen($json) > 1048576) { throw new OCSBadRequestException('Metadata too large'); }
        return ['file_id' => $fileId, 'etag' => $etag, 'sha256' => $sha256, 'revision' => $revision,
                'type' => $vision['type'], 'description' => $vision['description'], 'ocr_text' => $ocr,
                'tags_text' => implode(' ', $vision['tags']), 'objects_text' => implode(' ', $vision['objects']),
                'metadata_json' => $json, 'updated_at' => time()];
    }
    public function upsert(string $uid, array $data): void {
        // Native parameter binding; no core table or original file is written.
        $data['user_id'] = $uid;
        $columns = array_keys($data);
        $sql = 'INSERT INTO `*PREFIX*media_ai_meta` (`' . implode('`,`', $columns) . '`) VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ') ON DUPLICATE KEY UPDATE ';
        $updates = [];
        foreach ($columns as $column) { if (!in_array($column, ['user_id', 'file_id'], true)) { $updates[] = "`$column`=VALUES(`$column`)"; } }
        $this->db->executeStatement($sql . implode(',', $updates), array_values($data));
    }
    public function get(string $uid, int $fileId): ?array {
        $result=$this->db->executeQuery('SELECT etag,metadata_json,updated_at FROM `*PREFIX*media_ai_meta` WHERE user_id=? AND file_id=?',[$uid,$fileId]);
        try { $row=$result->fetchAssociative(); return $row ?: null; } finally { $result->closeCursor(); }
    }
    public function search(string $uid, string $term, int $cursor, int $limit): array {
        preg_match_all('/[\p{L}\p{N}_]+/u', $term, $matches);
        $words = array_slice(array_filter($matches[0], fn($word) => mb_strlen($word) >= 3), 0, 16);
        if (!$words) { return []; }
        $query = implode(' ', array_map(fn($word) => '+' . $word . '*', $words));
        $result = $this->db->executeQuery('SELECT id,file_id,etag,description,ocr_text FROM `*PREFIX*media_ai_meta` WHERE user_id=? AND id>? AND MATCH(description,ocr_text,tags_text,objects_text) AGAINST (? IN BOOLEAN MODE) ORDER BY id LIMIT ' . min(50, max(1, $limit)), [$uid, $cursor, $query]);
        try { return $result->fetchAllAssociative(); } finally { $result->closeCursor(); }
    }
}
