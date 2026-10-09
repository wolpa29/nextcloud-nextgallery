<?php
/** Disposable synthetic reset contract; never runs on a production installation. */
declare(strict_types=1);
require '/var/www/html/lib/base.php';
use OCP\Server;
use OCA\Nextgallery\AI\Service\{Metadata,State};
if(!is_file('/test-output/ISOLATED_SYNTHETIC_TEST'))throw new RuntimeException('Synthetic guard missing');
$uid='merge-synthetic';$home=Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($uid);$meta=Server::get(Metadata::class);$state=Server::get(State::class);
$file=$home->get('MergeSynthetic/invoice.png');
if(($argv[1]??'')==='prepare'){
 if($meta->count($uid)<1)throw new RuntimeException('Reset fixture missing');
 file_put_contents('/test-output/reset-before.json',json_encode(['original'=>hash('sha256',$file->getContent()),'id'=>$file->getId()]));
 $state->setControl('reset');echo "Explicit synthetic reset requested\n";
}else{
 $before=json_decode(file_get_contents('/test-output/reset-before.json'),true);
 if($meta->count($uid)!==0)throw new RuntimeException('Explicit reset left AI metadata');
 if(hash('sha256',$file->getContent())!==$before['original'])throw new RuntimeException('Reset modified original');
 $data=Metadata::validate($file->getId(),$file->getEtag(),str_repeat('a',64),str_repeat('c',64),['vision'=>['type'=>'foto','description'=>'New analysis after explicit deletion','tags'=>[],'objects'=>[],'uncertain_details'=>[]],'ocr'=>['text'=>'NEWANALYSIS']],[]);
 $meta->upsert($uid,$data);
 if(json_decode($meta->get($uid,$file->getId())['metadata_json'],true)['analysis']['vision']['description']!=='New analysis after explicit deletion')throw new RuntimeException('New analysis after reset refused');
 echo "3 explicit reset/original preservation/new analysis assertions passed\n";
}
