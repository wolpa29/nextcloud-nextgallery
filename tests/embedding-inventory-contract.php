<?php
/** Canonical embedding completion across selection changes; isolated synthetic instance only. */
declare(strict_types=1);
require '/var/www/html/lib/base.php';
set_exception_handler(function(\Throwable $e):void {fwrite(STDERR,get_class($e).': '.$e->getMessage()."\n");exit(1);});
use OCP\Server;
use OCA\Nextgallery\AI\Service\{Settings,Inventory,Embeddings};
if(!is_file('/test-output/ISOLATED_SYNTHETIC_TEST'))throw new RuntimeException('Synthetic guard missing');
$uid='merge-synthetic';$settings=Server::get(Settings::class);$own=$settings->forUser($uid);$before=$own->get();
$vectors=Server::get(Embeddings::class);$inventory=Server::get(Inventory::class)->forUser($uid);
$home=Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($uid);
$invoice=$home->get('MergeSynthetic/invoice.png');$dog=$home->get('MergeSynthetic/dog.png');
$space=str_repeat('a',64);$other=str_repeat('b',64);$checks=0;
function checkInventory(bool $value,string $message):void {global $checks;$checks++;if(!$value)throw new RuntimeException($message);}
function inventoryRows(array $page):array {return array_column($page['items'],null,'file_id');}
$frames=[['frame'=>0,'seconds'=>0,'values'=>array_merge([1.],array_fill(0,127,0.))]];
try{
    $own->saveAccount($uid,['folders'=>['/MergeSynthetic'],'exclude_folders'=>[],'folder_stages'=>['/MergeSynthetic'=>['embeddings']]]);
    $vectors->publish($uid,$invoice->getId(),$invoice->getEtag(),$space,$frames);
    $vectors->publish($uid,$dog->getId(),'stale-synthetic-etag',$space,$frames);
    $page=$inventory->page(0,'embeddings',$space);$rows=inventoryRows($page);
    checkInventory($page['embedding_space']===$space,'Space acknowledgement missing');
    checkInventory($rows[$invoice->getId()]['embedding_etag']===$invoice->getEtag(),'Published current file not reusable');
    checkInventory($rows[$dog->getId()]['embedding_etag']!==$dog->getEtag(),'Changed file incorrectly reusable');
    $wrong=inventoryRows($inventory->page(0,'embeddings',$other));
    checkInventory($wrong[$invoice->getId()]['embedding_etag']===null,'Another model space reused');
    $legacy=$inventory->page(0,'embeddings');
    checkInventory(!isset($legacy['embedding_space'])&&!array_key_exists('embedding_etag',$legacy['items'][0]),'Legacy response changed');
    $vectors->reset($uid);
    $vectors->publish('synthetic-admin',$invoice->getId(),$invoice->getEtag(),$space,$frames);
    $rows=inventoryRows($inventory->page(0,'embeddings',$space));
    checkInventory($rows[$invoice->getId()]['embedding_etag']===null,'Other account completion leaked');
    $vectors->publish($uid,$invoice->getId(),$invoice->getEtag(),$space,$frames);
    $own->saveAccount($uid,['folders'=>['/MergeSynthetic','/'],'exclude_folders'=>[],'folder_stages'=>['/MergeSynthetic'=>['embeddings'],'/'=>['embeddings']]]);
    $rows=inventoryRows($inventory->page(0,'embeddings',$space));
    checkInventory($rows[$invoice->getId()]['embedding_etag']===$invoice->getEtag(),'Expanded selection lost completion');
    $own->saveAccount($uid,['folders'=>['/','/MergeSynthetic'],'exclude_folders'=>[],'folder_stages'=>['/'=>['embeddings'],'/MergeSynthetic'=>['embeddings']]]);
    $rows=inventoryRows($inventory->page(0,'embeddings',$space));
    checkInventory($rows[$invoice->getId()]['embedding_etag']===$invoice->getEtag(),'Reordered selection lost completion');
    $own->saveAccount($uid,['exclude_folders'=>['/MergeSynthetic']]);
    checkInventory(!isset(inventoryRows($inventory->page(0,'embeddings',$space))[$invoice->getId()]),'Excluded file returned');
    foreach([['embeddings','invalid'],['descriptions',$space],['faces',$space]] as [$kind,$invalid]){
        try{$inventory->page(0,$kind,$invalid);throw new RuntimeException('Invalid space/kind accepted');}
        catch(\OCP\AppFramework\OCS\OCSBadRequestException){checkInventory(true,'Invalid request refused');}
    }
    echo "$checks embedding inventory account, ETag, model and selection contracts passed\n";
}finally{
    $vectors->reset($uid);$vectors->resetFiles('synthetic-admin',[$invoice->getId()]);$own->saveAccount($uid,$before);
}
