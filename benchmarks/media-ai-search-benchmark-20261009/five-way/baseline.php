<?php
declare(strict_types=1);
namespace OCP { interface IDBConnection {} }
namespace OCP\AppFramework\OCS { class OCSBadRequestException extends \Exception {} }
namespace {
    require '/source/nextcloud-app/media_ai/lib/Service/Metadata.php';
    class DB implements \OCP\IDBConnection {
        public function __construct(public \PDO $pdo) {}
        public function executeQuery(string $sql,array $params): \PDOStatement {
            $stmt=$this->pdo->prepare(str_replace('*PREFIX*','',$sql));$stmt->execute($params);return $stmt;
        }
        public function executeStatement(string $sql,array $params): int {
            $stmt=$this->executeQuery($sql,$params);return $stmt->rowCount();
        }
    }
    $pdo=new PDO('mysql:host=127.0.0.1;port='.getenv('BENCH_DB_PORT').';dbname=benchmark;charset=utf8mb4','root','',[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false]);
    $pdo->exec('DROP TABLE IF EXISTS media_ai_meta');
    $pdo->exec('CREATE TABLE media_ai_meta (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id VARCHAR(64) NOT NULL,file_id BIGINT UNSIGNED NOT NULL,
        etag VARCHAR(255) NOT NULL,sha256 VARCHAR(64) NOT NULL,revision VARCHAR(64) NOT NULL,type VARCHAR(16) NOT NULL,
        description MEDIUMTEXT NOT NULL,ocr_text MEDIUMTEXT NOT NULL,tags_text MEDIUMTEXT NOT NULL,
        objects_text MEDIUMTEXT NOT NULL,metadata_json MEDIUMTEXT NOT NULL,updated_at BIGINT NOT NULL,
        UNIQUE KEY media_ai_user_file(user_id,file_id),KEY media_ai_user(user_id),
        FULLTEXT KEY media_ai_text(description,ocr_text,tags_text,objects_text)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin');
    $data=json_decode(file_get_contents('/bench/dataset.json'),true,512,JSON_THROW_ON_ERROR);
    $metadata=new \OCA\MediaAI\Service\Metadata(new DB($pdo));$keys=[];
    $pdo->beginTransaction();
    foreach($data['documents'] as $d){
        $keys[$d['file_id']]=$d['key'];
        $analysis=['vision'=>['type'=>$d['kind'],'description'=>$d['description'],'tags'=>$d['tags'],
            'objects'=>$d['objects'],'uncertain_details'=>[]],'ocr'=>['text'=>$d['ocr']]];
        $record=\OCA\MediaAI\Service\Metadata::validate($d['file_id'],'synthetic-v1',str_repeat('a',64),str_repeat('b',64),$analysis,[]);
        $metadata->upsert('synthetic',$record);
    }
    $pdo->commit();
    $out=['version'=>$pdo->query('SELECT VERSION()')->fetchColumn(),'sql'=>'Actual Metadata::validate/upsert/search called, binary collation and FULLTEXT index',
        'variables'=>$pdo->query("SHOW VARIABLES WHERE Variable_name IN ('innodb_ft_min_token_size','innodb_ft_enable_stopword','innodb_ft_server_stopword_table')")->fetchAll(),
        'queries'=>[]];
    foreach($data['queries'] as $q){
        $start=hrtime(true);$rows=$metadata->search('synthetic',$q['text'],0,50);
        $out['queries'][$q['id']]=['keys'=>array_map(fn($r)=>$keys[(int)$r['file_id']],$rows),'ms'=>(hrtime(true)-$start)/1e6];
        // Additional proposal, explicitly NOT the current implementation: OR + relevance.
        preg_match_all('/[\p{L}\p{N}_]+/u',$q['text'],$matches);
        $words=array_slice(array_filter($matches[0],fn($w)=>mb_strlen($w)>=3),0,16);
        $term=implode(' ',array_map(fn($w)=>mb_strtolower($w).'*',$words));
        $relaxed=[];
        if($term!==''){
            $stmt=$pdo->prepare('SELECT file_id,MATCH(description,ocr_text,tags_text,objects_text) AGAINST (? IN BOOLEAN MODE) AS score FROM media_ai_meta WHERE user_id=? AND MATCH(description,ocr_text,tags_text,objects_text) AGAINST (? IN BOOLEAN MODE) ORDER BY score DESC,id LIMIT 50');
            $stmt->execute([$term,'synthetic',$term]);$relaxed=$stmt->fetchAll();
        }
        $out['queries'][$q['id']]['relaxed_keys']=array_map(fn($r)=>$keys[(int)$r['file_id']],$relaxed);
    }
    echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
}
