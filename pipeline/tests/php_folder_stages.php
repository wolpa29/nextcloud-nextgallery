<?php
declare(strict_types=1);
/** Per-folder pipeline choice: settings, worker inventory and file-page counts. Synthetic data only. */
namespace OCP { interface IConfig {} interface IDBConnection {} interface ICacheFactory {} }
namespace OCP\Security { interface ICrypto {} }
namespace OCP\Files { interface IRootFolder {} interface Folder {} interface File {} class NotFoundException extends \Exception {} class NotPermittedException extends \Exception {} }
namespace OCP\AppFramework\OCS { class OCSException extends \Exception {} class OCSBadRequestException extends OCSException {} class OCSNotFoundException extends OCSException {} }
namespace {
foreach(['Settings','State','Inventory','Library','Metadata','Files'] as $file) require __DIR__.'/../../lib/AI/Service/'.$file.'.php';
use OCA\Nextgallery\AI\Service\{Settings,State,Library,Files};
$checks=0;function check($condition,$message){global $checks;$checks++;if(!$condition)throw new \Exception($message);}
function refused(callable $call,$message){try{$call();}catch(\OCP\AppFramework\OCS\OCSException $e){check(true,$message);return;}throw new \Exception($message);}
class Config implements \OCP\IConfig {
 public array $values=[];public array $user=[];public function __construct(public string $directory=''){}
 public function getAppValue($app,$key,$default=''){return $this->values[$key]??$default;}public function setAppValue($app,$key,$value){$this->values[$key]=$value;}
 public function getUserValue($uid,$app,$key,$default=''){return $this->user[$uid][$key]??$default;}public function setUserValue($uid,$app,$key,$value){$this->user[$uid][$key]=$value;}
 public function getSystemValueString($key){return $this->directory;}
}
class Crypto implements \OCP\Security\ICrypto {public function encrypt($v){return $v;}public function decrypt($v){return $v;}}
$all=Settings::DEFAULT_STAGES;$config=new Config();$settings=new Settings($config,new Crypto());

// Settings: every selected folder has a pipeline list; folders without a choice run all of them.
check($settings->get()['folder_stages']===['/'=>$all],'Default selection does not run every pipeline');
$config->user['alice']['ai_processing']=json_encode(['folders'=>['/','/Screens'],'exclude_folders'=>[]]);
check($settings->forUser('alice')->get()['folder_stages']===['/'=>$all,'/Screens'=>$all],'Saved selection without choices lost pipelines');
$saved=$settings->saveAccount('alice',['folders'=>['/','/Screens/','/Trips'],'folder_stages'=>['/Screens/'=>['ocr'],'/Trips'=>['embeddings','descriptions']]]);
check($saved['folder_stages']===['/'=>$all,'/Screens'=>['ocr'],'/Trips'=>['descriptions','embeddings']],'Pipeline choice not normalized');
check($settings->forUser('alice')->get()['folder_stages']===$saved['folder_stages'],'Pipeline choice not stored');
$before=$config->user;
foreach([['/Screens'=>[]],['/Screens'=>['vision']],['/Screens'=>['ocr','ocr']],['/Screens'=>'ocr'],['/Screens'=>['a'=>'ocr']],['/Screens'=>[['ocr']]],'ocr'] as $bad)
    refused(fn()=>$settings->saveAccount('alice',['folder_stages'=>$bad]),'Invalid pipeline choice accepted: '.json_encode($bad));
check($config->user===$before,'Refused choice changed stored settings');
$saved=$settings->saveAccount('alice',['folders'=>['/Trips']]);
check($saved['folder_stages']===['/Trips'=>['descriptions','embeddings']],'Removed folder kept its choice or kept folder lost it');
$config->user['alice']['ai_processing']=json_encode(['folders'=>['/A','/B'],'folder_stages'=>['/A'=>['bogus'],'/B'=>['ocr']]]);
check($settings->forUser('alice')->get()['folder_stages']===['/A'=>$all,'/B'=>['ocr']],'Corrupt stored choice not read leniently');
$owner=$settings->defaults();$owner['folders']=['/','/Scans'];$owner['folder_stages']=['/Scans'=>['ocr']];
check($settings->save($owner,null,false)['folder_stages']===['/'=>$all,'/Scans'=>['ocr']],'Administrator save dropped pipeline choice');

// Fourth pipeline is available only by explicit folder choice.
check(in_array('faces',Settings::STAGES,true)&&!in_array('faces',$all,true),'Faces fallback is not opt-in');
$faceSelection=$settings->saveAccount('faces-opt-in',['folders'=>['/Photos'],'folder_stages'=>['/Photos'=>['faces']]]);
check($faceSelection['folder_stages']['/Photos']===['faces'],'Explicit faces choice lost');
// The most specific selected folder decides.
$selection=['folders'=>['/','/Photos/Screens','/Photos'],'folder_stages'=>['/'=>$all,'/Photos'=>['descriptions'],'/Photos/Screens'=>['ocr']]];
check(Settings::stagesFor($selection,'/root.jpg')===$all,'Root folder choice ignored');
check(Settings::stagesFor($selection,'Photos/a.jpg')===['descriptions'],'Nested folder choice ignored');
check(Settings::stagesFor($selection,'/Photos/Screens/b.png')===['ocr'],'Most specific folder did not win');
check(Settings::stagesFor($selection,'/Photos/Screenshots/c.png')===['descriptions'],'Prefix without separator matched a sibling');
check(Settings::stagesFor(['folders'=>['/Photos'],'folder_stages'=>[]],'/Other/d.jpg')===[],'Unselected path received pipelines');

// Worker inventory: each pipeline receives only files of folders that run it.
class Cache {public function getNumericStorageId(){return 1;}}
class Storage {public function getCache(){return new Cache();}}
class Dir implements \OCP\Files\Folder {public function __construct(private string $path){}public function isReadable(){return true;}public function getStorage(){return new Storage();}public function getInternalPath(){return 'files'.($this->path==='/'?'':$this->path);}}
class File implements \OCP\Files\File {public function __construct(private string $path){}public function getPath(){return $this->path;}public function isReadable(){return true;}public function getMimeType(){return "image/png";}}
class Home extends Dir {public function __construct(){parent::__construct("/");}public function get($path){return new Dir('/'.$path);}public function getById($id){return [new File(['1'=>'/Photos/a.jpg','2'=>'/Photos/Screens/b.png','3'=>'/root.jpg'][(string)$id])];}public function getRelativePath($path){return $path;}}
class Root implements \OCP\Files\IRootFolder {public function getUserFolder($uid){return new Home();}}
class Result {public function __construct(private array $rows){}public function fetchAll(){return $this->rows;}public function fetch(){return array_shift($this->rows)??false;}public function closeCursor(){}}
class DB implements \OCP\IDBConnection {public function executeQuery($sql,$args=[]){return new Result([
    ['fileid'=>1,'storage'=>1,'path'=>'files/Photos/a.jpg','etag'=>'e1','size'=>10,'mimetype'=>'image/jpeg','analyzed_etag'=>null,'metadata_json'=>null,'updated_at'=>0],
    ['fileid'=>2,'storage'=>1,'path'=>'files/Photos/Screens/b.png','etag'=>'e2','size'=>10,'mimetype'=>'image/png','analyzed_etag'=>null,'metadata_json'=>null,'updated_at'=>0],
    ['fileid'=>3,'storage'=>1,'path'=>'files/root.jpg','etag'=>'e3','size'=>10,'mimetype'=>'image/jpeg','analyzed_etag'=>null,'metadata_json'=>null,'updated_at'=>0]]);}}
$config->user['bob']['ai_processing']=json_encode(['folders'=>$selection['folders'],'folder_stages'=>$selection['folder_stages']]);
$root=new Root();$inventory=(new \OCA\Nextgallery\AI\Service\Inventory($root,new DB(),$settings,new Files($root)))->forUser('bob');
$ids=fn($kind)=>array_column($inventory->page(0,$kind)['items'],'file_id');
check($ids('descriptions')===[1,3],'Description inventory ignores folder choice');
check($ids('ocr')===[2,3],'OCR inventory ignores folder choice');
check($ids('embeddings')===[3],'Embedding inventory ignores folder choice');
check(array_column($inventory->snapshot(),'stages','file_id')===[1=>['descriptions'],2=>['ocr'],3=>$all],'Snapshot lacks folder pipelines');
check($inventory->allowedFile(2,'ocr')->getPath()==='/Photos/Screens/b.png'&&$inventory->allowedFile(2)->getPath()==='/Photos/Screens/b.png','Selected pipeline refused');
refused(fn()=>$inventory->allowedFile(2,'descriptions'),'Deselected pipeline accepted for a file');

// File page: a deselected pipeline is neither pending nor done.
class Snapshot extends \OCA\Nextgallery\AI\Service\Inventory {public function __construct(public array $rows){}public function forUser(string $uid):self{return clone $this;}public function snapshot():array{return $this->rows;}}
$dir=sys_get_temp_dir().'/media-ai-folder-stages-'.bin2hex(random_bytes(8));mkdir($dir);$library=new Library(new Snapshot([
    ['file_id'=>1,'path'=>'Photos/a.jpg','etag'=>'e1','analyzed_etag'=>'e1','ocr_etag'=>null,'updated_at'=>100,'size'=>10,'mime'=>'image/jpeg','stages'=>['descriptions']],
    ['file_id'=>2,'path'=>'Photos/Screens/b.png','etag'=>'e2','analyzed_etag'=>null,'ocr_etag'=>null,'updated_at'=>0,'size'=>10,'mime'=>'image/png','stages'=>['ocr']],
    ['file_id'=>3,'path'=>'clip.mp4','etag'=>'e3','analyzed_etag'=>null,'ocr_etag'=>null,'updated_at'=>0,'size'=>10,'mime'=>'video/mp4','stages'=>$all],
]),$settings,new State(new Config($dir)));
$r=$library->browse();$c=$r['counts'];
check($library->counts()['counts']===$c&&is_int($library->counts()['generated_at']),'Overview totals differ from the file page');
check($c['total']===3&&$c['description_total']===2&&$c['done']===1&&$c['pending']===1,'Description counts include a deselected folder');
check($c['ocr_total']===1&&$c['ocr_pending']===1,'OCR counts include a deselected folder or a video');
check($c['embedding_total']===1&&$c['embedding_pending']===1&&$c['embedded']===0,'Embedding counts include deselected folders');
check($r['schedule']['eligible']===1,'Deselected descriptions counted for the next run');
$screens=$library->browse('Photos/Screens')['files'][0];
check($screens['status']==='not_applicable'&&$screens['ocr_status']==='pending'&&$screens['embedding_status']==='not_applicable','File statuses ignore folder choice');
check(!isset($screens['stages']),'Internal pipeline list exposed');
check($library->browse('Photos/Screens')['folder']['status']==='not_applicable'&&$library->browse('Photos')['folder']['status']==='done','Folder status ignores folder choice');
check($library->browse('','unprocessed')['matched']===1&&$library->browse('','all','',0,'pending')['matched']===1,'Filters list deselected pipelines as pending');
$nodes=$library->nodeStatuses([['file_id'=>2,'folder'=>false,'path'=>'Photos/Screens/b.png'],['file_id'=>9,'folder'=>true,'path'=>'Photos/Screens']]);
check($nodes[2]['status']==='not_applicable'&&$nodes[9]['status']==='not_applicable'&&$nodes[9]['counts']['description_total']===0,'Files column shows deselected pipelines as open');
array_map('unlink',glob($dir.'/*/*')?:[]);array_map('rmdir',glob($dir.'/*')?:[]);@rmdir($dir);
echo "$checks folder pipeline assertions passed\n";
}
