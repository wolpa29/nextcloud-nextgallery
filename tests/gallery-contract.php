<?php
/** Synthetic contracts on the disposable test Nextcloud only. */
declare(strict_types=1);
require '/var/www/html/lib/base.php';
set_exception_handler(function(\Throwable $error){fwrite(STDERR,get_class($error).': '.$error->getMessage()."\n");exit(1);});
use OCP\Server;
use OCP\{IConfig,IUserSession,IUserManager};
use OCP\Files\IRootFolder;
use OCA\Nextgallery\Service\{Library,GallerySearch};
use OCA\Nextgallery\AI\Service\Metadata;
if(!is_file('/test-output/ISOLATED_SYNTHETIC_TEST'))throw new RuntimeException('Synthetic guard missing');
$uid='merge-synthetic';Server::get(IUserSession::class)->setUser(Server::get(IUserManager::class)->get($uid));
$library=Server::get(Library::class);$metadata=Server::get(Metadata::class);$home=Server::get(IRootFolder::class)->getUserFolder($uid);
$checks=0;function verify($v,$m){global $checks;$checks++;if(!$v)throw new RuntimeException($m);}
$lockedLibrary=new class extends Library {public function __construct(){}public function save(string $uid,array $settings):array {throw new \OCP\Lock\LockedException('synthetic-index-lock');}};
$lockedApi=new \OCA\Nextgallery\Controller\ApiController(Server::get(\OCP\IRequest::class),Server::get(IUserSession::class),$lockedLibrary,Server::get(GallerySearch::class),$metadata);
$lockedResponse=$lockedApi->save(['appearance'=>'dark']);verify($lockedResponse->getStatus()===409&&$lockedResponse->getData()['retryable']===true,'Index lock must be retryable, not an internal server error');
$settings=$library->settings($uid);verify($settings['galleries'][0]['roots']===$settings['roots'],'Legacy roots lost');$key=$library->cacheKey($uid,'default');
$folder=$home->nodeExists('TripsSynthetic')?$home->get('TripsSynthetic'):$home->newFolder('TripsSynthetic');
$sample=file_get_contents('/samples/dog.png');$files=[];
foreach(['2013-01','2020-06','2026-10'] as $month){$year=$folder->nodeExists($month)?$folder->get($month):$folder->newFolder($month);for($i=0;$i<40;$i++){$name="$month-SYNTHETIC-".str_pad((string)$i,2,'0',STR_PAD_LEFT).'.png';$files[]=$year->nodeExists($name)?$year->get($name):$year->newFile($name,$sample);}}
$p=$library->save($uid,['createGallery'=>'Reisen · Synthetic','roots'=>['/TripsSynthetic']]);$second=$p['activeGallery'];
verify($library->cacheKey($uid)!==$key,'Galleries share offline identity');verify($library->cacheKey($uid,'default')===$key,'Switch destroys previous cache');
do{$state=$library->step($uid,false,150,20);}while($state['running']);verify(!$state['errors'],'Index errors');
verify($library->summary($uid,'',$second)['total']===120,'Second gallery scope');verify($library->summary($uid,'','default')['total']===2,'First gallery catalog lost');
verify(count($library->items($uid,'','',0,150,false,$second)['items'])===120,'Pagination/scope');
verify(!Server::get(GallerySearch::class)->search($uid,'RECHNUNG','',0,60,$second)['items'],'Cross-gallery AI text leak');
verify(count(Server::get(GallerySearch::class)->search($uid,'RECHNUNG','',0,60,'default')['items'])===1,'Original gallery search lost');
$library->save($uid,['order'=>'desc','grouping'=>'month','language'=>'de']);verify($library->settings($uid,'default')['language']==='de','Language not shared');verify($library->settings($uid,'default')['order']==='desc','Display not shared');
$file=$home->get('MergeSynthetic/invoice.png');$before=$metadata->get($uid,$file->getId());
$data=Metadata::validate($file->getId(),$file->getEtag(),str_repeat('a',64),str_repeat('c',64),['vision'=>['type'=>'foto','description'=>'Should never overwrite','tags'=>[],'objects'=>[],'uncertain_details'=>[]],'ocr'=>['text'=>'NEVEROVERWRITE']],[]);
$metadata->upsert($uid,$data);verify($metadata->get($uid,$file->getId())===$before,'Existing metadata overwritten');
$file->putContent(file_get_contents('/samples/invoice.png'));$data['etag']=$file->getEtag();$metadata->upsert($uid,$data);verify($metadata->get($uid,$file->getId())===$before,'Changed-file metadata overwritten');
// Mark synthetic map coordinates in the test catalog; original fixture stays intact.
$db=Server::get(\OCP\IDBConnection::class);foreach(array_slice($files,0,6) as $i=>$f){$q=$db->getQueryBuilder();$q->select('payload')->from('nextgallery_media')->where($q->expr()->eq('user_id',$q->createNamedParameter($uid)))->andWhere($q->expr()->eq('file_id',$q->createNamedParameter($f->getId())));$payload=json_decode($q->executeQuery()->fetchOne(),true);$payload['metadata']['gps']=[48.2+$i*.04,16.3+$i*.06];$q=$db->getQueryBuilder();$q->update('nextgallery_media')->set('payload',$q->createNamedParameter(json_encode($payload)))->set('latitude',$q->createNamedParameter($payload['metadata']['gps'][0]))->set('longitude',$q->createNamedParameter($payload['metadata']['gps'][1]))->where($q->expr()->eq('user_id',$q->createNamedParameter($uid)))->andWhere($q->expr()->eq('file_id',$q->createNamedParameter($f->getId())))->executeStatement();}
verify(count($library->items($uid,'','',0,200,true,$second)['items'])===6,'Map scope');verify(!$library->items($uid,'','',0,200,true,'default')['items'],'Cross-gallery map leak');
$library->save($uid,['activeGallery'=>$second]);
file_put_contents('/test-output/gallery-contract.json',json_encode(['checks'=>$checks,'secondGallery'=>$second,'media'=>120,'metadataPreserved'=>true],JSON_PRETTY_PRINT));echo "$checks gallery/preservation contracts passed\n";
