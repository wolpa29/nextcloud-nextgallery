<?php
declare(strict_types=1);
namespace OCA\Chronicle\AI\Service;
use OCP\IDBConnection;
use OCP\AppFramework\OCS\OCSBadRequestException;
class Metadata {
    public function __construct(private IDBConnection $db) {}
    public static function validate(int $fileId, string $etag, string $sha256, string $revision, array $metadata, array $exif): array {
        $vision = $metadata['vision'] ?? [];
        $ocr = $metadata['ocr']['text'] ?? null;
        if ($fileId < 1 || strlen($etag) < 1 || strlen($etag) > 255 || !preg_match('/^[a-f0-9]{64}$/D', $sha256)
            || !preg_match('/^[a-f0-9]{64}$/D', $revision) || !is_string($ocr) || strlen($ocr) > 500000
            || !in_array($vision['type'] ?? null, ['video', 'foto', 'screenshot', 'dokument', 'meme', 'grafik', 'unklar'], true)
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
                'type' => $vision['type'], 'description' => mb_strtolower($vision['description']), 'ocr_text' => mb_strtolower($ocr),
                'tags_text' => mb_strtolower(implode(' ', $vision['tags'])), 'objects_text' => mb_strtolower(implode(' ', $vision['objects'])),
                'metadata_json' => $json, 'updated_at' => time()];
    }
    public function upsert(string $uid, array $data): void {
        $this->db->setValues('media_ai_meta', ['user_id'=>$uid, 'file_id'=>$data['file_id']], array_diff_key($data, ['user_id'=>true, 'file_id'=>true]));
    }
    public function normalizeIndex(): void {
        // Nextcloud tables use binary collation. Normalize indexed copies;
        // metadata_json preserves the complete original case and wording.
        $this->db->executeStatement('UPDATE *PREFIX*media_ai_meta SET description=LOWER(description),ocr_text=LOWER(ocr_text),tags_text=LOWER(tags_text),objects_text=LOWER(objects_text)',[]);
    }
    public function reset(string $uid): void {
        $this->db->executeStatement('DELETE FROM *PREFIX*media_ai_meta WHERE user_id=?',[$uid]);
    }
    public function get(string $uid, int $fileId): ?array {
        $result=$this->db->executeQuery('SELECT etag,metadata_json,updated_at FROM *PREFIX*media_ai_meta WHERE user_id=? AND file_id=?',[$uid,$fileId]);
        try { $row=$result->fetch(); return $row ?: null; } finally { $result->closeCursor(); }
    }
    public function statuses(string $uid, array $ids): array {
        if (!$ids) { return []; }
        $result=$this->db->executeQuery('SELECT file_id,etag,updated_at FROM *PREFIX*media_ai_meta WHERE user_id=? AND file_id IN ('.implode(',',array_fill(0,count($ids),'?')).')',array_merge([$uid],$ids));
        try { $rows=$result->fetchAll(); } finally { $result->closeCursor(); }
        $indexed=[]; foreach($rows as $row) { $indexed[(int)$row['file_id']]=$row; } return $indexed;
    }
    public function search(string $uid, string $term, int $cursor, int $limit): array {
        preg_match_all('/[\p{L}\p{N}_]+/u', $term, $matches);
        $words = array_slice(array_filter($matches[0], fn($word) => mb_strlen($word) >= 3), 0, 16);
        if (!$words) { return []; }
        if ($this->db->getDatabaseProvider() !== 'mysql') {
            $conditions=[]; $params=[$uid,$cursor];
            foreach ($words as $word) {
                $parts=[];
                foreach (['description','ocr_text','tags_text','objects_text'] as $column) {
                    $parts[]="$column LIKE ? ESCAPE '!'";
                    $params[]='%'.str_replace(['!','%','_'],['!!','!%','!_'],mb_strtolower($word)).'%';
                }
                $conditions[]='('.implode(' OR ',$parts).')';
            }
            $result=$this->db->executeQuery('SELECT id,file_id,etag,description,ocr_text,metadata_json FROM *PREFIX*media_ai_meta WHERE user_id=? AND id>? AND '.implode(' AND ',$conditions).' ORDER BY id LIMIT '.min(50,max(1,$limit)), $params);
            try { return $result->fetchAll(); } finally { $result->closeCursor(); }
        }
        $query = implode(' ', array_map(fn($word) => '+' . mb_strtolower($word) . '*', $words));
        $result = $this->db->executeQuery('SELECT id,file_id,etag,description,ocr_text,metadata_json FROM *PREFIX*media_ai_meta WHERE user_id=? AND id>? AND MATCH(description,ocr_text,tags_text,objects_text) AGAINST (? IN BOOLEAN MODE) ORDER BY id LIMIT ' . min(50, max(1, $limit)), [$uid, $cursor, $query]);
        try { return $result->fetchAll(); } finally { $result->closeCursor(); }
    }
}
