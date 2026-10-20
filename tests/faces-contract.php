<?php
/** Real Nextcloud DB/query builder faces contract; disposable synthetic Nextcloud instance only. */
declare(strict_types=1);
$_SERVER['HTTP_OCS_APIREQUEST'] = 'true';
require '/var/www/html/lib/base.php';
\OC_User::setIncognitoMode(false);
set_exception_handler(function(\Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . ' at ' . $error->getFile() . ':' . $error->getLine() . "\n");
    exit(1);
});

use OCP\Server;
use OCP\{IDBConnection, IUserSession, IUserManager};
use OCP\Files\IRootFolder;
use OCA\Nextgallery\AI\Service\{Settings, State, Inventory, Faces};
use OCA\Nextgallery\Service\Library;
use OCA\Nextgallery\Controller\{FaceWorkerController, FacesController, FacesOcsController};
use OCA\Nextgallery\BackgroundJob\FaceCleanupJob;
use OCA\Nextgallery\Exception\FaceConflictException;
use OCP\AppFramework\OCS\{OCSBadRequestException, OCSForbiddenException, OCSNotFoundException};

if (!is_file('/test-output/ISOLATED_SYNTHETIC_TEST')) {
    throw new RuntimeException('Synthetic guard missing');
}

$checks = 0;
function expectFaces(bool $condition, string $message): void {
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException('Faces contract assertion failed: ' . $message);
    }
}

$db = Server::get(IDBConnection::class);
$settings = Server::get(Settings::class);
$faces = Server::get(Faces::class);
$library = Server::get(Library::class);
$users = Server::get(IUserManager::class);
$session = Server::get(IUserSession::class);
$rootFolder = Server::get(IRootFolder::class);

// 1. Enable faces globally
$beforeSettings = $settings->get();
$c = $beforeSettings;
$c['faces']['enabled'] = true;
$settings->save($c);

$sampleVector = array_merge([1.0], array_fill(0, 127, 0.0));
$space = Faces::MODEL_SPACE;
$ids = [];
$etags = [];

// 2. Setup Alice and Bob with synthetic test photos
foreach (['account-alice', 'account-bob'] as $uid) {
    $user = $users->get($uid) ?? $users->createUser($uid, 'SyntheticAccountOnly2026!');
    $session->setUser($user);
    $home = $rootFolder->getUserFolder($uid);
    $folder = $home->nodeExists('Synthetic') ? $home->get('Synthetic') : $home->newFolder('Synthetic');

    $sampleData = is_file('/samples/dog.png') ? file_get_contents('/samples/dog.png') : "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x10\x00\x00\x00\x10\x08\x02\x00\x00\x00\x90\x91h6\x00\x00\x00\x0cIDATx\x9cc\xf8\xff\xff?\x00\x05\xfe\x02\xfe\xa74\x813\x00\x00\x00\x00IEND\xaeB`\x82";
    $file = $folder->nodeExists('face1.png') ? $folder->get('face1.png') : $folder->newFile('face1.png', $sampleData);
    $file2 = $folder->nodeExists('face2.png') ? $folder->get('face2.png') : $folder->newFile('face2.png', $sampleData);

    $settings->saveAccount($uid, [
        'folders' => ['/Synthetic'],
        'enabled' => false,
        'folder_stages' => ['/Synthetic' => ['faces', 'descriptions', 'ocr', 'embeddings']],
    ]);

    // Index files into library
    $library->save($uid, ['roots' => ['/Synthetic']]);
    $st = $library->step($uid, true, 50, 5);
    while (!empty($st['running'])) {
        $st = $library->step($uid, false, 50, 5);
    }

    $ids[$uid] = [$file->getId(), $file2->getId()];
    $etags[$uid] = [$file->getEtag(), $file2->getEtag()];
}

try {
    $faces->purge('account-alice');
    $faces->purge('account-bob');

    // 3. Account State and Generation Isolation
    $aliceState = $faces->accountState('account-alice');
    $bobState = $faces->accountState('account-bob');
    expectFaces(strlen($aliceState['epoch']) === 32, 'Alice epoch is 32-character hex');
    expectFaces(strlen($bobState['epoch']) === 32, 'Bob epoch is 32-character hex');
    expectFaces($aliceState['epoch'] !== $bobState['epoch'], 'Epochs must be isolated between accounts');
    expectFaces($aliceState['generation'] === 1 && $bobState['generation'] === 1, 'Initial generation is 1');
    expectFaces($aliceState['rules_revision'] === 1 && $bobState['rules_revision'] === 1, 'Initial rules_revision is 1');

    // 4. Publish detections for Alice
    $aliceFileId1 = $ids['account-alice'][0];
    $aliceEtag1 = $etags['account-alice'][0];
    $groupId1 = '11111111111111111111111111111111';

    $detInput = [
        'bbox' => [0.1, 0.1, 0.2, 0.2],
        'vector' => $sampleVector,
        'score' => 0.95,
        'group_id' => $groupId1,
    ];

    // 400 test: mismatched model space rejected
    try {
        $faces->publish('account-alice', $aliceFileId1, $aliceEtag1, $aliceState['epoch'], 1, 1, 'invalid-space', [$detInput]);
        expectFaces(false, 'Invalid space accepted');
    } catch (OCSBadRequestException) {
        expectFaces(true, 'Invalid space rejected with 400');
    }

    // 409 test: Mismatched epoch rejected
    try {
        $faces->publish('account-alice', $aliceFileId1, $aliceEtag1, '00000000000000000000000000000000', 1, 1, $space, [$detInput]);
        expectFaces(false, 'Stale epoch accepted');
    } catch (FaceConflictException) {
        expectFaces(true, 'Stale epoch rejected with 409');
    }

    // 409 test: Mismatched generation rejected
    try {
        $faces->publish('account-alice', $aliceFileId1, $aliceEtag1, $aliceState['epoch'], 999, 1, $space, [$detInput]);
        expectFaces(false, 'Stale generation accepted');
    } catch (FaceConflictException) {
        expectFaces(true, 'Stale generation rejected with 409');
    }

    // Successful publish
    $pubRes = $faces->publish('account-alice', $aliceFileId1, $aliceEtag1, $aliceState['epoch'], 1, 1, $space, [$detInput]);
    expectFaces(count($pubRes) === 1, 'Canonical detection returned');
    $detId1 = $pubRes[0]['id'];
    expectFaces(strlen($detId1) === 32, 'Stable UUID32 returned for detection');
    expectFaces($pubRes[0]['group_id'] === $groupId1, 'Group ID matched');

    // Account isolation check: Bob cannot see Alice's data
    $bobStatuses = $faces->statuses('account-bob', [$aliceFileId1]);
    expectFaces(empty($bobStatuses), 'Bob cannot see Alice files in statuses');
    $bobStateExport = $faces->state('account-bob');
    expectFaces(empty($bobStateExport['items']), 'Bob state export does not leak Alice detections');
    $bobSubjects = $faces->subjects('account-bob');
    expectFaces(empty($bobSubjects['items']), 'Bob subjects does not leak Alice subjects');

    // Alice can see her statuses and counts
    $aliceStatuses = $faces->statuses('account-alice', [$aliceFileId1]);
    expectFaces($aliceStatuses === [$aliceFileId1 => $aliceEtag1], 'Alice sees her file status');
    expectFaces($faces->count('account-alice') === 1, 'Alice processed file count is 1');

    // Zero-detection file processing for Alice's second file
    $aliceFileId2 = $ids['account-alice'][1];
    $aliceEtag2 = $etags['account-alice'][1];
    $zeroPub = $faces->publish('account-alice', $aliceFileId2, $aliceEtag2, $aliceState['epoch'], 1, 1, $space, []);
    expectFaces(empty($zeroPub), 'Zero detection returns empty list');
    $aliceStatuses2 = $faces->statuses('account-alice', [$aliceFileId1, $aliceFileId2]);
    expectFaces(count($aliceStatuses2) === 2, 'Zero-detection file recorded in processed statuses');
    expectFaces($faces->count('account-alice') === 2, 'Processed count now 2 (including zero-face file)');

    // Stable ID reconciliation on republish
    $detInputShift = [
        'bbox' => [0.105, 0.105, 0.2, 0.2], // IoU ~ 0.95
        'vector' => $sampleVector,
        'score' => 0.96,
        'group_id' => $groupId1,
    ];
    $repubRes = $faces->publish('account-alice', $aliceFileId1, $aliceEtag1, $aliceState['epoch'], 1, 1, $space, [$detInputShift]);
    expectFaces($repubRes[0]['id'] === $detId1, 'High IoU retained stable detection ID');

    // 5. Actions: hide, show, split, merge, cannot-link, resolve
    $rulesRev = 1;

    // Split detection to target group
    $targetGroupId = '22222222222222222222222222222222';
    $splitRes = $faces->action('account-alice', $groupId1, 'split', $targetGroupId, [$detId1], $rulesRev);
    expectFaces($splitRes['rules_revision'] === 2, 'Split bumped rules_revision to 2');
    $rulesRev = 2;

    $detRow = $faces->detection('account-alice', $detId1);
    expectFaces($detRow['group_id'] === $targetGroupId, 'Detection moved to new group');
    expectFaces((int)$detRow['manual'] === 1, 'Manual assignment flag set');

    // Cannot-link preservation: worker suggests old group
    $workerWantsOld = [
        'bbox' => [0.105, 0.105, 0.2, 0.2],
        'vector' => $sampleVector,
        'score' => 0.96,
        'group_id' => $groupId1,
    ];
    $pubProtected = $faces->publish('account-alice', $aliceFileId1, $aliceEtag1, $aliceState['epoch'], 1, $rulesRev, $space, [$workerWantsOld]);
    expectFaces($pubProtected[0]['group_id'] === $targetGroupId, 'Manual group assignment preserved against worker suggestion');
    expectFaces($pubProtected[0]['review'] === true, 'Cannot-link conflict flagged review');

    // Resolve review
    $resolveRes = $faces->action('account-alice', $targetGroupId, 'resolve', null, [$detId1], $rulesRev);
    expectFaces($resolveRes['rules_revision'] === 3, 'Resolve bumped rules_revision to 3');
    $rulesRev = 3;
    expectFaces((int)$faces->detection('account-alice', $detId1)['review'] === 0, 'Review flag resolved');

    // Hide / Show
    $hideRes = $faces->action('account-alice', $targetGroupId, 'hide', null, null, $rulesRev);
    expectFaces($hideRes['rules_revision'] === 4, 'Hide bumped rules_revision to 4');
    $rulesRev = 4;
    $showRes = $faces->action('account-alice', $targetGroupId, 'show', null, null, $rulesRev);
    expectFaces($showRes['rules_revision'] === 5, 'Show bumped rules_revision to 5');
    $rulesRev = 5;

    // Merge into group 3
    $targetGroupId3 = '33333333333333333333333333333333';
    $mergeRes = $faces->action('account-alice', $targetGroupId, 'merge', $targetGroupId3, null, $rulesRev);
    expectFaces($mergeRes['rules_revision'] === 6, 'Merge bumped rules_revision to 6');
    $rulesRev = 6;
    expectFaces($faces->detection('account-alice', $detId1)['group_id'] === $targetGroupId3, 'Detections moved on merge');

    // 6. Controllers via user session
    $session->setUser($users->get('account-alice'));
    $facesController = Server::get(FacesController::class);

    // 403 test: unauthenticated request
    $session->setUser(null);
    try {
        $facesController->subjects();
        expectFaces(false, 'Unauthenticated subjects accepted');
    } catch (OCSForbiddenException) {
        expectFaces(true, 'Unauthenticated subjects rejected with 403');
    }
    $session->setUser($users->get('account-alice'));

    // 404 test: action on nonexistent group
    $res404 = $facesController->action('00000000000000000000000000000000', 'hide');
    expectFaces($res404->getStatus() === 404, 'Action on nonexistent group rejected with 404');

    // Subjects endpoint
    $subjectsRes = $facesController->subjects();
    expectFaces($subjectsRes->getStatus() === 200, 'Subjects returns 200');
    $subjectsData = $subjectsRes->getData();
    expectFaces(isset($subjectsData['items'], $subjectsData['epoch'], $subjectsData['generation']), 'Subjects envelope complete');
    expectFaces(!isset($subjectsData['items'][0]['vector']), 'No vector exposure via subjects endpoint');

    // FacesOcsController test
    $facesOcsController = Server::get(FacesOcsController::class);
    $ocsSubRes = $facesOcsController->subjects();
    expectFaces($ocsSubRes->getStatus() === 200, 'FacesOcsController subjects returns 200');

    // Preview endpoint
    $previewRes = $facesController->preview($detId1);
    expectFaces($previewRes->getStatus() === 200, 'Preview returns 200');
    expectFaces(str_contains($previewRes->getHeaders()['Cache-Control'] ?? '', 'private, no-store'), 'Preview has private, no-store');
    expectFaces(strlen($previewRes->render()) > 0, 'Preview returns image bytes');

    // Bob cannot preview Alice's detection
    $session->setUser($users->get('account-bob'));
    $bobPreviewRes = $facesController->preview($detId1);
    expectFaces($bobPreviewRes->getStatus() === 404, 'Bob cannot preview Alice face');

    // 7. Background job FaceCleanupJob
    $cleanupJob = Server::get(FaceCleanupJob::class);
    // Runs cleanly without throwing exceptions
    $cleanupJob->start(Server::get(\OCP\BackgroundJob\IJobList::class));
    expectFaces(true, 'FaceCleanupJob executed cleanly');

    // 8. Reset & Purge
    $session->setUser($users->get('account-alice'));
    $resetOut = $faces->reset('account-alice');
    expectFaces($resetOut['generation'] === 2, 'Reset returned new generation 2');
    $accReset = $faces->accountState('account-alice');
    expectFaces($accReset['generation'] === 2, 'Generation bumped to 2 on reset');
    expectFaces($accReset['epoch'] === $aliceState['epoch'], 'Epoch preserved on reset');
    expectFaces($faces->count('account-alice') === 0, 'Faces cleared on reset');

    // Stale late publish with old generation rejected
    try {
        $faces->publish('account-alice', $aliceFileId1, $aliceEtag1, $aliceState['epoch'], 1, $rulesRev, $space, [$detInput]);
        expectFaces(false, 'Old generation publish accepted after reset');
    } catch (FaceConflictException) {
        expectFaces(true, 'Old generation publish rejected with 409');
    }

    // Purge (UserDeleted simulation)
    $faces->purge('account-alice');
    $accPurged = $faces->accountState('account-alice');
    expectFaces($accPurged['epoch'] !== $aliceState['epoch'], 'Brand new epoch created after account purge');
    expectFaces($accPurged['generation'] === 1, 'Generation reset to 1 after account purge');

    echo "$checks real Nextcloud faces storage/account isolation/API contracts passed\n";
} finally {
    $faces->reset('account-alice');
    $faces->reset('account-bob');
    $settings->save($beforeSettings);
}
