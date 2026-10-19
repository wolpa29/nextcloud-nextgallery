<?php
declare(strict_types=1);
namespace OCP {interface IConfig {} interface IDBConnection {} interface IRequest {public function getHeader(string $key):string;} interface IUserManager {}
 class Server {public static function get(string $class){return new class implements IUserManager {public function userExists($uid){return in_array($uid,['alice','bob','carol'],true);}};}}}
namespace OCP\Security {interface ICrypto {}}
namespace OCP\Files {interface IRootFolder {} interface File {public function getEtag();} interface Folder {} class NotFoundException extends \Exception {} class NotPermittedException extends \Exception {}}
namespace OCP\AppFramework {class Controller {public function __construct(string $app,protected \OCP\IRequest $request){}}}
namespace OCP\AppFramework\Http {class DataResponse {public function __construct(public array $data,public int $status=200){}public function addHeader($k,$v){}}class StreamResponse {}}
namespace OCP\AppFramework\Http\Attribute {#[\Attribute] class PublicPage {} #[\Attribute] class NoCSRFRequired {}}
namespace OCP\AppFramework\OCS {class OCSException extends \Exception {}class OCSBadRequestException extends OCSException {}class OCSNotFoundException extends OCSException {}}
namespace {
$path=__DIR__.'/../../lib/';
foreach(['AI/Service/Settings','AI/Service/State','AI/Service/Metadata','AI/Service/Files','AI/Service/Inventory','AI/Service/Embeddings','AI/Service/SharedResults','Controller/AiWorkerController'] as $file)require $path.$file.'.php';
use OCA\Nextgallery\AI\Service\{Settings,Inventory,Metadata,Embeddings,SharedResults,State};
use OCA\Nextgallery\Controller\AiWorkerController;

class SharedDB implements \OCP\IDBConnection {
 public \PDO $pdo;
 public function __construct(){
  $this->pdo=new \PDO('sqlite::memory:');$this->pdo->setAttribute(\PDO::ATTR_ERRMODE,\PDO::ERRMODE_EXCEPTION);
  $this->pdo->exec('CREATE TABLE nextgallery_ai_shared(id INTEGER PRIMARY KEY,sha256 TEXT,kind TEXT,revision TEXT,user_id TEXT,result TEXT,model TEXT,created_at INTEGER,UNIQUE(sha256,kind,revision))');
  $this->pdo->exec('CREATE TABLE nextgallery_ai_vectors(id INTEGER PRIMARY KEY,user_id TEXT,file_id INTEGER,etag TEXT,space TEXT,frame INTEGER,sample_seconds REAL,dimensions INTEGER,vector_data TEXT,updated_at INTEGER,UNIQUE(user_id,file_id,space,frame))');
 }
 public function executeStatement($sql,$args=[]){$q=$this->pdo->prepare(str_replace('*PREFIX*','',$sql));$q->execute($args);return $q->rowCount();}
 public function executeQuery($sql,$args=[]){$q=$this->pdo->prepare(str_replace('*PREFIX*','',$sql));$q->execute($args);return new class($q){public function __construct(private \PDOStatement $q){}public function fetch(){return $this->q->fetch(\PDO::FETCH_ASSOC);}public function fetchAll(){return $this->q->fetchAll(\PDO::FETCH_ASSOC);}public function fetchOne(){return $this->q->fetchColumn();}public function closeCursor(){$this->q->closeCursor();}};}
 public function insertIgnoreConflict(string $table,array $values):int{$q=$this->pdo->prepare('INSERT OR IGNORE INTO '.$table.' ('.implode(',',array_keys($values)).') VALUES ('.implode(',',array_fill(0,count($values),'?')).')');$q->execute(array_values($values));return $q->rowCount();}
 public function beginTransaction(){$this->pdo->beginTransaction();}public function commit(){$this->pdo->commit();}public function rollBack(){$this->pdo->rollBack();}
}
class SharedConfig implements \OCP\IConfig {
 public array $values=[];public array $user=[];
 public function getAppValue($a,$k,$d=''){return $this->values[$k]??$d;}public function setAppValue($a,$k,$v){$this->values[$k]=$v;}
 public function getUserValue($uid,$a,$k,$d=''){return $this->user[$uid][$k]??$d;}
}
class SharedCrypto implements \OCP\Security\ICrypto {public function encrypt($s){return $s;}public function decrypt($s){return $s;}}
class SharedRequest implements \OCP\IRequest {public string $token='';public string $user='';public function getHeader(string $key):string{return $key==='Authorization'?$this->token:($key==='X-Nextgallery-User'?$this->user:'');}}
class SharedFile implements \OCP\Files\File {public function __construct(public string $etag){}public function getEtag(){return $this->etag;}}
/** Which file IDs each account can read inside its selected folders, with current ETags. */
class SharedInventory extends Inventory {
 public string $uid='';public static array $access=[];public static array $kinds=[];
 public function __construct(){}
 public function forUser(string $uid):self{$copy=clone $this;$copy->uid=$uid;return $copy;}
 public function allowedFile(int $id,?string $kind=null):\OCP\Files\File{if($kind!==null&&!in_array($kind,Settings::STAGES,true))throw new \LogicException('Unknown pipeline '.$kind);if($kind!==null&&isset(self::$kinds[$this->uid])&&!in_array($kind,self::$kinds[$this->uid],true))throw new \OCP\AppFramework\OCS\OCSNotFoundException('Verarbeitung für diesen Ordner nicht ausgewählt');if(!isset(self::$access[$this->uid][$id]))throw new \OCP\AppFramework\OCS\OCSNotFoundException('Bild außerhalb der ausgewählten Ordner');return new SharedFile(self::$access[$this->uid][$id]);}
}
class SharedResetState extends State {
 public array $value=[];public function __construct(){}
 public function forUser(string $uid):self{return $this;}public function forPipeline(string $kind):self{return $this;}
 public function control():array{return $this->value;}
 public function directory():string{$p=sys_get_temp_dir().'/synthetic-shared-reset';if(!is_dir($p))mkdir($p,0700);return $p;}
}
class SharedResetMeta extends Metadata {public function __construct(){}public function reset(string $uid,?string $stage=null):void{}}

$checks=0;function sharedCheck($value,$message){global $checks;$checks++;if(!$value)throw new \RuntimeException($message);}
function rejected(callable $fn):bool{try{$fn();}catch(\OCP\AppFramework\OCS\OCSBadRequestException){return true;}return false;}
$sha=str_repeat('a',64);$rev=str_repeat('b',64);$other=str_repeat('c',64);
$vision=['type'=>'foto','description'=>'Synthetic red bicycle','tags'=>['bicycle'],'objects'=>['bicycle'],'uncertain_details'=>[]];
$frames=[['frame'=>0,'seconds'=>0,'values'=>array_merge([2.],array_fill(0,127,0.))]];

// Service: validation keeps model output only and the first contribution.
$db=new SharedDB();$config=new SharedConfig();$shared=new SharedResults($db,$config);
sharedCheck($shared->enabled(),'Sharing should default to enabled');
sharedCheck($shared->store('alice',$sha,'vision',$rev,$vision),'Vision result not stored');
sharedCheck(!$shared->store('bob',$sha,'vision',$rev,array_replace($vision,['description'=>'Different'])),'Second contribution replaced the first');
sharedCheck($shared->find($sha,'vision',$rev)===['result'=>$vision,'revision'=>$rev,'model'=>null],'Stored vision result changed');
sharedCheck($shared->find($sha,'vision',$other)===null&&$shared->find($sha,'ocr',$rev)===null&&$shared->find($other,'vision',$rev)===null,'Result leaked across revision, stage or content');
// Descriptions/OCR: another revision only on request, the matching one first, else the newest; vectors never.
sharedCheck($shared->find($sha,'vision',$other,true)['revision']===$rev,'Other revision not adopted');
$third=str_repeat('f',64);$shared->store('carol',$sha,'vision',$third,array_replace($vision,['description'=>'Newer model']),['id'=>'vision-model-2','weights'=>null]);$db->pdo->exec("UPDATE nextgallery_ai_shared SET created_at=created_at+10 WHERE revision='$third'");
sharedCheck($shared->find($sha,'vision',$rev,true)['result']===$vision&&$shared->find($sha,'vision',$other,true)['result']['description']==='Newer model','Revision preference wrong');
sharedCheck($shared->find($sha,'vision',$other,true)['model']===['id'=>'vision-model-2','weights'=>null],'Origin model lost');
foreach([['url'=>'http://10.1.10.78:1234'],['id'=>['x']],'model',['id'=>str_repeat('x',201)]] as $bad)sharedCheck(rejected(fn()=>$shared->store('alice',$other,'vision',$rev,$vision,$bad)),'Invalid model accepted');
foreach([array_replace($vision,['provenance'=>['user'=>'alice']]),array_replace($vision,['type'=>'secret']),array_replace($vision,['description'=>'']),'text',[]] as $bad)sharedCheck(rejected(fn()=>$shared->store('alice',$other,'vision',$rev,$bad)),'Invalid vision result accepted');
sharedCheck($shared->store('alice',$sha,'ocr',$rev,['text'=>'SYNTHETIC OCR','source'=>'ocr_model_json']),'OCR result not stored');
foreach([['text'=>['x']],['text'=>'x','path'=>'/alice/private.png'],['text'=>str_repeat('x',500001)]] as $bad)sharedCheck(rejected(fn()=>$shared->store('alice',$other,'ocr',$rev,$bad)),'Invalid OCR result accepted');
sharedCheck($shared->store('alice',$sha,'embedding',$rev,$frames),'Embedding not stored');sharedCheck($shared->find($sha,'embedding',$other,true)===null,'Vectors adopted across spaces');
foreach([[],[['frame'=>1,'seconds'=>0,'values'=>array_fill(0,128,1.)]],[['frame'=>0,'seconds'=>0,'values'=>array_fill(0,12,1.)]]] as $bad)sharedCheck(rejected(fn()=>$shared->store('alice',$other,'embedding',$rev,$bad)),'Invalid embedding accepted');
foreach([['x',$rev,'vision'],[$sha,'x','vision'],[$sha,$rev,'descriptions']] as [$s,$r,$k])sharedCheck(rejected(fn()=>$shared->find($s,$k,$r)),'Invalid key accepted');
sharedCheck(!str_contains($db->pdo->query("SELECT result FROM nextgallery_ai_shared WHERE kind='vision'")->fetchColumn(),'alice'),'Contributor stored inside the result');
$db->pdo->exec("UPDATE nextgallery_ai_shared SET result='{\"type\":\"foto\"}' WHERE kind='vision' AND revision='$rev'");sharedCheck($shared->find($sha,'vision',$rev)===null&&$shared->find($sha,'vision',$rev,true)['revision']===$third,'Corrupt stored result returned');

// Worker endpoints: authentication, account scope, current version and enabled stages.
$db=new SharedDB();$config=new SharedConfig();$config->values['ai_settings']=json_encode(['user_id'=>'alice','embedding'=>['enabled'=>true]]);
$settings=new Settings($config,new SharedCrypto());$token=$settings->workerToken();$shared=new SharedResults($db,$config);$embeddings=new Embeddings($db);
SharedInventory::$access=['alice'=>[7=>'v1'],'bob'=>[7=>'v1'],'carol'=>[]];
$request=new SharedRequest();$controller=fn()=>new AiWorkerController($request,$settings,new SharedInventory(),new SharedResetMeta(),null,$embeddings,$shared);
foreach(['','Bearer wrong'] as $bad){$request->token=$bad;sharedCheck($controller()->sharedResult(7,'v1',$sha,'vision',$rev)->status===401&&$controller()->shareResult(7,'v1',$sha,'vision',$rev,$vision)->status===401,'Unauthenticated shared access');}
$request->token='Bearer '.$token;
$request->user='mallory';sharedCheck($controller()->sharedResult(7,'v1',$sha,'vision',$rev)->status===404,'Unknown account accepted');
$request->user='alice';$response=$controller()->shareResult(7,'v1',$sha,'vision',$rev,$vision,['id'=>'vision-model','weights'=>'q4']);sharedCheck($response->status===200&&$response->data['stored']===true,'Alice could not contribute');
sharedCheck($controller()->shareResult(7,'v1',$sha,'vision',$rev,['type'=>'foto'])->status===400,'Invalid contribution accepted');
$request->user='bob';$response=$controller()->sharedResult(7,'v1',$sha,'vision',$rev);sharedCheck($response->status===200&&$response->data===['result'=>$vision,'revision'=>$rev,'model'=>['id'=>'vision-model','weights'=>'q4']],'Bob did not receive the shared result');
sharedCheck(!array_key_exists('user_id',$response->data)&&!str_contains(json_encode($response->data),'alice'),'Response reveals the contributor');
sharedCheck($controller()->sharedResult(7,'v0',$sha,'vision',$rev)->status===409&&$controller()->sharedResult(7,'',$sha,'vision',$rev)->status===409,'Outdated file version accepted');
sharedCheck($controller()->sharedResult(7,'v1',$sha,'vision',$other)->data['revision']===$rev,'Result of another revision not offered');
$request->user='carol';sharedCheck($controller()->sharedResult(7,'v1',$sha,'vision',$rev)->status===404&&$controller()->shareResult(7,'v1',$other,'vision',$rev,$vision)->status===404,'Account without access reached shared results');
sharedCheck($controller()->sharedResult(8,'v1',$sha,'vision',$rev)->status===404,'Unknown file reached shared results');
$request->user='bob';$config->values['ai_settings']=json_encode(['user_id'=>'alice','ocr_enabled'=>false,'embedding'=>['enabled'=>false]]);
sharedCheck($controller()->sharedResult(7,'v1',$sha,'ocr',$rev)->status===409&&$controller()->sharedResult(7,'v1',$sha,'embedding',$rev)->status===409,'Disabled stage returned shared results');
sharedCheck($controller()->sharedResult(7,'v1',$sha,'vision',$rev)->status===200,'Enabled stage refused');
// A folder that runs only OCR receives no shared descriptions or vectors.
SharedInventory::$kinds['bob']=['ocr'];$config->values['ai_settings']=json_encode(['user_id'=>'alice','embedding'=>['enabled'=>true]]);
sharedCheck($controller()->sharedResult(7,'v1',$sha,'vision',$rev)->status===404&&$controller()->sharedResult(7,'v1',$sha,'embedding',$rev)->status===404&&$controller()->shareResult(7,'v1',$other,'vision',$rev,$vision)->status===404,'Deselected folder pipeline reached shared results');
sharedCheck($controller()->sharedResult(7,'v1',$sha,'ocr',$rev)->status===200,'Folder pipeline refused');
sharedCheck($controller()->publishEmbedding(7,'v1',str_repeat('d',64),$frames)->status===404,'Deselected folder pipeline published vectors');unset(SharedInventory::$kinds['bob']);
$config->values['ai_settings']=json_encode(['user_id'=>'alice','embedding'=>['enabled'=>true]]);
$config->values['ai_share_results']='no';$response=$controller()->sharedResult(7,'v1',$sha,'vision',$rev);
sharedCheck($response->status===200&&$response->data['result']===null&&$response->data['disabled']===true,'Disabled sharing returned results');
sharedCheck($controller()->shareResult(7,'v1',$other,'vision',$rev,$vision)->data['disabled']===true&&$shared->find($other,'vision',$rev)===null,'Disabled sharing stored results');
unset($config->values['ai_share_results']);

// Same file and version: vectors published before sharing existed (group mount).
$space=str_repeat('d',64);$embeddings->publish('alice',7,'v1',$space,$frames);
$request->user='bob';$result=$controller()->sharedResult(7,'v1',$sha,'embedding',$space)->data['result'];
sharedCheck(is_array($result)&&count($result)===1&&$result[0]['frame']===0&&abs($result[0]['values'][0]-1.)<1e-6&&count($result[0]['values'])===128,'Same-file vectors not offered');
sharedCheck($controller()->sharedResult(7,'v1',$sha,'embedding',str_repeat('e',64))->data['result']===null,'Vectors of another space offered');
$request->user='alice';sharedCheck($controller()->sharedResult(7,'v1',$sha,'embedding',$space)->data['result']===null,'Own vectors echoed as shared');
$db->pdo->exec("UPDATE nextgallery_ai_vectors SET etag='v0'");$request->user='bob';sharedCheck($controller()->sharedResult(7,'v1',$sha,'embedding',$space)->data['result']===null,'Vectors of an older version offered');

// Reset and account deletion withdraw the account's own contributions only.
$shared->store('alice',$sha,'ocr',$rev,['text'=>'A']);$shared->store('bob',$other,'ocr',$rev,['text'=>'B']);
$state=new SharedResetState();$state->value=['paused'=>true,'reset_requested'=>true,'reset_kind'=>'ocr'];$request->user='alice';
$reset=new AiWorkerController($request,$settings,new SharedInventory(),new SharedResetMeta(),$state,$embeddings,$shared);
sharedCheck($reset->reset('ocr')->status===200,'Reset refused');
sharedCheck($shared->find($sha,'ocr',$rev)===null&&$shared->find($other,'ocr',$rev)!==null&&$shared->find($sha,'vision',$rev)!==null,'OCR reset removed the wrong shared results');
$shared->forget('alice');sharedCheck($shared->find($sha,'vision',$rev)===null&&$shared->find($other,'ocr',$rev)!==null,'Account deletion left contributions or removed others');
$shared->store('bob',$sha,'embedding',$space,$frames);$shared->forgetKind('embedding');sharedCheck($shared->find($sha,'embedding',$space)===null&&$shared->find($other,'ocr',$rev)!==null,'Embedding reset removed other kinds');
@unlink($state->directory().'/publication.lock');@rmdir($state->directory());
echo "$checks shared result assertions passed\n";
}
