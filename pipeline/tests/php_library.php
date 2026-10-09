<?php
declare(strict_types=1);
namespace OCP { interface IConfig {} interface IDBConnection {} interface ICacheFactory {} interface IRequest {} interface IUserSession {} }
namespace OCP\Security { interface ICrypto {} }
namespace OCP\Files { interface IRootFolder {} interface Folder {} interface File {} class NotFoundException extends \Exception {} class NotPermittedException extends \Exception {} }
namespace OCP\AppFramework { class OCSController { public function __construct($name,$request) {} } }
namespace OCP\AppFramework\Http { class DataResponse { public function __construct(public array $data) {} } }
namespace OCP\AppFramework\OCS { class OCSException extends \Exception {} class OCSBadRequestException extends OCSException {} }
namespace {
foreach(['Settings','State','Inventory','Library','Metadata','Files'] as $file) require __DIR__.'/../../lib/AI/Service/'.$file.'.php';
require __DIR__.'/../../lib/Controller/AiMetadataController.php';
$checks=0;function check($condition,$message=''){global $checks;$checks++;if(!$condition)throw new \Exception($message?:'Assertion '.$checks);}
function refused($call){try{$call();}catch(\OCP\AppFramework\OCS\OCSException $e){check(true);return;}throw new \Exception('Expected refusal');}
class Config implements \OCP\IConfig {public array $values=[];public function __construct(public string $directory){}public function getSystemValueString($key){return $this->directory;}public function getAppValue($app,$key,$default=''){return $this->values[$key]??$default;}public function setAppValue($app,$key,$value){$this->values[$key]=$value;}}
class Crypto implements \OCP\Security\ICrypto {public function decrypt($s){return $s;}public function encrypt($s){return $s;}}
class Inventory extends \OCA\Nextgallery\AI\Service\Inventory {public function __construct(public array $rows){}public function snapshot():array{return $this->rows;}}
$dir=sys_get_temp_dir().'/media-ai-library-'.bin2hex(random_bytes(8));mkdir($dir);$config=new Config($dir);$settings=new \OCA\Nextgallery\AI\Service\Settings($config,new Crypto());$state=new \OCA\Nextgallery\AI\Service\State($config);
$db=new \PDO('sqlite:'.$state->directory().'/native.sqlite3');$db->exec('CREATE TABLE files(locator,etag,status,error,retry_at,analyzed_at,publish_error,active,scope)');file_put_contents($state->directory().'/bootstrap.json',json_encode(['url'=>'https://cloud.example']));$scope=hash('sha256',json_encode(['native','https://cloud.example/index.php/apps/nextgallery/ai/worker/','paul',['/'],[]],JSON_UNESCAPED_SLASHES));$insert=$db->prepare('INSERT INTO files VALUES(?,?,?,?,?,?,?,1,?)');
$insert->execute([2,'v1','pending',null,0,null,null,$scope]);$insert->execute([3,'v1','error','Test error',time()+3600,null,null,$scope]);$insert->execute([4,'old','done',null,0,100,null,$scope]);$insert->execute([5,'v1','done',null,0,100,'Publish error',$scope]);
function item($id,$path,$etag='v1',$analyzed=null){return ['file_id'=>$id,'path'=>$path,'etag'=>$etag,'analyzed_etag'=>$analyzed,'updated_at'=>$analyzed?100:0,'size'=>500,'mime'=>'image/jpeg'];}
$insert->execute([1,'v1','done',null,0,100,null,$scope]);
$insert->execute([1,'v1','error','Other scope',0,100,null,'other-selection']);
$rows=[item(1,'Photos/Done/a.jpg','v1','v1'),item(2,'Photos/Queue/b.jpg','v1','v1'),item(3,'Photos/Queue/c.jpg'),item(4,'Photos/Queue/d.jpg','v2','old'),item(5,'Photos/Queue/e.jpg','v1','v1'),item(6,'root.jpg')];
for($i=10;$i<215;$i++)$rows[]=item($i,'Photos/Many/'.$i.'.jpg');
$inventory=new Inventory($rows);$library=new \OCA\Nextgallery\AI\Service\Library($inventory,$settings,$state);$before=hash_file('sha256',$state->directory().'/native.sqlite3');
$r=$library->browse();check($r['counts']['total']===211);check($r['counts']['done']===3);check($r['counts']['error']===1);check($r['counts']['changed']===1);check($r['counts']['publishing']===0);check($r['schedule']['eligible']===206);check($r['files'][0]['path']==='root.jpg');check($r['recent'][0]['file_id']===1);check($r['folder']['status']==='error');
$r=$library->browse('','analyzed');check($r['matched']===4);check(count(array_filter($r['files'],fn($f)=>$f['status']==='changed'))===1);check(count(array_filter($r['files'],fn($f)=>!$f['has_metadata']))===0);
$r=$library->browse('Photos/Done');check($r['folder']['status']==='done');check(count($r['files'])===1);$r=$library->browse('Photos/Queue','pending');check($r['matched']===1);check(array_column($r['files'],'status')===['error']);check($r['files'][0]['error']==='Test error');
$r=$library->browse('Photos/Many');check(count($r['files'])===100);check($r['next_offset']===100);check($r['matched']===205);$page=$library->browse('Photos/Many','all','',200);check(count($page['files'])===5);check($page['next_offset']===null);$r=$library->browse('','all','root');check($r['matched']===1);check($r['files'][0]['file_id']===6);
foreach([['../Secret'],['Photos/../Secret'],['missing'],['','invalid'],['','all','',-1],['','all',str_repeat('x',201)]] as $args)refused(fn()=>$library->browse(...$args));
check(hash_file('sha256',$state->directory().'/native.sqlite3')===$before,'Library wrote to worker DB');
$inventory->rows[]=item(300,'Other/current.jpg','v1','v1');$future=$library->browse();check($future['counts']['done']===4);check($future['schedule']['eligible']===206,'Untracked selected result was missing from next scan forecast');
$nodes=$library->nodeStatuses([['file_id'=>1,'folder'=>false,'path'=>'Photos/Done/a.jpg'],['file_id'=>8,'folder'=>true,'path'=>'Photos/Done'],['file_id'=>9,'folder'=>true,'path'=>'Elsewhere']]);check($nodes[1]['status']==='done');check($nodes[8]['status']==='done');check($nodes[8]['selected_only']);check($nodes[9]['status']==='unselected');
class Request implements \OCP\IRequest {}
class Session implements \OCP\IUserSession {public string $uid='paul';public function getUser(){return new class($this->uid){public function __construct(private $uid){}public function getUID(){return $this->uid;}};}}
class Node implements \OCP\Files\File {public function __construct(public int $id,public bool $readable=true,public string $etag='v1'){}public function isReadable(){return $this->readable;}public function getMimeType(){return 'image/jpeg';}public function getEtag(){return $this->etag;}public function getPath(){return 'Photos/Done/a.jpg';}}
class Home {public array $nodes;public function __construct(){$this->nodes=[1=>new Node(1),7=>new Node(7,false),8=>new Node(8,true,'v2')];}public function getById($id){return isset($this->nodes[$id])?[$this->nodes[$id]]:[];}public function getRelativePath($path){return $path;}}
class Root implements \OCP\Files\IRootFolder {public array $calls=[];public function getUserFolder($uid){$this->calls[]=$uid;return new Home();}}
class Metadata extends \OCA\Nextgallery\AI\Service\Metadata {public array $calls=[];public function __construct(){}public function statuses(string $uid,array $ids):array{$this->calls[]=[$uid,$ids];return [1=>['etag'=>'v1','updated_at'=>100],8=>['etag'=>'v1','updated_at'=>100]];}}
$session=new Session();$root=new Root();$metadata=new Metadata();$controller=new \OCA\Nextgallery\Controller\AiMetadataController(new Request(),$session,new \OCA\Nextgallery\AI\Service\Files($root),$metadata,$root,$library,$settings);$r=$controller->statuses([1,7,8,99])->data['statuses'];check($r[1]['status']==='done');check(!isset($r[7])&&!isset($r[99]),'Unreadable/missing node status leaked');check($r[8]['status']==='changed');check($metadata->calls[0]===['paul',[1,8]]);$session->uid='bob';$r=$controller->statuses([1])->data['statuses'];check($root->calls[1]==='bob');check($metadata->calls[1][0]==='bob');check($r[1]['status']==='done');refused(fn()=>$controller->statuses(range(1,101)));refused(fn()=>$controller->statuses(['1']));refused(fn()=>$controller->statuses([0]));
$db=null;foreach(glob($state->directory().'/*') as $file)unlink($file);rmdir($state->directory());rmdir($dir);echo "$checks library/status assertions passed\n";
}
