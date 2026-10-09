<?php
if(PHP_SAPI!=='cli')exit(1);
define('OC_CONSOLE',1);require '/var/www/nextcloud/lib/base.php';
\OC_App::loadApp('external');
$manager=\OC::$server->get(\OCA\External\SitesManager::class);
$target=$argv[1]??'';
if(!filter_var($target,FILTER_VALIDATE_URL)||!str_starts_with($target,'https://'))throw new RuntimeException('Pass the real HTTPS NextGallery URL as the first argument.');
foreach($manager->getSites() as $site)if($site['name']==='NextGallery'&&$site['device']==='android'){
 $site=$manager->updateSite($site['id'],'NextGallery',$target,'','link','android',$site['icon'],$site['groups'],false);
 echo json_encode(['id'=>$site['id'],'url'=>$site['url'],'device'=>$site['device'],'redirect'=>$site['redirect']])."\n";exit(0);
}
$site=$manager->addSite('NextGallery',$target,'',\OCA\External\SitesManager::TYPE_LINK,\OCA\External\SitesManager::DEVICE_ANDROID,'external.svg',[],false);
echo json_encode(['id'=>$site['id'],'name'=>$site['name'],'device'=>$site['device'],'url'=>$site['url'],'redirect'=>$site['redirect']])."\n";
