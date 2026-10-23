<?php
declare(strict_types=1);
require '/var/www/html/lib/base.php';
set_exception_handler(function(\Throwable $e):void {fwrite(STDERR,get_class($e).': '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine()."\n");exit(1);});
use OCP\Server;
use OCP\{IDBConnection,IUserSession,IUserManager};
use OCP\Files\IRootFolder;
use OCA\Nextgallery\AI\Service\{Settings,State,Metadata,Inventory};
use OCA\Nextgallery\Service\{SearchIndex,NativeSearch,GallerySearch,Library};
if(!is_file('/test-output/ISOLATED_SYNTHETIC_TEST'))throw new RuntimeException('Synthetic environment required');
$checks=0;
function audioCheck($ok,string $message):void {global $checks;$checks++;if(!$ok)throw new RuntimeException($message);}
$uid='merge-synthetic';$syntheticUser=Server::get(IUserManager::class)->get($uid)??Server::get(IUserManager::class)->createUser($uid,'SyntheticMergeOnly2026!');Server::get(IUserSession::class)->setUser($syntheticUser);
$settings=Server::get(Settings::class);$before=$settings->get();$personalBefore=$settings->forUser($uid)->get();$state=Server::get(State::class)->forUser($uid);$metadata=Server::get(Metadata::class);
$metadata->reset($uid,'audio');
$home=Server::get(IRootFolder::class)->getUserFolder($uid);$folderName='AudioSynthetic-'.bin2hex(random_bytes(4));$folderPath='/'.$folderName;$folder=$home->newFolder($folderName);
foreach(['speech.mp4','other.mp4','silent.mp4'] as $name)if($folder->nodeExists($name))$folder->get($name)->delete();
$one=$folder->newFile('speech.mp4',file_get_contents('/tmp/synthetic-speech.mp4'));$two=$folder->newFile('other.mp4',file_get_contents('/tmp/synthetic-speech.mp4'));$silent=$folder->newFile('silent.mp4',file_get_contents('/tmp/synthetic-no-audio.mp4'));
$home->nodeExists('AudioUnselected')?$home->get('AudioUnselected'):$home->newFolder('AudioUnselected');
$config=$before;$config['folders']=[$folderPath];$config['folder_stages']=[$folderPath=>['audio']];$config['audio']['enabled']=true;$settings->save($config);$settings->saveAccount($uid,$config);
$inventory=Server::get(Inventory::class)->forUser($uid);
$payload=['text'=>'The secret word is pineapple and Vienna.','language'=>'en','segments'=>[['start'=>0.0,'end'=>4.32,'text'=>'The secret word is pineapple and Vienna.']],'duration'=>11,'model'=>'small','no_audio'=>false];
$publish=function($file,string $stage,array $data)use($metadata,$uid):void {$metadata->upsert($uid,Metadata::validate($file->getId(),$file->getEtag(),hash('sha256',$file->getContent()),str_repeat('a',64),$data,[],$stage),$stage);};
$vision=['type'=>'video','description'=>'pineapple description only','tags'=>[],'objects'=>[],'uncertain_details'=>[]];
try{
 audioCheck(!in_array('audio',Settings::stagesFor($before,'/OldFolder/a.mp4'),true),'Legacy folders opted in');
 audioCheck(count($inventory->page(0,'audio')['items'])===3,'Video audio inventory incomplete');
 $publish($one,'audio',['audio'=>$payload]);
 audioCheck($metadata->count($uid,'audio')===1,'Audio publication missing');
 $row=$metadata->get($uid,$one->getId());$stages=Metadata::stages($row);
 audioCheck(isset($stages['audio'])&&!isset($stages['descriptions'])&&!isset($stages['ocr']),'Audio created fake vision/OCR completion');
 $publish($one,'descriptions',['vision'=>$vision]);$publish($two,'descriptions',['vision'=>$vision]);
 $publish($silent,'audio',['audio'=>['text'=>'','language'=>'','segments'=>[],'duration'=>2,'model'=>'small','no_audio'=>true]]);
 audioCheck($metadata->count($uid,'audio')===2,'No-track completion missing');
 audioCheck(count($metadata->search($uid,'Vienna',0,10))===1,'Canonical transcript search missing');
 audioCheck(!$metadata->search($uid,'small',0,10),'Model name must not become searchable speech');
 $stored=json_decode($metadata->get($uid,$one->getId())['metadata_json'],true)['analysis'];
 audioCheck($stored['audio']==$payload&&$stored['vision']['description']===$vision['description'],'Description publication changed transcript');
 $library=Server::get(Library::class);$galleryBefore=$library->settings($uid);$library->save($uid,['roots'=>[$folderPath]]);
 do{$scan=$library->step($uid,false,100,10);}while($scan['running']);
 audioCheck($scan['errors']===0,'Synthetic video catalog scan failed');
 $index=Server::get(SearchIndex::class);$index->ensure($uid);
 Server::get(IUserManager::class)->get('audio-other')??Server::get(IUserManager::class)->createUser('audio-other','SyntheticAudioOther2026!');
 $native=Server::get(NativeSearch::class);$gallery=Server::get(GallerySearch::class);
 $r=$native->search($uid,'pineapple',[$folderPath],phase:'text',source:'audio');
 audioCheck(array_column($r['items'],'id')===[$one->getId()],'Native audio-only search leaked nontranscript results');
 audioCheck($r['items'][0]['search_match']['sources']===['audio'],'Transcript match source missing');
 $g=$gallery->search($uid,'pineapple',phase:'text',source:'audio');
 audioCheck(array_column($g['items'],'id')===[$one->getId()],'Gallery audio-only search failed');
 audioCheck(count($native->search($uid,'pineapple',[$folderPath],phase:'text')['items'])===2,'General search omitted sources');
 audioCheck(!$native->search($uid,'pineapple',['/AudioUnselected'],phase:'text',source:'audio')['items'],'Transcript escaped roots');
 audioCheck(!$native->search('audio-other','pineapple',['/'],phase:'text',source:'audio')['items'],'Transcript escaped account');
 if(!empty($r['search_token'])){
  $rejected=false;try{$native->search($uid,'pineapple',[$folderPath],phase:'text',search_token:$r['search_token'],source:'');}catch(Throwable){$rejected=true;}
  audioCheck($rejected,'Search token reused across source filters');
 }
 $metadata->reset($uid,'descriptions');$stages=Metadata::stages($metadata->get($uid,$one->getId()));
 audioCheck(isset($stages['audio'])&&!isset($stages['descriptions']),'Description reset removed transcript');
 $publish($one,'descriptions',['vision'=>$vision]);$metadata->reset($uid,'audio');$stages=Metadata::stages($metadata->get($uid,$one->getId()));
 audioCheck(!isset($stages['audio'])&&isset($stages['descriptions']),'Audio reset removed descriptions');
 $index->ensure($uid);
 audioCheck(!$native->search($uid,'pineapple',[$folderPath],phase:'text',source:'audio')['items'],'Deleted transcript still searchable');
 audioCheck(count($native->search($uid,'pineapple',[$folderPath],phase:'text')['items'])===1,'Audio reset removed description search');
 audioCheck(hash('sha256',$one->getContent())===hash_file('sha256','/tmp/synthetic-speech.mp4'),'Original video changed');
 echo "$checks real Nextcloud audio publication, search, account, opt-in and deletion assertions passed\n";
}finally{
 $settings->save($before);$settings->saveAccount($uid,$personalBefore);
 if(isset($galleryBefore))Server::get(Library::class)->save($uid,$galleryBefore);
}
