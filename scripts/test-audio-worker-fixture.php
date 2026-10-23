<?php
declare(strict_types=1);
require '/var/www/html/lib/base.php';
set_exception_handler(function(Throwable $e):void {fwrite(STDERR,get_class($e).': '.$e->getMessage().' line '.$e->getLine()."\n");exit(1);});
use OCP\Server;
use OCA\Nextgallery\AI\Service\{Settings,State,Metadata};
if(!is_file('/test-output/ISOLATED_SYNTHETIC_TEST'))throw new RuntimeException('Synthetic environment required');
$uid='audio-worker-synthetic';$settings=Server::get(Settings::class);$phase=$argv[1]??'verify';
if($phase==='prepare'){
 $user=Server::get(\OCP\IUserManager::class)->get($uid)??Server::get(\OCP\IUserManager::class)->createUser($uid,'SyntheticAudioWorker2026!');
 Server::get(\OCP\IUserSession::class)->setUser($user);
 $home=Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($uid);$folder=$home->nodeExists('AudioWorker')?$home->get('AudioWorker'):$home->newFolder('AudioWorker');
 foreach(['speech.mp4','no-track.mp4'] as $name)if($folder->nodeExists($name))$folder->get($name)->delete();
 $one=$folder->newFile('speech.mp4',file_get_contents('/tmp/synthetic-speech.mp4'));$two=$folder->newFile('no-track.mp4',file_get_contents('/tmp/synthetic-no-audio.mp4'));
 $value=$settings->get();$value['audio']['enabled']=true;$value['audio']['model']='small';$settings->save($value);
 $settings->saveAccount($uid,['folders'=>['/AudioWorker'],'exclude_folders'=>[],'folder_stages'=>['/AudioWorker'=>['audio']],'enabled'=>false]);
 Server::get(Metadata::class)->reset($uid,'audio');
 $state=Server::get(State::class)->forUser($uid);$state->registerAccount();
 $state->forPipeline('audio')->atomic('control.json',['paused'=>false,'requested'=>true,'run_kind'=>'audio']);
 echo json_encode(['uid'=>$uid,'file_ids'=>[$one->getId(),$two->getId()]]),"\n";
}else{
 $meta=Server::get(Metadata::class);if($meta->count($uid,'audio')!==2)throw new RuntimeException('Worker did not publish both videos');
 Server::get(\OCA\Nextgallery\Service\SearchIndex::class)->ensure($uid);
 $r=Server::get(\OCA\Nextgallery\Service\NativeSearch::class)->search($uid,'pineapple',['/AudioWorker'],phase:'text',source:'audio');
 if(count($r['items'])!==1||$r['items'][0]['search_match']['sources']!==['audio'])throw new RuntimeException('Worker result not searchable');
 echo json_encode(['synthetic'=>true,'transcripts'=>2,'search_hits'=>1,'source'=>'audio']),"\n";
}
