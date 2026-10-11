<?php
declare(strict_types=1);
namespace OCP {interface IDBConnection {}}
namespace OCP\AppFramework\OCS {class OCSBadRequestException extends \Exception {}}
namespace {
require __DIR__.'/../../lib/AI/Service/Metadata.php';
class StageDB implements \OCP\IDBConnection {
 public \PDO $pdo;
 public function __construct(){$this->pdo=new \PDO('sqlite::memory:');$this->pdo->setAttribute(\PDO::ATTR_ERRMODE,\PDO::ERRMODE_EXCEPTION);$this->pdo->exec('CREATE TABLE nextgallery_ai_meta(id INTEGER PRIMARY KEY,user_id TEXT,file_id INTEGER,etag TEXT,sha256 TEXT,revision TEXT,type TEXT,description TEXT,ocr_text TEXT,tags_text TEXT,objects_text TEXT,metadata_json TEXT,updated_at INTEGER,UNIQUE(user_id,file_id))');}
 public function executeStatement($sql,$args=[]){$q=$this->pdo->prepare(str_replace('*PREFIX*','',$sql));$q->execute($args);return $q->rowCount();}
 public function executeQuery($sql,$args=[]){$q=$this->pdo->prepare(str_replace('*PREFIX*','',$sql));$q->execute($args);return new class($q){public function __construct(private \PDOStatement $q){}public function fetch(){return $this->q->fetch(\PDO::FETCH_ASSOC);}public function fetchAll(){return $this->q->fetchAll(\PDO::FETCH_ASSOC);}public function fetchOne(){return $this->q->fetchColumn();}public function closeCursor(){$this->q->closeCursor();}};}
 public function getDatabaseProvider(){return 'sqlite';}
}
use OCA\Nextgallery\AI\Service\Metadata;
$n=0;function stageCheck($x,$message){global $n;$n++;if(!$x)throw new \RuntimeException($message);}
$db=new StageDB();$meta=new Metadata($db);$vision=['type'=>'foto','description'=>'SYNTHETICDESCRIPTION','tags'=>['SyntheticTag'],'objects'=>[],'uncertain_details'=>[]];
$publish=function(string $uid,int $id,string $stage,array $value)use($meta){$meta->upsert($uid,Metadata::validate($id,'v1',str_repeat('a',64),str_repeat('b',64),$value,[],$stage),$stage);};
$publish('alice',1,'ocr',['ocr'=>['text'=>'SYNTHETICOCR'],'provenance'=>['models'=>['ocr'=>['id'=>'ocr-model']]]]);
stageCheck($meta->count('alice')===1&&$meta->count('alice','ocr')===1&&$meta->count('alice','descriptions')===0,'OCR-only publication pretends to have a description');
stageCheck(count($meta->search('alice','SYNTHETICOCR',0,10))===1,'OCR-only text is not searchable');
$publish('alice',1,'descriptions',['vision'=>$vision,'provenance'=>['models'=>['vision'=>['id'=>'vision-model']]]]);
$x=json_decode($meta->get('alice',1)['metadata_json'],true)['analysis'];
stageCheck($x['ocr']['text']==='SYNTHETICOCR'&&$x['vision']===$vision,'Description overwrote OCR');
stageCheck($x['provenance']['models']['ocr']['id']==='ocr-model'&&$x['provenance']['models']['vision']['id']==='vision-model','Stage provenance lost');
stageCheck($meta->count('alice','ocr')===1&&$meta->count('alice','descriptions')===1,'Completed stages counted incorrectly');
$publish('alice',1,'ocr',['ocr'=>['text'=>'UNREQUESTEDREPLACEMENT']]);stageCheck(json_decode($meta->get('alice',1)['metadata_json'],true)['analysis']['ocr']['text']==='SYNTHETICOCR','Model change silently replaced OCR');
$publish('alice',2,'descriptions',['vision'=>$vision]);$publish('alice',2,'ocr',['ocr'=>['text'=>'']]);stageCheck($meta->count('alice','ocr')===2,'Empty successful OCR was treated as pending');
$publish('bob',1,'ocr',['ocr'=>['text'=>'BOBONLY']]);stageCheck($meta->count('bob','descriptions')===0&&count($meta->search('alice','BOBONLY',0,10))===0,'Other user stage leaked');
$meta->reset('alice','ocr');stageCheck($meta->count('alice','ocr')===0&&$meta->count('alice','descriptions')===2,'OCR reset removed descriptions');stageCheck(count($meta->search('alice','SYNTHETICOCR',0,10))===0,'OCR reset left searchable text');stageCheck($meta->count('bob','ocr')===1,'Reset affected another user');
$publish('alice',1,'ocr',['ocr'=>['text'=>'NEWTEXT']]);$meta->reset('alice','descriptions');stageCheck($meta->count('alice','descriptions')===0&&$meta->count('alice','ocr')===1,'Description reset removed OCR');stageCheck(count($meta->search('alice','SYNTHETICDESCRIPTION',0,10))===0&&count($meta->search('alice','NEWTEXT',0,10))===1,'Description reset corrupted search');
$legacy=Metadata::validate(3,'v1',str_repeat('a',64),str_repeat('b',64),['vision'=>$vision,'ocr'=>['text'=>'LEGACYOCR']],['camera'=>'synthetic']);$meta->upsert('alice',$legacy);stageCheck($meta->count('alice','descriptions')===1&&$meta->count('alice','ocr')===2,'Legacy data not adopted');$meta->reset('alice','descriptions');stageCheck(json_decode($meta->get('alice',3)['metadata_json'],true)['analysis']['ocr']['text']==='LEGACYOCR','Legacy OCR lost during description reset');
$meta->reset('alice');stageCheck($meta->count('alice')===0&&$meta->count('bob')===1,'Whole account reset not scoped');
echo "$n partial-stage metadata/search/preservation/reset assertions passed\n";
}
