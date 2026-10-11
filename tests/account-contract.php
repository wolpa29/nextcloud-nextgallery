<?php
/** Account isolation and independent deletion. Disposable synthetic Nextcloud only. */
declare(strict_types=1);
require '/var/www/html/lib/base.php';
set_exception_handler(function(\Throwable $error){fwrite(STDERR,get_class($error).': '.$error->getMessage()."\n");exit(1);});
use OCP\Server;
use OCA\Nextgallery\AI\Service\{Settings,State,Metadata,Embeddings};
use OCA\Nextgallery\Controller\{AiAccountController,AiAdminController};
if(!is_file('/test-output/ISOLATED_SYNTHETIC_TEST'))throw new RuntimeException('Synthetic guard missing');
$settings=Server::get(Settings::class);$state=Server::get(State::class);$metadata=Server::get(Metadata::class);$vectors=Server::get(Embeddings::class);$users=Server::get(\OCP\IUserManager::class);$session=Server::get(\OCP\IUserSession::class);$checks=0;
function expectAccount($value,$message){global $checks;$checks++;if(!$value)throw new RuntimeException($message);}
if(($argv[1]??'prepare')==='prepare'){
 $value=$settings->get();$value['analysis_enabled']=true;$value['embedding']['enabled']=true;$settings->save($value);$ids=[];
 foreach(['account-alice','account-bob'] as $uid){
  $user=$users->get($uid)??$users->createUser($uid,'SyntheticAccountOnly2026!');$session->setUser($user);$home=Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($uid);$folder=$home->nodeExists('Synthetic')?$home->get('Synthetic'):$home->newFolder('Synthetic');$file=$folder->nodeExists('test.png')?$folder->get('test.png'):$folder->newFile('test.png',file_get_contents('/samples/dog.png'));
  $settings->saveAccount($uid,['folders'=>['/Synthetic'],'enabled'=>false]);$own=$state->forUser($uid);$own->registerAccount();$own->atomic('control.json',['paused'=>true,'requested'=>false]);$own->atomic('status.json',['state'=>'paused','stats'=>['done'=>2,'pending'=>0,'error'=>0],'embedding_service'=>['url'=>'ADMINONLY'],'embedding_models'=>['lite'=>['installed'=>true]]]);
  $metadata->upsert($uid,Metadata::validate($file->getId(),$file->getEtag(),str_repeat('a',64),str_repeat('b',64),['vision'=>['type'=>'foto','description'=>$uid.' synthetic description','tags'=>[],'objects'=>[],'uncertain_details'=>[]],'ocr'=>['text'=>'SYNTHETIC']],[]));
  $vectors->publish($uid,$file->getId(),$file->getEtag(),str_repeat('a',64),[['frame'=>0,'seconds'=>0,'values'=>array_merge([1.],array_fill(0,127,0.))]]);$ids[$uid]=$file->getId();
 }
 foreach(['account-alice','account-bob','synthetic-admin'] as $uiUser)Server::get(\OCA\Nextgallery\Service\Library::class)->save($uiUser,['language'=>'de']);
 $state->atomic('service.json',['heartbeat_at'=>time()+1800,'active_user_id'=>null]);$state->atomic('status.json',['state'=>'paused','heartbeat_at'=>time()+1800]);
 $session->setUser($users->get('account-alice'));$controller=Server::get(AiAccountController::class);$config=$controller->config()->getData();
 expectAccount($config['user_id']==='account-alice'&&$config['metadata_count']===1&&$config['embedding_count']===1,'Personal counts wrong');
 foreach(['vision','ocr','embedding','api_key','url','can_configure_models'] as $key)expectAccount(!array_key_exists($key,$config),'Admin settings leaked: '.$key);
 $status=$controller->status()->getData();expectAccount(!isset($status['embedding_service'])&&!isset($status['embedding_models']),'Admin model status leaked');
 expectAccount($state->forUser('account-alice')->directory()!==$state->forUser('account-bob')->directory(),'Account queues shared');
 $rows=$controller->library('Synthetic')->getData()['files'];expectAccount(array_column($rows,'file_id')===[$ids['account-alice']],'Other account files leaked');expectAccount($rows[0]['has_embedding'],'Current vector status absent');
 expectAccount($controller->result($ids['account-bob'])->getStatus()===404,'Other account metadata readable by ID');
 expectAccount(Server::get(AiAdminController::class)->save([])->getStatus()===403,'Ordinary user changed admin config');
 expectAccount($controller->delete('embeddings','embeddings',0)->getStatus()===409,'Stale delete count accepted');
 expectAccount($controller->control('start','descriptions')->getStatus()===200,'Description start unavailable');$a=$state->forUser('account-alice');expectAccount($a->control()['run_kind']==='descriptions','Wrong run selection');expectAccount(empty($state->forUser('account-bob')->control()['requested']),'Other account queue changed');
 $a->atomic('control.json',['paused'=>true,'requested'=>false]);expectAccount($controller->control('start','embeddings')->getStatus()===200,'Embedding start unavailable');$a->atomic('control.json',['paused'=>true,'requested'=>false]);
 $session->setUser($users->get('synthetic-admin'));$admin=Server::get(AiAdminController::class);$before=$settings->get();$changed=$before;$changed['embedding']['max_edge']=1024;expectAccount($admin->save($changed)->getStatus()===409,'Unconfirmed global model change accepted');expectAccount($settings->get()===$before,'Unconfirmed change modified settings');expectAccount($admin->save($changed,$admin->embeddingChangePreview($changed)->getData()['config_id'],'reset',false)->getStatus()===200,'Confirmed admin model change rejected');
 foreach(['account-alice','account-bob'] as $uid){expectAccount($vectors->count($uid)===0,'Central model change failed to reset account '.$uid);$f=Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($uid)->get('Synthetic/test.png');$vectors->publish($uid,$f->getId(),$f->getEtag(),str_repeat('a',64),[['frame'=>0,'seconds'=>0,'values'=>array_merge([1.],array_fill(0,127,0.))]]);$state->forUser($uid)->atomic('embedding-rebuild.json',[]);}
 $settings->save($before);$withoutOwner=$before;$withoutOwner['user_id']='nonexistent-synthetic-owner';$settings->save($withoutOwner);expectAccount($admin->save($withoutOwner)->getStatus()===200,'Global model configuration depends on an existing legacy owner');$settings->save($before);$session->setUser($users->get('account-alice'));
 file_put_contents('/test-output/accounts.json',json_encode(['ids'=>$ids,'checks'=>$checks]));echo "$checks real Nextcloud account access/scope assertions passed\n";
}else{
 $kind=$argv[1];$uid='account-alice';expectAccount($metadata->count('account-bob')===1&&$vectors->count('account-bob')===1,'Other account data deleted');
 if($kind==='embeddings'){expectAccount($metadata->count($uid)===1&&$vectors->count($uid)===0,'Embedding delete damaged descriptions');}
 else{expectAccount($metadata->count($uid)===0&&$vectors->count($uid)===0,'Description delete failed');}
 $home=Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($uid);expectAccount(hash('sha256',$home->get('Synthetic/test.png')->getContent())===hash_file('sha256','/samples/dog.png'),'Original changed');
 $own=$state->forUser($uid);$own->atomic('control.json',['paused'=>true,'requested'=>false]);echo "$checks separate deletion/account/original preservation assertions passed\n";
}
