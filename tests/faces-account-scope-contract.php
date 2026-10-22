<?php
/** Real Nextcloud contract. Disposable synthetic instances only; no existing users or photos. */
declare(strict_types=1);
$_SERVER['HTTP_OCS_APIREQUEST'] = 'true';
require '/var/www/html/lib/base.php';
\OC_User::setIncognitoMode(false);
if (!is_file('/test-output/ISOLATED_SYNTHETIC_TEST')) throw new RuntimeException('Synthetic guard missing');
set_exception_handler(function (Throwable $e) { fwrite(STDERR, $e->getMessage() . " at " . $e->getLine() . "\n"); exit(1); });
use OCP\Server;
use OCA\Nextgallery\AI\Service\{Settings, Faces};
use OCA\Nextgallery\Service\Library;
use OCA\Nextgallery\Controller\FacesController;
$db = Server::get(\OCP\IDBConnection::class);
$config = Server::get(\OCP\IConfig::class);
$settings = Server::get(Settings::class);
$faces = Server::get(Faces::class);
$library = Server::get(Library::class);
$users = Server::get(\OCP\IUserManager::class);
$session = Server::get(\OCP\IUserSession::class);
$root = Server::get(\OCP\Files\IRootFolder::class);
$before = $config->getAppValue('nextgallery', 'ai_settings', '{}');
$uids = ['ng-cross-a-' . bin2hex(random_bytes(4)), 'ng-cross-b-' . bin2hex(random_bytes(4))];
$checks = 0;
function crossCheck(bool $condition, string $message): void { global $checks; $checks++; if (!$condition) throw new RuntimeException($message); }
try {
    $c = $settings->get(); $c['faces']['enabled'] = true; $c['faces']['min_group_size'] = 3; $settings->save($c);
    $img = imagecreatetruecolor(128, 128); imagefilledrectangle($img, 0, 0, 127, 127, imagecolorallocate($img, 80, 120, 150));
    ob_start(); imagepng($img); $png = ob_get_clean(); imagedestroy($img);
    $files = []; $galleries = [];
    $maria = str_repeat('a', 32); $rare = str_repeat('b', 32); $ada = str_repeat('0', 32); $bob = str_repeat('d', 32);
    foreach ($uids as $i => $uid) {
        $user = $users->createUser($uid, bin2hex(random_bytes(24))); $session->setUser($user);
        $home = $root->getUserFolder($uid);
        $a = $home->newFolder('CrossA'); $b = $home->newFolder('CrossB');
        $files[$uid] = [$a->newFile('maria.png', $png), $b->newFile('rare.png', $png), $a->newFile('ada.png', $png)];
        $settings->saveAccount($uid, ['folders'=>['/CrossA','/CrossB'], 'enabled'=>false, 'folder_stages'=>['/CrossA'=>['faces'],'/CrossB'=>['faces']]]);
        $library->save($uid, ['roots'=>['/CrossA']]);
        $library->save($uid, ['createGallery'=>'Second', 'roots'=>['/CrossB']]);
        $galleries[$uid] = $library->settings($uid)['activeGallery'];
        do { $scan = $library->step($uid, !isset($scan), 50, 5); } while ($scan['running']); unset($scan);
        foreach ($files[$uid] as $j => $file) {
            $acc = $faces->accountState($uid); $vector = array_fill(0,128,0.0); $vector[$j] = 1.0;
            $gid = $i === 1 ? $bob : [$maria,$rare,$ada][$j];
            $faces->publish($uid,$file->getId(),$file->getEtag(),$acc['epoch'],$acc['generation'],$acc['rules_revision'],Faces::MODEL_SPACE,[['bbox'=>[0.1,0.1,0.3,0.3],'vector'=>$vector,'score'=>0.9,'group_id'=>$gid]]);
        }
    }
    $uid = $uids[0]; $gallery = $galleries[$uid]; $session->setUser($users->get($uid));
    $faces->action($uid,$maria,'rename',null,null,$faces->accountState($uid)['rules_revision'],'Maria');
    $faces->action($uid,$ada,'rename',null,null,$faces->accountState($uid)['rules_revision'],'Ada');
    crossCheck($faces->subjects($uid,$gallery)['items'] === [], 'Current gallery overview stays empty before the first assignment');
    crossCheck(array_column($faces->subjects($uid,$gallery,0,60,'single')['items'],'id') === [$rare], 'Only current-gallery rare face is listed');
    $targets = $faces->subjects($uid,$gallery,0,60,'all','account');
    crossCheck(in_array($maria,array_column($targets['items'],'id'),true), 'Named Maria from another gallery is an assignment target');
    crossCheck(!in_array($bob,array_column($targets['items'],'id'),true), 'Another account never supplies an assignment target');
    crossCheck($faces->subjects($uid,$gallery,0,1,'all','account')['items'][0]['id'] === $ada, 'Fixture puts Maria beyond the first page');
    $search = $faces->subjects($uid,$gallery,0,1,'all','account','mArIa');
    crossCheck(array_column($search['items'],'id') === [$maria], 'Case-insensitive name search runs before pagination');
    crossCheck($search['items'][0]['scope'] === 'account' && $search['items'][0]['photo_count'] === 1, 'Global target declares scope and global photo count');
    crossCheck($faces->media($uid,$maria,$gallery)['items'] === [], 'Normal media stays gallery-scoped');
    $media = $faces->media($uid,$maria,$gallery,0,60,'all','account');
    crossCheck(count($media['items']) === 1, 'Target can be inspected across galleries');
    crossCheck($faces->media($uids[1],$maria,$galleries[$uids[1]],0,60,'all','account')['items'] === [], 'Foreign group ID reveals no media');
    $ocs = Server::get(\OCA\Nextgallery\Controller\FacesOcsController::class);
    crossCheck($ocs->subjects($gallery,0,1,'all','account','Maria')->getData()['items'][0]['id'] === $maria, 'OCS adapter forwards account scope and server-side search');
    crossCheck(count($ocs->media($maria,$gallery,0,60,'all','account')->getData()['items']) === 1, 'OCS adapter forwards account inspection scope');
    $det = $media['items'][0]['face_detection_ids'][0];
    $ctrl = new FacesController(Server::get(\OCP\IRequest::class),$session,$faces,$library,Server::get(\OCP\IPreview::class),$root);
    crossCheck($ctrl->preview($det,$gallery)->getStatus() === 404, 'Normal preview cannot cross the selected gallery');
    $preview = $ctrl->preview($det,$gallery,[],'account');
    crossCheck($preview->getStatus() === 200, 'Account preview can show another own gallery: HTTP ' . $preview->getStatus());
    $session->setUser($users->get($uids[1]));
    crossCheck($ctrl->preview($det,$galleries[$uids[1]],[],'account')->getStatus() === 404, 'Account preview rejects another account detection');
    $session->setUser($users->get($uid));
    $acc = $faces->accountState($uid); $faces->publishSuggestions($uid,$acc['epoch'],$acc['generation'],[['a'=>$maria,'b'=>$rare,'similarity'=>0.8]]);
    crossCheck($faces->similar($uid,$rare,$gallery)['items'] === [], 'Legacy gallery-scoped suggestions remain compatible');
    crossCheck(array_column($faces->similar($uid,$rare,$gallery,8,'account')['items'],'id') === [$maria], 'Rare first photo finds a similar person in another gallery');
    crossCheck($ocs->similar($rare,$gallery,'account')->getData()['items'][0]['id'] === $maria, 'OCS adapter forwards account similarity scope');
    $suggested = $faces->subjects($uid,$gallery,0,60,'suggested');
    crossCheck(array_column($suggested['items'],'id') === [$rare] && $suggested['filter_counts']['suggested'] === 1, 'Overview badge counts the cross-gallery suggestion without listing foreign-gallery people');
    $faces->action($uid,$maria,'stranger',null,null,$faces->accountState($uid)['rules_revision']);
    crossCheck($faces->similar($uid,$rare,$gallery,8,'account')['items'] === [], 'Unknown targets are excluded from automatic suggestions');
    $faces->action($uid,$maria,'unstranger',null,null,$faces->accountState($uid)['rules_revision']);
    $faces->action($uid,$maria,'hide',null,null,$faces->accountState($uid)['rules_revision']);
    crossCheck($faces->subjects($uid,$gallery,0,60,'all','account','Maria')['items'] === [], 'Hidden target is excluded from ordinary account targets');
    $faces->action($uid,$maria,'show',null,null,$faces->accountState($uid)['rules_revision']);
    $library->save($uid,['activeGallery'=>'default','roots'=>[]]);
    crossCheck($faces->subjects($uid,$gallery,0,60,'all','account','Maria')['items'] === [], 'Removed gallery roots are excluded even with old index rows');
    crossCheck($ctrl->preview($det,$gallery,[],'account')->getStatus() === 404, 'Removed root cannot supply a global preview');
    $library->save($uid,['activeGallery'=>'default','roots'=>['/CrossA']]);
    $previousEtag = $files[$uid][0]->getEtag();
    $files[$uid][0]->putContent($png . 'changed');
    // Local storage ETags may use second-resolution mtimes. Ensure this
    // fixture really changes the version even on a fast CI runner.
    $files[$uid][0]->touch($files[$uid][0]->getMTime() + 2);
    crossCheck($files[$uid][0]->getEtag() !== $previousEtag, 'Synthetic write produced a distinct live ETag');
    crossCheck($faces->subjects($uid,$gallery,0,60,'all','account','Maria')['items'] === [], 'Changed ETag excludes stale target immediately');
    crossCheck($ctrl->preview($det,$gallery,[],'account')->getStatus() === 404, 'Changed ETag excludes stale preview');
    try { $faces->subjects($uid,$gallery,0,60,'all','unrestricted'); crossCheck(false,'Invalid scope accepted'); }
    catch (\OCP\AppFramework\OCS\OCSBadRequestException) { crossCheck(true,'Invalid scope rejected'); }
    $result = $faces->action($uid,$rare,'merge',$maria,null,$faces->accountState($uid)['rules_revision']);
    crossCheck($result['success'] && count($faces->media($uid,$maria,$gallery)['items']) === 1, 'First rare photo can be assigned to the pre-existing global identity');
    crossCheck($faces->subjects($uid,$gallery)['items'][0]['label'] === 'Maria', 'Existing name follows the first assignment into the new gallery');
    echo "$checks account-wide target, search, preview, suggestion and isolation assertions passed\n";
} finally {
    $session->setUser(null);
    foreach ($uids as $uid) { $faces->purge($uid); $user = $users->get($uid); if ($user) $user->delete(); }
    $config->setAppValue('nextgallery','ai_settings',$before);
}
