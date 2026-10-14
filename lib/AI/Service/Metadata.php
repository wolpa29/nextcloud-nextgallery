<?php
declare(strict_types=1);
namespace OCA\Nextgallery\AI\Service;
use OCP\IDBConnection;
use OCP\AppFramework\OCS\OCSBadRequestException;
class Metadata {
    public function __construct(private IDBConnection $db,private ?\OCA\Nextgallery\Service\SearchIndexChanges $searchChanges=null) {}
    public static function validate(int $fileId, string $etag, string $sha256, string $revision, array $metadata, array $exif,string $stage=''): array {
        if(!in_array($stage,['','descriptions','ocr'],true))throw new OCSBadRequestException('Invalid metadata stage');
        $vision = $metadata['vision'] ?? ['type'=>'unklar','description'=>'','tags'=>[],'objects'=>[],'uncertain_details'=>[]];
        $ocr = $metadata['ocr']['text'] ?? ($stage==='descriptions'?'':null);
        if ($fileId < 1 || strlen($etag) < 1 || strlen($etag) > 255 || !preg_match('/^[a-f0-9]{64}$/D', $sha256)
            || !preg_match('/^[a-f0-9]{64}$/D', $revision) || !is_string($ocr) || strlen($ocr) > 500000
            || !in_array($vision['type'] ?? null, ['video', 'foto', 'screenshot', 'dokument', 'meme', 'grafik', 'unklar'], true)
            || !is_string($vision['description'] ?? null) || ($stage!=='ocr'&&strlen($vision['description']) < 1) || strlen($vision['description']) > 48000) {
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
        $metadata['vision']=$vision;$metadata['ocr']=$metadata['ocr']??['text'=>$ocr];
        if($stage!==''){$metadata['completed_stages']=$metadata['completed_stages']??[];$metadata['completed_stages'][$stage]=true;$metadata['stage_etags'][$stage]=$etag;}
        $json = json_encode(['analysis' => $metadata, 'source_exif' => $exif], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (strlen($json) > 1048576) { throw new OCSBadRequestException('Metadata too large'); }
        return ['file_id' => $fileId, 'etag' => $etag, 'sha256' => $sha256, 'revision' => $revision,
                'type' => $vision['type'], 'description' => mb_strtolower($vision['description']), 'ocr_text' => mb_strtolower($ocr),
                'tags_text' => mb_strtolower(implode(' ', $vision['tags'])), 'objects_text' => mb_strtolower(implode(' ', $vision['objects'])),
                'metadata_json' => $json, 'updated_at' => time()];
    }
    /** Legacy rows already contain both completed stages; no destructive backfill. */
    public static function stages(array $row):array {
        $analysis=json_decode($row['metadata_json']??'{}',true)['analysis']??[];
        $flags=$analysis['completed_stages']??null;$result=[];
        $description=is_string($analysis['vision']['description']??null)&&$analysis['vision']['description']!=='';
        $ocr=is_string($analysis['ocr']['text']??null)&&($analysis['ocr']['source']??'')!=='skipped_video';
        foreach(['descriptions'=>$description,'ocr'=>$ocr] as $kind=>$complete)if($complete&&($flags===null||!empty($flags[$kind])))$result[$kind]=$analysis['stage_etags'][$kind]??$row['etag'];
        return $result;
    }
    public function upsert(string $uid,array $data,string $stage=''):void {
        if($stage===''){
            $values=['user_id'=>$uid]+$data;
            try{$this->db->executeStatement('INSERT INTO *PREFIX*nextgallery_ai_meta ('.implode(',',array_keys($values)).') VALUES ('.implode(',',array_fill(0,count($values),'?')).')',array_values($values));}
            catch(\Throwable $error){if(!$this->get($uid,(int)$data['file_id']))throw $error;}
            $this->searchChanges?->file($uid,(int)$data['file_id']);return;
        }
        // Caller holds the per-account publication gate. Inference stays independent.
        $previous=$this->getStored($uid,(int)$data['file_id']);$completed=$previous?self::stages($previous):[];
        if(isset($completed[$stage]))return; // Only explicit stage deletion allows replacement.
        $incoming=json_decode($data['metadata_json'],true);$old=$previous?json_decode($previous['metadata_json'],true):[];
        $analysis=$old['analysis']??[];$new=$incoming['analysis'];$key=$stage==='descriptions'?'vision':'ocr';
        $analysis[$key]=$new[$key];
        $analysis['provenance']=array_replace_recursive($analysis['provenance']??[],$new['provenance']??[]);
        $analysis['stage_provenance'][$stage]=$new['provenance']??[];
        $analysis['completed_stages']=array_fill_keys(array_keys($completed),true)+[$stage=>true];
        $analysis['stage_etags']=$completed+[$stage=>$data['etag']];
        foreach($completed as $kind=>$etag)if(!isset($analysis['stage_sources'][$kind]))$analysis['stage_sources'][$kind]=['etag'=>$etag,'sha256'=>$previous['sha256'],'revision'=>$previous['revision']];
        $analysis['stage_sources'][$stage]=['etag'=>$data['etag'],'sha256'=>$data['sha256'],'revision'=>$data['revision']];
        $merged=self::validate((int)$data['file_id'],$data['etag'],$data['sha256'],$data['revision'],$analysis,array_replace($old['source_exif']??[],$incoming['source_exif']??[]),$stage);
        if(!$previous){$this->upsert($uid,$merged);return;}
        $fields=array_keys($merged);$this->db->executeStatement('UPDATE *PREFIX*nextgallery_ai_meta SET '.implode(',',array_map(fn($key)=>$key.'=?',$fields)).' WHERE user_id=? AND file_id=?',array_merge(array_values($merged),[$uid,(int)$data['file_id']]));
        $this->searchChanges?->file($uid,(int)$data['file_id']);
    }
    public function count(string $uid,?string $stage=null): int {
        if($stage!==null)return $this->counts($uid)[$stage]??0;
        $result=$this->db->executeQuery('SELECT COUNT(*) FROM *PREFIX*nextgallery_ai_meta WHERE user_id=?',[$uid]);
        try { return (int)$result->fetchOne(); } finally { $result->closeCursor(); }
    }
    /** Bounded keyset reads also protect buffered MariaDB drivers from large OCR payloads. */
    private function storedRows(string $uid,string $columns):\Generator {
        $cursor=0;
        do{
            $r=$this->db->executeQuery('SELECT file_id,'.$columns.' FROM *PREFIX*nextgallery_ai_meta WHERE user_id=? AND file_id>? ORDER BY file_id LIMIT 20',[$uid,$cursor]);
            try{$rows=$r->fetchAll();}finally{$r->closeCursor();}
            foreach($rows as $row){$cursor=(int)$row['file_id'];yield $row;}
        }while(count($rows)===20);
    }
    /** Count both independent stages in bounded reads for the live account overview. */
    public function counts(string $uid):array {
        $counts=['metadata'=>0,'descriptions'=>0,'ocr'=>0];
        foreach($this->storedRows($uid,'etag,metadata_json') as $row){$counts['metadata']++;foreach(self::stages($row) as $stage=>$etag)$counts[$stage]++;}
        return $counts;
    }
    public function normalizeIndex(): void {
        // Nextcloud tables use binary collation. Normalize indexed copies;
        // metadata_json preserves the complete original case and wording.
        $this->db->executeStatement('UPDATE *PREFIX*nextgallery_ai_meta SET description=LOWER(description),ocr_text=LOWER(ocr_text),tags_text=LOWER(tags_text),objects_text=LOWER(objects_text)',[]);
    }
    public function reset(string $uid,?string $stage=null): void {
        $this->searchChanges?->account($uid);
        if($stage!==null){
            foreach($this->storedRows($uid,'etag,sha256,revision,metadata_json') as $row){$completed=self::stages($row);if(!isset($completed[$stage]))continue;unset($completed[$stage]);if(!$completed){$this->db->executeStatement('DELETE FROM *PREFIX*nextgallery_ai_meta WHERE user_id=? AND file_id=?',[$uid,(int)$row['file_id']]);continue;}
                $wire=json_decode($row['metadata_json'],true);$analysis=$wire['analysis'];unset($analysis[$stage==='descriptions'?'vision':'ocr'],$analysis['stage_provenance'][$stage],$analysis['stage_sources'][$stage]);
                $model=$stage==='descriptions'?'vision':'ocr';foreach(['models','images','inference_attempts','output_channels'] as $field)unset($analysis['provenance'][$field][$model]);
                $analysis['completed_stages']=array_fill_keys(array_keys($completed),true);$analysis['stage_etags']=$completed;$remaining=isset($completed['descriptions'])?'descriptions':'ocr';
                $data=self::validate((int)$row['file_id'],reset($completed),$row['sha256'],$row['revision'],$analysis,$wire['source_exif']??[],$remaining);
                $this->db->executeStatement('UPDATE *PREFIX*nextgallery_ai_meta SET '.implode(',',array_map(fn($key)=>$key.'=?',array_keys($data))).' WHERE user_id=? AND file_id=?',array_merge(array_values($data),[$uid,(int)$row['file_id']]));
            }return;
        }
        $this->db->executeStatement('DELETE FROM *PREFIX*nextgallery_ai_meta WHERE user_id=?',[$uid]);
    }
    private function getStored(string $uid,int $fileId):?array {
        $result=$this->db->executeQuery('SELECT etag,sha256,revision,metadata_json,updated_at FROM *PREFIX*nextgallery_ai_meta WHERE user_id=? AND file_id=?',[$uid,$fileId]);
        try { $row=$result->fetch(); return $row ?: null; } finally { $result->closeCursor(); }
    }
    public function get(string $uid,int $fileId):?array {
        $row=$this->getStored($uid,$fileId);
        if($row!==null)unset($row['sha256'],$row['revision']);
        return $row;
    }
    public function statuses(string $uid, array $ids): array {
        if (!$ids) { return []; }
        $result=$this->db->executeQuery('SELECT file_id,etag,updated_at,metadata_json FROM *PREFIX*nextgallery_ai_meta WHERE user_id=? AND file_id IN ('.implode(',',array_fill(0,count($ids),'?')).')',array_merge([$uid],$ids));
        try { $rows=$result->fetchAll(); } finally { $result->closeCursor(); }
        $indexed=[]; foreach($rows as $row) { $row['stages']=self::stages($row);unset($row['metadata_json']);$indexed[(int)$row['file_id']]=$row; } return $indexed;
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
            $result=$this->db->executeQuery('SELECT id,file_id,etag,description,ocr_text,metadata_json FROM *PREFIX*nextgallery_ai_meta WHERE user_id=? AND id>? AND '.implode(' AND ',$conditions).' ORDER BY id LIMIT '.min(50,max(1,$limit)), $params);
            try { return $result->fetchAll(); } finally { $result->closeCursor(); }
        }
        $query = implode(' ', array_map(fn($word) => '+' . mb_strtolower($word) . '*', $words));
        $result = $this->db->executeQuery('SELECT id,file_id,etag,description,ocr_text,metadata_json FROM *PREFIX*nextgallery_ai_meta WHERE user_id=? AND id>? AND MATCH(description,ocr_text,tags_text,objects_text) AGAINST (? IN BOOLEAN MODE) ORDER BY id LIMIT ' . min(50, max(1, $limit)), [$uid, $cursor, $query]);
        try { return $result->fetchAll(); } finally { $result->closeCursor(); }
    }
}
