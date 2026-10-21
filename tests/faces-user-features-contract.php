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

// Reject self-split without changing assignments, revisions or correction constraints.
$beforeSelfSplit = $faces->accountState($aliceUid)['rules_revision'];
$constraintsBefore = (int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*nextgallery_faces_constraints WHERE user_id=?', [$aliceUid])->fetchOne();
try {
    $faces->action($aliceUid, $gidA, 'split', strtoupper($gidA), [$detA1], $beforeSelfSplit);
    expectFeat(false, 'Self-split must fail');
} catch (OCSBadRequestException) {
    expectFeat(true, 'Self-split rejected including case-normalized target');
}
expectFeat($faces->accountState($aliceUid)['rules_revision'] === $beforeSelfSplit, 'Rejected self-split preserves rule revision');
expectFeat((int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*nextgallery_faces_constraints WHERE user_id=?', [$aliceUid])->fetchOne() === $constraintsBefore, 'Rejected self-split creates no constraints');
expectFeat($db->executeQuery('SELECT group_id FROM *PREFIX*nextgallery_faces_detections WHERE user_id=? AND id=?', [$aliceUid, $detA1])->fetchOne() === $gidA, 'Rejected self-split preserves group membership');

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

// 4b. Bulk stranger/unstranger: one transaction, one rules revision, all-or-nothing ownership check
$st = $faces->accountState($aliceUid);
$singleBefore = $faces->subjects($aliceUid, '', 0, 60, 'single');
$itemB = array_values(array_filter($singleBefore['items'], fn($it) => $it['id'] === $gidB))[0];
expectFeat($itemB['good_count'] === 1, 'subjects() reports good_count for a rare person');
$bulk = $faces->bulkAction($aliceUid, [$gidB, strtoupper($gidC), $gidB], 'stranger', $st['rules_revision']);
expectFeat($bulk['rules_revision'] === $st['rules_revision'] + 1, 'bulk stranger bumps rules revision exactly once');
expectFeat($bulk['ids'] === [$gidB, $gidC], 'bulk stranger normalizes and deduplicates ids');
$afterBulk = $faces->subjects($aliceUid, '', 0, 60, 'single');
expectFeat($afterBulk['filter_counts']['strangers'] === 2, 'bulk stranger moves both groups to strangers');
expectFeat($afterBulk['filter_counts']['single'] === 0, 'bulk stranger empties rare people');
try {
    $faces->bulkAction($aliceUid, [$gidB], 'unstranger', $st['rules_revision']);
    expectFeat(false, 'stale bulk revision must be rejected');
} catch (FaceConflictException) {
    expectFeat(true, 'stale bulk revision rejected');
}
$st = $faces->accountState($aliceUid);
try {
    $faces->bulkAction($aliceUid, [$gidB, str_repeat('ab', 16)], 'unstranger', $st['rules_revision']);
    expectFeat(false, 'unknown group in bulk must be rejected');
} catch (OCSNotFoundException) {
    expectFeat($faces->subjects($aliceUid, '', 0, 60, 'all')['filter_counts']['strangers'] === 2, 'unknown group rejects the whole bulk action');
}
// 4c. Bulk merge pre-mutation rejections & atomic failure check
// Missing target ID (replaces former non-flag rejection)
try {
    $faces->bulkAction($aliceUid, [$gidB], 'merge', $st['rules_revision']);
    expectFeat(false, 'bulk merge without target must be rejected');
} catch (OCSBadRequestException $e) {
    expectFeat($e->getMessage() === 'Ziel-ID für Zusammenführung erforderlich', 'bulk merge without target rejected');
}

// Self-merge (target directly in sources list)
try {
    $faces->bulkAction($aliceUid, [$gidB, $gidA], 'merge', $st['rules_revision'], $gidA);
    expectFeat(false, 'self-merge in bulk sources must be rejected');
} catch (OCSBadRequestException $e) {
    expectFeat(str_contains($e->getMessage(), 'Zielgruppe darf nicht in den Quellgruppen enthalten sein'), 'self-merge in bulk rejected');
}

// Self-merge with uppercase target in sources list (case-normalized check)
try {
    $faces->bulkAction($aliceUid, [strtoupper($gidA)], 'merge', $st['rules_revision'], $gidA);
    expectFeat(false, 'self-merge with uppercase target in sources must be rejected');
} catch (OCSBadRequestException) {
    expectFeat(true, 'case-normalized self-merge rejected');
}

// Stale rules revision
try {
    $faces->bulkAction($aliceUid, [$gidB], 'merge', $st['rules_revision'] - 1, $gidA);
    expectFeat(false, 'stale revision in bulk merge must be rejected');
} catch (FaceConflictException) {
    expectFeat(true, 'stale revision in bulk merge rejected');
}

// Absent target (does not exist)
try {
    $faces->bulkAction($aliceUid, [$gidB], 'merge', $st['rules_revision'], str_repeat('9', 32));
    expectFeat(false, 'absent target in bulk merge must be rejected');
} catch (OCSNotFoundException) {
    expectFeat(true, 'absent target in bulk merge rejected');
}

// Absent source (one group does not exist)
try {
    $faces->bulkAction($aliceUid, [$gidB, str_repeat('8', 32)], 'merge', $st['rules_revision'], $gidA);
    expectFeat(false, 'absent source in bulk merge must be rejected');
} catch (OCSNotFoundException) {
    expectFeat(true, 'absent source in bulk merge rejected');
}

// Max 500 sources limit
$tooMany = array_map(fn($n) => sprintf('%032x', $n), range(1, 501));
try {
    $faces->bulkAction($aliceUid, $tooMany, 'merge', $st['rules_revision'], $gidA);
    expectFeat(false, 'more than 500 sources must be rejected');
} catch (OCSBadRequestException $e) {
    expectFeat(str_contains($e->getMessage(), 'maximal 500'), 'exceeding 500 sources rejected');
}

// Atomic failure: verify account state and source group state are untouched
$stAfterRejections = $faces->accountState($aliceUid);
expectFeat($stAfterRejections['rules_revision'] === $st['rules_revision'], 'rejected bulk merges do not increment rules_revision');
$bDetGroup = $db->executeQuery('SELECT group_id FROM *PREFIX*nextgallery_faces_detections WHERE user_id=? AND id=?', [$aliceUid, $detB])->fetchOne();
expectFeat($bDetGroup === $gidB, 'atomic rejection leaves source group detections intact in group B');
$faces->bulkAction($aliceUid, [$gidB, $gidC], 'unstranger', $st['rules_revision']);
$restored = $faces->subjects($aliceUid, '', 0, 60, 'single');
expectFeat($restored['filter_counts']['strangers'] === 0 && $restored['filter_counts']['single'] === 2, 'bulk unstranger restores rare people');

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

// 9. Bulk Merge comprehensive contracts (Foreign checks, atomic execution, metadata/cover preference, merged_groups, controllers)

// 9a. Cross-account isolation: Bob and Alice foreign source and target rejections
$stAlice = $faces->accountState($aliceUid);
$stBob = $faces->accountState($bobUid);

// Alice trying to merge into Bob's group as target
try {
    $faces->bulkAction($aliceUid, [$gidC], 'merge', $stAlice['rules_revision'], $bobGid1);
    expectFeat(false, 'Alice merging into Bob target must be rejected');
} catch (OCSNotFoundException) {
    expectFeat(true, 'Foreign target for Alice rejected with 404');
}

// Alice trying to merge Bob's group as source
try {
    $faces->bulkAction($aliceUid, [$gidC, $bobGid1], 'merge', $stAlice['rules_revision'], $gidA);
    expectFeat(false, 'Alice merging Bob group in sources must be rejected');
} catch (OCSNotFoundException) {
    expectFeat(true, 'Foreign source for Alice rejected with 404');
}

// Bob trying to merge into Alice's group as target
try {
    $faces->bulkAction($bobUid, [$bobGid1], 'merge', $stBob['rules_revision'], $gidA);
    expectFeat(false, 'Bob merging into Alice target must be rejected');
} catch (OCSNotFoundException) {
    expectFeat(true, 'Foreign target for Bob rejected with 404');
}

// Bob trying to merge Alice's group as source
try {
    $faces->bulkAction($bobUid, [$gidA, $bobGid2], 'merge', $stBob['rules_revision'], $bobGid1);
    expectFeat(false, 'Bob merging Alice group in sources must be rejected');
} catch (OCSNotFoundException) {
    expectFeat(true, 'Foreign source for Bob rejected with 404');
}

// Verify revisions untouched
expectFeat($faces->accountState($aliceUid)['rules_revision'] === $stAlice['rules_revision'], 'Alice revision unchanged after foreign attempts');
expectFeat($faces->accountState($bobUid)['rules_revision'] === $stBob['rules_revision'], 'Bob revision unchanged after foreign attempts');

// 9b. Setup additional files and groups for Alice bulk merge testing
$session->setUser($aliceUser);
for ($i = 6; $i <= 13; $i++) {
    $aliceFiles[$i] = $aliceFolder->newFile("photo{$i}.png", $sampleImg);
}
do {
    $step = $library->step($aliceUid, false, 100, 10);
} while ($step['running']);

$gidT1 = str_repeat('1', 32);
$gidS1 = str_repeat('2', 32);
$gidS2 = str_repeat('3', 32);
$gidS3 = str_repeat('4', 32);

$st = $faces->accountState($aliceUid);
$faces->publish($aliceUid, $aliceFiles[6]->getId(), $aliceFiles[6]->getEtag(), $st['epoch'], $st['generation'], $st['rules_revision'], $space, [
    ['bbox' => [.1, .1, .2, .2], 'vector' => $sampleVector, 'score' => .95, 'sharpness' => 80.0, 'group_id' => $gidT1]
], $rev);
$faces->publish($aliceUid, $aliceFiles[7]->getId(), $aliceFiles[7]->getEtag(), $st['epoch'], $st['generation'], $st['rules_revision'], $space, [
    ['bbox' => [.1, .1, .2, .2], 'vector' => $sampleVector, 'score' => .95, 'sharpness' => 80.0, 'group_id' => $gidS1]
], $rev);
$faces->publish($aliceUid, $aliceFiles[8]->getId(), $aliceFiles[8]->getEtag(), $st['epoch'], $st['generation'], $st['rules_revision'], $space, [
    ['bbox' => [.1, .1, .2, .2], 'vector' => $sampleVector, 'score' => .95, 'sharpness' => 80.0, 'group_id' => $gidS2]
], $rev);
$faces->publish($aliceUid, $aliceFiles[9]->getId(), $aliceFiles[9]->getEtag(), $st['epoch'], $st['generation'], $st['rules_revision'], $space, [
    ['bbox' => [.1, .1, .2, .2], 'vector' => $sampleVector, 'score' => .95, 'sharpness' => 80.0, 'group_id' => $gidS3]
], $rev);

$detT1 = (string)$db->executeQuery('SELECT id FROM *PREFIX*nextgallery_faces_detections WHERE user_id=? AND group_id=? AND file_id=? LIMIT 1', [$aliceUid, $gidT1, $aliceFiles[6]->getId()])->fetchOne();
$detS1 = (string)$db->executeQuery('SELECT id FROM *PREFIX*nextgallery_faces_detections WHERE user_id=? AND group_id=? AND file_id=? LIMIT 1', [$aliceUid, $gidS1, $aliceFiles[7]->getId()])->fetchOne();
$detS2 = (string)$db->executeQuery('SELECT id FROM *PREFIX*nextgallery_faces_detections WHERE user_id=? AND group_id=? AND file_id=? LIMIT 1', [$aliceUid, $gidS2, $aliceFiles[8]->getId()])->fetchOne();
$detS3 = (string)$db->executeQuery('SELECT id FROM *PREFIX*nextgallery_faces_detections WHERE user_id=? AND group_id=? AND file_id=? LIMIT 1', [$aliceUid, $gidS3, $aliceFiles[9]->getId()])->fetchOne();

$st = $faces->accountState($aliceUid);
$faces->action($aliceUid, $gidS1, 'rename', null, null, $st['rules_revision'], 'Person Eins');
$st = $faces->accountState($aliceUid);
$faces->action($aliceUid, $gidS1, 'set_cover', $detS1, null, $st['rules_revision']);

$st = $faces->accountState($aliceUid);
$faces->action($aliceUid, $gidS2, 'rename', null, null, $st['rules_revision'], 'Person Zwei');
$st = $faces->accountState($aliceUid);
$faces->action($aliceUid, $gidS2, 'set_cover', $detS2, null, $st['rules_revision']);

// Add contradictory constraint (gidS1 cannot-link to detT1 which is in target)
$db->executeStatement(
    'INSERT INTO *PREFIX*nextgallery_faces_constraints (user_id, kind, detection_id, group_id, created_at) VALUES (?, ?, ?, ?, ?)',
    [$aliceUid, 'cannot_link', $detT1, $gidS1, time()]
);
// Add valid constraint (gidS2 cannot-link to detA1)
$db->executeStatement(
    'INSERT INTO *PREFIX*nextgallery_faces_constraints (user_id, kind, detection_id, group_id, created_at) VALUES (?, ?, ?, ?, ?)',
    [$aliceUid, 'cannot_link', $detA1, $gidS2, time()]
);

// 9c. Execute Bulk Merge with multiple sources
$stBefore = $faces->accountState($aliceUid);
$bulkMergeRes = $faces->bulkAction($aliceUid, [$gidS1, $gidS2, $gidS3], 'merge', $stBefore['rules_revision'], $gidT1);

expectFeat($bulkMergeRes['success'] === true, 'bulk merge returns success=true');
expectFeat($bulkMergeRes['rules_revision'] === $stBefore['rules_revision'] + 1, 'bulk merge bumps rules revision exactly once');
expectFeat($bulkMergeRes['target_id'] === $gidT1, 'bulk merge returns target_id matching gidT1');
expectFeat($bulkMergeRes['ids'] === [$gidS1, $gidS2, $gidS3], 'bulk merge returns cleaned source ids');
expectFeat(count($bulkMergeRes['merged_detection_ids']) === 3, 'merged_detection_ids contains 3 detections');
expectFeat(in_array($detS1, $bulkMergeRes['merged_detection_ids'], true), 'merged_detection_ids contains detS1');
expectFeat(in_array($detS2, $bulkMergeRes['merged_detection_ids'], true), 'merged_detection_ids contains detS2');
expectFeat(in_array($detS3, $bulkMergeRes['merged_detection_ids'], true), 'merged_detection_ids contains detS3');
expectFeat(count($bulkMergeRes['merged_groups']) === 3, 'merged_groups contains 3 entries for group-wise split');
expectFeat($bulkMergeRes['merged_groups'][0]['source_id'] === $gidS1 && $bulkMergeRes['merged_groups'][0]['detection_ids'] === [$detS1], 'merged_groups entry 0 has gidS1 and detS1');
expectFeat($bulkMergeRes['merged_groups'][1]['source_id'] === $gidS2 && $bulkMergeRes['merged_groups'][1]['detection_ids'] === [$detS2], 'merged_groups entry 1 has gidS2 and detS2');
expectFeat($bulkMergeRes['merged_groups'][2]['source_id'] === $gidS3 && $bulkMergeRes['merged_groups'][2]['detection_ids'] === [$detS3], 'merged_groups entry 2 has gidS3 and detS3');

// Check DB state for metadata fallback
$t1Row = $db->executeQuery('SELECT label, cover_id FROM *PREFIX*nextgallery_faces_groups WHERE user_id=? AND id=?', [$aliceUid, $gidT1])->fetch();
expectFeat($t1Row['label'] === 'Person Eins', 'Target adopted label from first non-empty source fallback');
expectFeat($t1Row['cover_id'] === $detS1, 'Target adopted cover_id from first non-empty source fallback');

// Check source groups deleted from groups table
$remainingSources = (int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*nextgallery_faces_groups WHERE user_id=? AND id IN (?, ?, ?)', [$aliceUid, $gidS1, $gidS2, $gidS3])->fetchOne();
expectFeat($remainingSources === 0, 'All source groups deleted from nextgallery_faces_groups');

// Check all detections moved to target group with manual = 1
$t1Dets = $db->executeQuery('SELECT id, manual FROM *PREFIX*nextgallery_faces_detections WHERE user_id=? AND group_id=?', [$aliceUid, $gidT1])->fetchAll();
expectFeat(count($t1Dets) === 4, 'Target group contains all 4 detections');
foreach ($t1Dets as $td) {
    if ($td['id'] !== $detT1) {
        expectFeat((int)$td['manual'] === 1, 'Merged detection marked as manual=1');
    }
}

// Check contradictory constraint removed
$contraRemaining = (int)$db->executeQuery(
    'SELECT COUNT(*) FROM *PREFIX*nextgallery_faces_constraints WHERE user_id=? AND group_id=? AND detection_id=?',
    [$aliceUid, $gidT1, $detT1]
)->fetchOne();
expectFeat($contraRemaining === 0, 'Contradictory cannot-link constraint on own detection was removed');

// Check valid constraint remapped to gidT1
$remappedRemaining = (int)$db->executeQuery(
    'SELECT COUNT(*) FROM *PREFIX*nextgallery_faces_constraints WHERE user_id=? AND group_id=? AND detection_id=?',
    [$aliceUid, $gidT1, $detA1]
)->fetchOne();
expectFeat($remappedRemaining === 1, 'Valid cannot-link constraint was remapped to target group gidT1');

// 9d. 1-click Undo / Group-wise split using merged_groups
$stAfterMerge = $faces->accountState($aliceUid);
$firstMerged = $bulkMergeRes['merged_groups'][0];
$undoSplit = $faces->action($aliceUid, $gidT1, 'split', $firstMerged['source_id'], $firstMerged['detection_ids'], $stAfterMerge['rules_revision']);
expectFeat($undoSplit['target_id'] === $gidS1, 'Group-wise split restores source group ID via split action');
$restoredDetGroup = $db->executeQuery('SELECT group_id FROM *PREFIX*nextgallery_faces_detections WHERE user_id=? AND id=?', [$aliceUid, $detS1])->fetchOne();
expectFeat($restoredDetGroup === $gidS1, 'Detection detS1 restored back to original source group');

// 9e. Target metadata PREFERENCE (target retains existing label & cover when set)
$gidT2 = str_repeat('5', 32);
$gidS4 = str_repeat('6', 32);
$st = $faces->accountState($aliceUid);
$faces->publish($aliceUid, $aliceFiles[10]->getId(), $aliceFiles[10]->getEtag(), $st['epoch'], $st['generation'], $st['rules_revision'], $space, [
    ['bbox' => [.1, .1, .2, .2], 'vector' => $sampleVector, 'score' => .95, 'sharpness' => 80.0, 'group_id' => $gidT2]
], $rev);
$faces->publish($aliceUid, $aliceFiles[11]->getId(), $aliceFiles[11]->getEtag(), $st['epoch'], $st['generation'], $st['rules_revision'], $space, [
    ['bbox' => [.1, .1, .2, .2], 'vector' => $sampleVector, 'score' => .95, 'sharpness' => 80.0, 'group_id' => $gidS4]
], $rev);
$detT2 = (string)$db->executeQuery('SELECT id FROM *PREFIX*nextgallery_faces_detections WHERE user_id=? AND group_id=? AND file_id=? LIMIT 1', [$aliceUid, $gidT2, $aliceFiles[10]->getId()])->fetchOne();
$detS4 = (string)$db->executeQuery('SELECT id FROM *PREFIX*nextgallery_faces_detections WHERE user_id=? AND group_id=? AND file_id=? LIMIT 1', [$aliceUid, $gidS4, $aliceFiles[11]->getId()])->fetchOne();

$st = $faces->accountState($aliceUid);
$faces->action($aliceUid, $gidT2, 'rename', null, null, $st['rules_revision'], 'Target Boss');
$st = $faces->accountState($aliceUid);
$faces->action($aliceUid, $gidT2, 'set_cover', $detT2, null, $st['rules_revision']);

$st = $faces->accountState($aliceUid);
$faces->action($aliceUid, $gidS4, 'rename', null, null, $st['rules_revision'], 'Source Sub');
$st = $faces->accountState($aliceUid);
$faces->action($aliceUid, $gidS4, 'set_cover', $detS4, null, $st['rules_revision']);

$st = $faces->accountState($aliceUid);
$prefMergeRes = $faces->bulkAction($aliceUid, [$gidS4], 'merge', $st['rules_revision'], $gidT2);
expectFeat($prefMergeRes['success'] === true, 'Merge with existing target metadata succeeds');
$t2Row = $db->executeQuery('SELECT label, cover_id FROM *PREFIX*nextgallery_faces_groups WHERE user_id=? AND id=?', [$aliceUid, $gidT2])->fetch();
expectFeat($t2Row['label'] === 'Target Boss', 'Target label preferred over source label');
expectFeat($t2Row['cover_id'] === $detT2, 'Target cover preferred over source cover');

// 9f. FacesController bulkAction passing target_id
$gidS5 = str_repeat('a', 16) . str_repeat('1', 16);
$st = $faces->accountState($aliceUid);
$faces->publish($aliceUid, $aliceFiles[12]->getId(), $aliceFiles[12]->getEtag(), $st['epoch'], $st['generation'], $st['rules_revision'], $space, [
    ['bbox' => [.1, .1, .2, .2], 'vector' => $sampleVector, 'score' => .95, 'sharpness' => 80.0, 'group_id' => $gidS5]
], $rev);
$st = $faces->accountState($aliceUid);
$ctrlResp = $facesController->bulkAction('merge', [$gidS5], $st['rules_revision'], $gidT2);
expectFeat($ctrlResp->getStatus() === 200, 'FacesController bulkAction returns HTTP 200');
expectFeat($ctrlResp->getData()['success'] === true, 'FacesController bulkAction returns success=true');
expectFeat($ctrlResp->getData()['target_id'] === $gidT2, 'FacesController bulkAction returns target_id');

// 9g. FacesOcsController bulkAction passing target_id
$gidS6 = str_repeat('b', 16) . str_repeat('2', 16);
$st = $faces->accountState($aliceUid);
$faces->publish($aliceUid, $aliceFiles[13]->getId(), $aliceFiles[13]->getEtag(), $st['epoch'], $st['generation'], $st['rules_revision'], $space, [
    ['bbox' => [.1, .1, .2, .2], 'vector' => $sampleVector, 'score' => .95, 'sharpness' => 80.0, 'group_id' => $gidS6]
], $rev);
$st = $faces->accountState($aliceUid);
$facesOcsController = new FacesOcsController(
    Server::get(IRequest::class),
    $session,
    $faces,
    $library
);
$ocsResp = $facesOcsController->bulkAction('merge', [$gidS6], $st['rules_revision'], $gidT2);
expectFeat($ocsResp->getStatus() === 200, 'FacesOcsController bulkAction returns HTTP 200');
expectFeat($ocsResp->getData()['success'] === true, 'FacesOcsController bulkAction returns success=true');
expectFeat($ocsResp->getData()['target_id'] === $gidT2, 'FacesOcsController bulkAction returns target_id');
expectFeat(count($ocsResp->getData()['merged_groups']) === 1, 'FacesOcsController bulkAction returns merged_groups');

// Cleanup
foreach ($uids as $uid) {
    $users->get($uid)?->delete();
}

echo "$checks real Nextcloud MariaDB UX and Face Feature contracts passed successfully!\n";
