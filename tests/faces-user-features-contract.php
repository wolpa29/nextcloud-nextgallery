<?php
/** Real Nextcloud DB/query builder contract for new user UX features (rename, cover, strangers, context preview, shared sorting) */
declare(strict_types=1);
$_SERVER['HTTP_OCS_APIREQUEST'] = 'true';
require '/var/www/html/lib/base.php';
\OC_User::setIncognitoMode(false);
set_exception_handler(function(\Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . ' at ' . $error->getFile() . ':' . $error->getLine() . "\n" . $error->getTraceAsString() . "\n");
    exit(1);
});

use OCP\Server;
use OCP\{IDBConnection, IUserSession, IUserManager, IRequest};
use OCP\Files\IRootFolder;
use OCA\Nextgallery\AI\Service\{Settings, State, Inventory, Faces, FaceArtifacts};
use OCA\Nextgallery\Service\Library;
use OCA\Nextgallery\Controller\{FacesController, FacesOcsController};
use OCA\Nextgallery\Exception\FaceConflictException;
use OCP\AppFramework\OCS\{OCSBadRequestException, OCSForbiddenException, OCSNotFoundException};

if (!is_file('/test-output/ISOLATED_SYNTHETIC_TEST')) {
    throw new RuntimeException('Synthetic guard missing');
}

$checks = 0;
function expectFeat(bool $condition, string $message): void {
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException('Feature contract assertion failed: ' . $message);
    }
}

$db = Server::get(IDBConnection::class);
$settings = Server::get(Settings::class);
$faces = Server::get(Faces::class);
$library = Server::get(Library::class);
$users = Server::get(IUserManager::class);
$session = Server::get(IUserSession::class);
$rootFolder = Server::get(IRootFolder::class);
$previewService = Server::get(\OCP\IPreview::class);
$art = Server::get(FaceArtifacts::class);

// Enable faces globally
$c = $settings->get();
$c['faces']['enabled'] = true;
$settings->save($c);

$uids = ['ux-alice', 'ux-bob'];
foreach ($uids as $uid) {
    $existing = $users->get($uid);
    if ($existing !== null) {
        $existing->delete();
    }
    $users->createUser($uid, 'SyntheticUx2026!Pass');
}

$aliceUid = 'ux-alice';
$bobUid = 'ux-bob';
$aliceUser = $users->get($aliceUid);
$bobUser = $users->get($bobUid);

$sampleImg = file_get_contents('/samples/dog.png');
$sampleVector = array_merge([1.0], array_fill(0, 127, 0.0));
$space = Faces::MODEL_SPACE;

// Setup Alice files
$session->setUser($aliceUser);
$aliceHome = $rootFolder->getUserFolder($aliceUid);
$aliceFolder = $aliceHome->newFolder('Album');
$aliceFiles = [];
for ($i = 1; $i <= 5; $i++) {
    $aliceFiles[$i] = $aliceFolder->newFile("photo{$i}.png", $sampleImg);
}
$settings->saveAccount($aliceUid, [
    'enabled' => false,
    'folders' => ['/Album'],
    'folder_stages' => ['/Album' => ['faces']],
    'faces_filters' => Settings::DEFAULT_FACE_FILTERS,
]);
$library->save($aliceUid, ['roots' => ['/Album']]);
do {
    $step = $library->step($aliceUid, false, 100, 10);
} while ($step['running']);

// Publish face detections for Alice: Group A (photos 1, 2, 3), Group B (photo 4), Group C (photo 5)
$st = $faces->accountState($aliceUid);
$rev = $art->currentRevision();
$gidA = str_repeat('a', 32);
$gidB = str_repeat('b', 32);
$gidC = str_repeat('c', 32);

for ($i = 1; $i <= 3; $i++) {
    $faces->publish($aliceUid, $aliceFiles[$i]->getId(), $aliceFiles[$i]->getEtag(), $st['epoch'], $st['generation'], $st['rules_revision'], $space, [
        ['bbox' => [.1, .1, .3, .3], 'vector' => $sampleVector, 'score' => .95, 'sharpness' => 80.0, 'group_id' => $gidA]
    ], $rev);
}
$faces->publish($aliceUid, $aliceFiles[4]->getId(), $aliceFiles[4]->getEtag(), $st['epoch'], $st['generation'], $st['rules_revision'], $space, [
    ['bbox' => [.2, .2, .25, .25], 'vector' => $sampleVector, 'score' => .90, 'sharpness' => 70.0, 'group_id' => $gidB]
], $rev);
$faces->publish($aliceUid, $aliceFiles[5]->getId(), $aliceFiles[5]->getEtag(), $st['epoch'], $st['generation'], $st['rules_revision'], $space, [
    ['bbox' => [.3, .3, .2, .2], 'vector' => $sampleVector, 'score' => .88, 'sharpness' => 65.0, 'group_id' => $gidC]
], $rev);

// Detections for Alice
$detA1 = (string)$db->executeQuery('SELECT id FROM *PREFIX*nextgallery_faces_detections WHERE user_id=? AND group_id=? AND file_id=? LIMIT 1', [$aliceUid, $gidA, $aliceFiles[1]->getId()])->fetchOne();
$detA2 = (string)$db->executeQuery('SELECT id FROM *PREFIX*nextgallery_faces_detections WHERE user_id=? AND group_id=? AND file_id=? LIMIT 1', [$aliceUid, $gidA, $aliceFiles[2]->getId()])->fetchOne();
$detB = (string)$db->executeQuery('SELECT id FROM *PREFIX*nextgallery_faces_detections WHERE user_id=? AND group_id=? AND file_id=? LIMIT 1', [$aliceUid, $gidB, $aliceFiles[4]->getId()])->fetchOne();

// 1. Check filter_counts in subjects()
$subjAll = $faces->subjects($aliceUid, '', 0, 60, 'all');
expectFeat(isset($subjAll['filter_counts']), 'filter_counts dictionary present in subjects response');
expectFeat(isset($subjAll['filter_counts']['all'], $subjAll['filter_counts']['single'], $subjAll['filter_counts']['strangers']), 'filter_counts contains all, single, strangers keys');
expectFeat($subjAll['filter_counts']['all'] === 1, 'filter_counts all is 1 (Group A has 3 photos >= minGroupSize 3)');
expectFeat($subjAll['filter_counts']['single'] === 2, 'filter_counts single is 2 (Groups B and C have 1 photo each)');
expectFeat($subjAll['filter_counts']['strangers'] === 0, 'filter_counts strangers starts at 0');

// 2. Test rename
$st = $faces->accountState($aliceUid);
$renamed = $faces->action($aliceUid, $gidA, 'rename', null, null, $st['rules_revision'], 'Oma Maria');
expectFeat($renamed['label'] === 'Oma Maria', 'action rename returns updated label');
$subjAfterRename = $faces->subjects($aliceUid, '', 0, 60, 'all');
$groupAItem = array_values(array_filter($subjAfterRename['items'], fn($it) => $it['id'] === $gidA))[0];
expectFeat($groupAItem['label'] === 'Oma Maria', 'subjects() returns updated label on person card');

// 3. Test set_cover
$st = $faces->accountState($aliceUid);
$coverResult = $faces->action($aliceUid, $gidA, 'set_cover', $detA2, null, $st['rules_revision']);
expectFeat($coverResult['cover_id'] === $detA2, 'action set_cover returns new cover_id');
$subjAfterCover = $faces->subjects($aliceUid, '', 0, 60, 'all');
$groupAItemAfterCover = array_values(array_filter($subjAfterCover['items'], fn($it) => $it['id'] === $gidA))[0];
expectFeat($groupAItemAfterCover['cover_id'] === $detA2, 'subjects() returns new cover_id');
expectFeat((int)$groupAItemAfterCover['cover_file_id'] === $aliceFiles[2]->getId(), 'subjects() cover_file_id matches selected photo 2');

// 4. Test stranger & unstranger
$st = $faces->accountState($aliceUid);
$strangerResult = $faces->action($aliceUid, $gidC, 'stranger', null, null, $st['rules_revision']);
expectFeat($strangerResult['stranger'] === true, 'action stranger returns stranger=true');

$subjAfterStranger = $faces->subjects($aliceUid, '', 0, 60, 'all');
$idsInAll = array_column($subjAfterStranger['items'], 'id');
expectFeat(!in_array($gidC, $idsInAll, true), 'Group C is excluded from all filter after being marked stranger');
expectFeat($subjAfterStranger['filter_counts']['strangers'] === 1, 'filter_counts strangers incremented to 1');

$subjStrangers = $faces->subjects($aliceUid, '', 0, 60, 'strangers');
$idsInStrangers = array_column($subjStrangers['items'], 'id');
expectFeat(in_array($gidC, $idsInStrangers, true), 'Group C appears in filter=strangers');

// Unstranger
$st = $faces->accountState($aliceUid);
$unstrangerResult = $faces->action($aliceUid, $gidC, 'unstranger', null, null, $st['rules_revision']);
expectFeat($unstrangerResult['stranger'] === false, 'action unstranger returns stranger=false');
$subjAfterUnstranger = $faces->subjects($aliceUid, '', 0, 60, 'all');
expectFeat($subjAfterUnstranger['filter_counts']['strangers'] === 0, 'filter_counts strangers back to 0');
$subjSingleAfterUnstranger = $faces->subjects($aliceUid, '', 0, 60, 'single');
expectFeat(in_array($gidC, array_column($subjSingleAfterUnstranger['items'], 'id'), true), 'Group C returns to single filter');

// 5. Test merge preserving label & cover_id, returning merged_detection_ids
$st = $faces->accountState($aliceUid);
$mergeResult = $faces->action($aliceUid, $gidB, 'merge', $gidA, null, $st['rules_revision']);
expectFeat(is_array($mergeResult['merged_detection_ids']), 'merge action returns merged_detection_ids array');
expectFeat(in_array($detB, $mergeResult['merged_detection_ids'], true), 'merged_detection_ids contains detB');

$subjAfterMerge = $faces->subjects($aliceUid, '', 0, 60, 'all');
$mergedGroupA = array_values(array_filter($subjAfterMerge['items'], fn($it) => $it['id'] === $gidA))[0];
expectFeat($mergedGroupA['label'] === 'Oma Maria', 'Target group A preserved its custom label Oma Maria after merge');
expectFeat($mergedGroupA['cover_id'] === $detA2, 'Target group A preserved custom cover after merge');
expectFeat($mergedGroupA['photo_count'] === 4, 'Target group A now has 4 photos');

// 6. Test split with target_id (reassigning/moving detections directly to existing group)
$st = $faces->accountState($aliceUid);
$splitMoveResult = $faces->action($aliceUid, $gidA, 'split', $gidC, [$detB], $st['rules_revision']);
expectFeat($splitMoveResult['target_id'] === $gidC, 'split with target_id returns target_id matching gidC');

$mediaC = $faces->media($aliceUid, $gidC, '', 0, 60);
$allDetsInC = !empty($mediaC['items']) ? array_merge(...array_column($mediaC['items'], 'face_detection_ids')) : [];
expectFeat(in_array($detB, $allDetsInC, true), 'Detection detB was moved directly into Group C');

// 7. Test preview with ?context=1
$facesController = new FacesController(
    Server::get(IRequest::class),
    $session,
    $faces,
    $library,
    $previewService,
    $rootFolder
);

// Standard crop preview
$cropPreview = $facesController->preview($detA1);
expectFeat($cropPreview->getStatus() === 200, 'Standard crop preview returns 200');
expectFeat(($cropPreview->getHeaders()['Content-Type'] ?? '') === 'image/jpeg', 'Crop preview mime type is image/jpeg');

// Context preview with marked face bounding box
$_GET['context'] = '1';
$contextPreview = $facesController->preview($detA1);
expectFeat($contextPreview->getStatus() === 200, 'Context preview returns 200');
expectFeat(strlen($contextPreview->render()) > 0, 'Context preview returns non-empty image data');
unset($_GET['context']);

// 8. Test Shared Sorting
// Initially Alice has share_sorting disabled
$aliceSharedList = $faces->sharedSortings($aliceUid, '');
expectFeat($aliceSharedList['sharing_enabled'] === false, 'Alice sharing_enabled is false initially');

// Alice enables share_sorting
$faces->setSharedSorting($aliceUid, true);
$aliceSharedListAfter = $faces->sharedSortings($aliceUid, '');
expectFeat($aliceSharedListAfter['sharing_enabled'] === true, 'Alice sharing_enabled is true after enabling');

// Alice shares Album with Bob
$shareManager = Server::get(\OCP\Share\IManager::class);
$share = $shareManager->newShare()
    ->setNode($aliceFolder)
    ->setShareType(\OCP\Share\IShare::TYPE_USER)
    ->setSharedBy($aliceUid)
    ->setSharedWith($bobUid)
    ->setPermissions(\OCP\Constants::PERMISSION_READ);
$shareManager->createShare($share);

// Bob setup with shared folder access
$session->setUser($bobUser);
$bobHome = $rootFolder->getUserFolder($bobUid);
$settings->saveAccount($bobUid, [
    'enabled' => false,
    'folders' => ['/Album'],
    'folder_stages' => ['/Album' => ['faces']],
    'faces_filters' => Settings::DEFAULT_FACE_FILTERS,
]);
$library->save($bobUid, ['roots' => ['/Album']]);
do {
    $step = $library->step($bobUid, false, 100, 10);
} while ($step['running']);

// Publish Bob's own detections for shared photo 1 and photo 2 as separate unmerged groups
$stBob = $faces->accountState($bobUid);
$bobGid1 = str_repeat('7', 32);
$bobGid2 = str_repeat('8', 32);
$faces->publish($bobUid, $aliceFiles[1]->getId(), $aliceFiles[1]->getEtag(), $stBob['epoch'], $stBob['generation'], $stBob['rules_revision'], $space, [
    ['bbox' => [.1, .1, .3, .3], 'vector' => $sampleVector, 'score' => .95, 'sharpness' => 80.0, 'group_id' => $bobGid1]
], $rev);
$faces->publish($bobUid, $aliceFiles[2]->getId(), $aliceFiles[2]->getEtag(), $stBob['epoch'], $stBob['generation'], $stBob['rules_revision'], $space, [
    ['bbox' => [.1, .1, .3, .3], 'vector' => $sampleVector, 'score' => .95, 'sharpness' => 80.0, 'group_id' => $bobGid2]
], $rev);

// Bob checks available shared sortings
$bobShared = $faces->sharedSortings($bobUid, '');
expectFeat(count($bobShared['available']) >= 1, 'Bob sees available shared sortings');
$aliceEntry = array_values(array_filter($bobShared['available'], fn($e) => $e['user_id'] === $aliceUid))[0] ?? null;
expectFeat($aliceEntry !== null, 'Bob sees Alice in available shared sortings');
expectFeat($aliceEntry['user_id'] === $aliceUid, 'Alice entry user_id matches');

// Bob adopts Alice's sorting!
$adoptResult = $faces->adoptSharedSorting($bobUid, $aliceUid, '');
expectFeat($adoptResult['adopted'] === true, 'adoptSharedSorting returned adopted=true');
expectFeat($adoptResult['source_user'] === $aliceUid, 'source_user matches Alice');

// Bob's group for photo 1 now has label 'Oma Maria' adopted from Alice!
$bobSubjAfterAdopt = $faces->subjects($bobUid, '', 0, 60, 'all');
$bobAdoptedGroup = array_values(array_filter($bobSubjAfterAdopt['items'], fn($it) => $it['label'] === 'Oma Maria'))[0] ?? null;
expectFeat($bobAdoptedGroup !== null, 'Bob successfully adopted Oma Maria label and grouping from Alice');

// Cleanup
foreach ($uids as $uid) {
    $users->get($uid)?->delete();
}

echo "$checks real Nextcloud MariaDB UX and Face Feature contracts passed successfully!\n";
