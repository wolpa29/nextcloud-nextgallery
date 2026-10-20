<?php
declare(strict_types=1);
namespace OCA\Nextgallery\AI\Service;

use OCP\{IDBConnection,IConfig};
use OCP\AppFramework\OCS\OCSBadRequestException;

/** Internal, file-bound model output. Callers prove live access before lookup.
 * Mutations run inside Faces' account + global artifact lock and DB transaction.
 * Account-specific detections remain projections for grouping and corrections.
 */
class FaceArtifacts {
    public const MODEL_SPACE = Faces::MODEL_SPACE;
    public const PREPROCESS_VERSION = 'oriented-multiscale-yunet-sface-v1';
    public function __construct(private IDBConnection $db,private IConfig $config,private Settings $settings) {}
    public function enabled():bool {return $this->config->getAppValue('nextgallery','ai_share_results','yes')!=='no';}
    public static function revision(array $config):string {
        $faces=$config['faces']??$config;
        $manifest=json_decode(file_get_contents(dirname(__DIR__,3).'/worker/faces_models.json'),true,64,JSON_THROW_ON_ERROR);
        return hash('sha256',json_encode([
            'space'=>$manifest['space'],'detector'=>$manifest['detector']['sha256'],'recognizer'=>$manifest['recognizer']['sha256'],
            'preprocess'=>self::PREPROCESS_VERSION,'detection_threshold'=>(float)($faces['detection_threshold']??0.9),
            'min_face_size'=>(int)($faces['min_face_size']??32),'max_edge'=>(int)($faces['max_edge']??2048),
        ],JSON_THROW_ON_ERROR));
    }
    public function currentRevision():string {return self::revision($this->settings->get());}
    /** Older rows have no recorded inference options. Only an explicitly verified
     * operator pin can establish their provenance; never infer it from current settings. */
    public function legacyRevision():string {return $this->config->getAppValue('nextgallery','ai_faces_legacy_inference_revision','');}
    public static function sanitizeRawDetections(array $detections):array {
        if(!array_is_list($detections)||count($detections)>100)throw new OCSBadRequestException('Ungültige Gesichtsliste');
        $clean=[];
        foreach($detections as $d){
            if(!is_array($d))throw new OCSBadRequestException('Ungültiger Gesichtsbefund');
            $score=$d['score']??null;
            if((!is_int($score)&&!is_float($score))||!is_finite((float)$score)||$score<0||$score>1)throw new OCSBadRequestException('Ungültiger Erkennungsscore');
            $vector=$d['vector']??null;
            if(!is_array($vector)||!array_is_list($vector))throw new OCSBadRequestException('Ungültiger Gesichtsvektor');
            $clean[]=['bbox'=>Faces::validateBbox($d['bbox']??null),'vector'=>Faces::normalizeVector($vector),'score'=>(float)$score];
        }
        return $clean;
    }
    private function rows(string $sql,array $params=[]):array {
        $r=$this->db->executeQuery($sql,$params);try{return $r->fetchAll();}finally{$r->closeCursor();}
    }
    private static function key(int $fid,string $etag,string $revision):void {
        if($fid<1||$etag===''||strlen($etag)>255||!preg_match('/^[a-f0-9]{64}$/D',$revision))throw new OCSBadRequestException('Ungültiger Befundschlüssel');
    }
    /** [] means a successful image with no faces; null alone means cache miss. */
    public function find(int $fileId,string $etag,string $revision):?array {
        self::key($fileId,$etag,$revision);
        if(!$this->enabled())return null;
        $rows=$this->rows('SELECT a.face_count,a.detections_json FROM *PREFIX*nextgallery_faces_artifacts a WHERE a.file_id=? AND a.etag=? AND a.inference_revision=? AND EXISTS (SELECT 1 FROM *PREFIX*nextgallery_faces_artifact_refs r WHERE r.artifact_id=a.id)',[$fileId,$etag,$revision]);
        foreach($rows as $row){
            try{
                $d=json_decode($row['detections_json'],true,64,JSON_THROW_ON_ERROR);
                if(is_array($d)&&count($d)===(int)$row['face_count'])return self::sanitizeRawDetections($d);
            }catch(\JsonException|OCSBadRequestException){}
        }
        // Safe lazy reuse from another canonical projection. Legacy signatures are
        // accepted only with explicit legacy provenance; new unsigned writes use 'unknown'.
        $legacy=$this->legacyRevision()===$revision;
        $q=$this->db->getQueryBuilder();
        $q->select('user_id','face_count')->from('nextgallery_faces_files')
          ->where($q->expr()->eq('file_id',$q->createNamedParameter($fileId)))
          ->andWhere($q->expr()->eq('etag',$q->createNamedParameter($etag)))
          ->andWhere($legacy?$q->expr()->orX($q->expr()->eq('inference_revision',$q->createNamedParameter($revision)),$q->expr()->eq('inference_revision',$q->createNamedParameter('legacy'))):$q->expr()->eq('inference_revision',$q->createNamedParameter($revision)))
          ->orderBy('id','DESC')->setMaxResults(5);
        $r=$q->executeQuery();try{$files=$r->fetchAll();}finally{$r->closeCursor();}
        foreach($files as $ff){
            $dets=$this->rows('SELECT bbox_x,bbox_y,bbox_w,bbox_h,score,vector_data,model_space FROM *PREFIX*nextgallery_faces_detections WHERE user_id=? AND file_id=? AND etag=?',[$ff['user_id'],$fileId,$etag]);
            if(count($dets)!==(int)$ff['face_count']||count($dets)>100)continue;
            $raw=[];
            foreach($dets as $d){
                $bytes=base64_decode((string)$d['vector_data'],true);
                if($d['model_space']!==self::MODEL_SPACE||$bytes===false||strlen($bytes)!==512)continue 2;
                $raw[]=['bbox'=>[(float)$d['bbox_x'],(float)$d['bbox_y'],(float)$d['bbox_w'],(float)$d['bbox_h']],'vector'=>array_values(unpack('g*',$bytes)),'score'=>(float)$d['score']];
            }
            try{return self::sanitizeRawDetections($raw);}catch(OCSBadRequestException){}
        }
        return null;
    }
    /** Called only after generation/rules/file checks in the publication transaction. */
    public function store(string $uid,int $fileId,string $etag,string $revision,array $rawDetections):void {
        self::key($fileId,$etag,$revision);
        if(!$this->enabled())return;
        $raw=self::sanitizeRawDetections($rawDetections);$now=time();$json=json_encode($raw,JSON_THROW_ON_ERROR);
        $this->db->insertIgnoreConflict('nextgallery_faces_artifacts',['file_id'=>$fileId,'etag'=>$etag,'inference_revision'=>$revision,'face_count'=>count($raw),'detections_json'=>$json,'created_at'=>$now]);
        $rows=$this->rows('SELECT id FROM *PREFIX*nextgallery_faces_artifacts WHERE file_id=? AND etag=? AND inference_revision=?',[$fileId,$etag,$revision]);
        if(!$rows)throw new \RuntimeException('Gesichtsbefund konnte nicht gespeichert werden');
        $aid=(int)$rows[0]['id'];
        // A valid new inference also repairs a corrupt cache row. Raw output never
        // comes from personal group configuration or correction fields.
        $this->db->executeStatement('UPDATE *PREFIX*nextgallery_faces_artifacts SET detections_json=?,face_count=? WHERE id=?',[$json,count($raw),$aid]);
        $this->db->insertIgnoreConflict('nextgallery_faces_artifact_refs',['artifact_id'=>$aid,'user_id'=>$uid,'file_id'=>$fileId,'etag'=>$etag,'created_at'=>$now]);
    }
    private function prune(array $ids):void {
        foreach(array_chunk(array_values(array_unique($ids)),500) as $chunk){
            if(!$chunk)continue;
            $marks=implode(',',array_fill(0,count($chunk),'?'));
            $this->db->executeStatement('DELETE FROM *PREFIX*nextgallery_faces_artifacts WHERE id IN ('.$marks.') AND NOT EXISTS (SELECT 1 FROM *PREFIX*nextgallery_faces_artifact_refs r WHERE r.artifact_id=*PREFIX*nextgallery_faces_artifacts.id)',$chunk);
        }
    }
    public function forget(string $uid):void {
        $rows=$this->rows('SELECT artifact_id FROM *PREFIX*nextgallery_faces_artifact_refs WHERE user_id=?',[$uid]);
        $this->db->executeStatement('DELETE FROM *PREFIX*nextgallery_faces_artifact_refs WHERE user_id=?',[$uid]);
        $this->config->deleteUserValue($uid,'nextgallery','ai_faces_cleanup_cursor');
        $this->prune(array_map(fn($r)=>(int)$r['artifact_id'],$rows));
    }
    /** Bounded sweep cursor avoids starving later files in a large account. */
    public function cleanupUser(string $uid,callable $allowed):void {
        $cursor=(int)$this->config->getUserValue($uid,'nextgallery','ai_faces_cleanup_cursor','0');
        $q=$this->db->getQueryBuilder();$q->select('id','artifact_id','file_id','etag')->from('nextgallery_faces_artifact_refs')
          ->where($q->expr()->eq('user_id',$q->createNamedParameter($uid)))->andWhere($q->expr()->gt('id',$q->createNamedParameter($cursor)))->orderBy('id')->setMaxResults(1000);
        $r=$q->executeQuery();try{$rows=$r->fetchAll();}finally{$r->closeCursor();}
        $stale=[];
        foreach($rows as $row){
            $cursor=(int)$row['id'];
            if(!$allowed((int)$row['file_id'],(string)$row['etag'])){
                $this->db->executeStatement('DELETE FROM *PREFIX*nextgallery_faces_artifact_refs WHERE id=?',[(int)$row['id']]);$stale[]=(int)$row['artifact_id'];
            }
        }
        $this->prune($stale);
        $this->config->setUserValue($uid,'nextgallery','ai_faces_cleanup_cursor',count($rows)===1000?(string)$cursor:'0');
    }
    /** Caller holds the same global mutation lock as publication and deletion. */
    public function globalCleanup():void {
        $q=$this->db->getQueryBuilder();$q->select('a.id')->from('nextgallery_faces_artifacts','a')->leftJoin('a','filecache','f','a.file_id=f.fileid')
          ->where($q->expr()->orX($q->expr()->isNull('f.fileid'),$q->expr()->neq('f.etag','a.etag')))->setMaxResults(500);
        $r=$q->executeQuery();try{$rows=$r->fetchAll();}finally{$r->closeCursor();}
        foreach($rows as $row){$id=(int)$row['id'];$this->db->executeStatement('DELETE FROM *PREFIX*nextgallery_faces_artifact_refs WHERE artifact_id=?',[$id]);$this->db->executeStatement('DELETE FROM *PREFIX*nextgallery_faces_artifacts WHERE id=?',[$id]);}
        $this->db->executeStatement('DELETE FROM *PREFIX*nextgallery_faces_artifact_refs WHERE artifact_id NOT IN (SELECT id FROM *PREFIX*nextgallery_faces_artifacts)');
        $q=$this->db->getQueryBuilder();$q->select('a.id')->from('nextgallery_faces_artifacts','a')->leftJoin('a','nextgallery_faces_artifact_refs','r','a.id=r.artifact_id')->where($q->expr()->isNull('r.id'))->setMaxResults(500);
        $r=$q->executeQuery();try{$rows=$r->fetchAll();}finally{$r->closeCursor();}
        $this->prune(array_map(fn($r)=>(int)$r['id'],$rows));
    }
}
