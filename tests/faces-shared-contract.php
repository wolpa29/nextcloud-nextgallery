<?php
/** Real Nextcloud MariaDB face artifact shared inference contract; disposable synthetic instance only. */
declare(strict_types=1);
require '/var/www/html/lib/base.php';
\OC_User::setIncognitoMode(false);
set_exception_handler(function(\Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . ' at ' . $error->getFile() . ':' . $error->getLine() . "\n");
    exit(1);
});

use OCP\Server;
use OCP\{IDBConnection, IUserSession, IUserManager, IConfig, Constants};
use OCP\Files\IRootFolder;
use OCP\Share\IManager;
use OCA\Nextgallery\AI\Service\{Settings, State, Inventory, Faces, FaceArtifacts};
use OCA\Nextgallery\Service\Library;
use OCA\Nextgallery\Controller\FaceWorkerController;
use OCA\Nextgallery\Exception\FaceConflictException;
use OCP\AppFramework\OCS\{OCSBadRequestException, OCPBadRequestException, OCSNotFoundException};

if (!is_file('/test-output/ISOLATED_SYNTHETIC_TEST')) {
    throw new RuntimeException('Synthetic guard missing');
}

$checks = 0;
function expectShared(bool $condition, string $message): void {
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException('Faces shared contract assertion failed: ' . $message);
    }
}

$db = Server::get(IDBConnection::class);
$settings = Server::get(Settings::class);
$faces = Server::get(Faces::class);
$faceArtifacts = Server::get(FaceArtifacts::class);
$library = Server::get(Library::class);
$users = Server::get(IUserManager::class);
$session = Server::get(IUserSession::class);
$rootFolder = Server::get(IRootFolder::class);
$shareManager = Server::get(IManager::class);
$config = Server::get(IConfig::class);

// 1. Enable faces globally, ensure clean baseline
$beforeSettings = $settings->get();
$c = $beforeSettings;
$c['faces']['enabled'] = true;
$c['faces']['cosine_threshold'] = 0.55;
$c['faces']['detection_threshold'] = 0.9;
$c['faces']['min_face_size'] = 32;
$c['faces']['max_edge'] = 2048;
$settings->save($c);

$config->setAppValue('nextgallery', 'ai_share_results', 'yes');
$config->setAppValue('nextgallery', 'ai_faces_legacy_inference_revision', '');

$currentRevision = FaceArtifacts::revision($settings->get());
$workerToken = $settings->workerToken();

// 2. Setup Alice and Bob disposable accounts
$owner = 'shared-alice';
$reader = 'shared-bob';

foreach ([$owner, $reader] as $uid) {
    if (!$users->get($uid)) {
        $users->createUser($uid, 'SyntheticShareOnly2026!');
    }
    $session->setUser($users->get($uid));
}

$session->setUser($users->get($owner));
$aliceHome = $rootFolder->getUserFolder($owner);
$sharedFolder = $aliceHome->nodeExists('SharedFaces') ? $aliceHome->get('SharedFaces') : $aliceHome->newFolder('SharedFaces');
$privateFolder = $aliceHome->nodeExists('PrivateFaces') ? $aliceHome->get('PrivateFaces') : $aliceHome->newFolder('PrivateFaces');

$pngData = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x20\x00\x00\x00\x20\x08\x02\x00\x00\x00\x4c\x5e\xa3\x52\x00\x00\x00\x0cIDATx\x9cc\xf8\xff\xff?\x00\x05\xfe\x02\xfe\xa74\x813\x00\x00\x00\x00IEND\xaeB`\x82";
$sharedFile = $sharedFolder->nodeExists('face-share.png') ? $sharedFolder->get('face-share.png') : $sharedFolder->newFile('face-share.png', $pngData);
$emptyFile = $sharedFolder->nodeExists('zero-face.png') ? $sharedFolder->get('zero-face.png') : $sharedFolder->newFile('zero-face.png', $pngData);
$privateFile = $privateFolder->nodeExists('private.png') ? $privateFolder->get('private.png') : $privateFolder->newFile('private.png', $pngData);

$sharedFileId = (int)$sharedFile->getId();
$sharedFileEtag = (string)$sharedFile->getEtag();
$emptyFileId = (int)$emptyFile->getId();
$emptyFileEtag = (string)$emptyFile->getEtag();
$privateFileId = (int)$privateFile->getId();
$privateFileEtag = (string)$privateFile->getEtag();

// Share SharedFaces with Bob
$existingShares = $shareManager->getSharesBy($owner, \OCP\Share\IShare::TYPE_USER);
$alreadyShared = false;
foreach ($existingShares as $s) {
    if ($s->getSharedWith() === $reader && $s->getNode()->getId() === $sharedFolder->getId()) {
        $alreadyShared = true;
        break;
    }
}
if (!$alreadyShared) {
    $share = $shareManager->newShare()
        ->setNode($sharedFolder)
        ->setShareType(\OCP\Share\IShare::TYPE_USER)
        ->setSharedBy($owner)
        ->setSharedWith($reader)
        ->setPermissions(Constants::PERMISSION_READ);
    $shareManager->createShare($share);
}

// Configure account folder stages
$settings->saveAccount($owner, [
    'folders' => ['/SharedFaces', '/PrivateFaces'],
    'enabled' => false,
    'folder_stages' => [
        '/SharedFaces' => ['faces'],
        '/PrivateFaces' => ['faces'],
    ],
]);
$library->save($owner, ['roots' => ['/SharedFaces', '/PrivateFaces']]);

$session->setUser($users->get($reader));
$settings->saveAccount($reader, [
    'folders' => ['/SharedFaces'],
    'enabled' => false,
    'folder_stages' => [
        '/SharedFaces' => ['faces'],
    ],
]);
$library->save($reader, ['roots' => ['/SharedFaces']]);

// Helper to create worker request
function createWorkerController(string $uid, string $token): FaceWorkerController {
    $req = new class(
        [
            'server' => [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'HTTP_X_NEXTGALLERY_USER' => $uid,
            ],
            'method' => 'POST',
        ],
        Server::get(\OCP\IRequestId::class),
        Server::get(\OCP\IConfig::class)
    ) extends \OC\AppFramework\Http\Request {};
    return new FaceWorkerController(
        $req,
        Server::get(Settings::class),
        Server::get(Inventory::class),
        Server::get(Faces::class),
        Server::get(IUserManager::class),
        Server::get(State::class),
        null,
        Server::get(FaceArtifacts::class)
    );
}

try {
    // 3. Purge initial state
    $faces->purge($owner);
    $faces->purge($reader);

    // Verify state exports inference_revision
    $ownerState = $faces->state($owner);
    expectShared(isset($ownerState['inference_revision']), 'faces-state includes inference_revision');
    expectShared($ownerState['inference_revision'] === $currentRevision, 'faces-state inference_revision matches current settings');

    // 4. Initial Lookup: Cache Miss
    $aliceWorker = createWorkerController($owner, $workerToken);
    $resMiss = $aliceWorker->artifactLookup($sharedFileId, $sharedFileEtag);
    expectShared($resMiss->getStatus() === 200, 'artifactLookup returns 200 on miss');
    $missData = $resMiss->getData();
    expectShared($missData['detections'] === null, 'Initial lookup returns detections: null');
    expectShared($missData['inference_revision'] === $currentRevision, 'Miss returns current inference_revision');

    // 5. Alice Publishes with current inference_revision
    $sampleVector = array_merge([1.0], array_fill(0, 127, 0.0));
    $rawDet1 = [
        'bbox' => [0.15, 0.15, 0.25, 0.25],
        'vector' => $sampleVector,
        'score' => 0.98,
        'group_id' => '11111111111111111111111111111111',
    ];

    $alicePub = $faces->publish(
        $owner,
        $sharedFileId,
        $sharedFileEtag,
        $ownerState['epoch'],
        $ownerState['generation'],
        $ownerState['rules_revision'],
        Faces::MODEL_SPACE,
        [$rawDet1],
        $currentRevision
    );
    expectShared(count($alicePub) === 1, 'Alice published detection');

    // Verify artifact is in database
    $qb = $db->getQueryBuilder();
    $qb->select('face_count', 'inference_revision')
       ->from('nextgallery_faces_artifacts')
       ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($sharedFileId)))
       ->andWhere($qb->expr()->eq('etag', $qb->createNamedParameter($sharedFileEtag)));
    $row = $qb->executeQuery()->fetch();
    expectShared($row !== false, 'Artifact recorded in nextgallery_faces_artifacts');
    expectShared((int)$row['face_count'] === 1, 'Artifact face_count is 1');
    expectShared($row['inference_revision'] === $currentRevision, 'Artifact revision matches current');

    // 6. Bob Cache HIT (Raw-only fields / No Groups)
    $bobWorker = createWorkerController($reader, $workerToken);
    $resHit = $bobWorker->artifactLookup($sharedFileId, $sharedFileEtag);
    expectShared($resHit->getStatus() === 200, 'Bob lookup returns 200');
    $hitData = $resHit->getData();
    expectShared(is_array($hitData['detections']), 'Bob gets cache HIT array');
    expectShared(count($hitData['detections']) === 1, 'Bob gets exactly 1 detection from cache');
    $cachedDet = $hitData['detections'][0];
    expectShared(!isset($cachedDet['group_id']), 'Cached detection contains NO group_id');
    expectShared(!isset($cachedDet['id']), 'Cached detection contains NO detection id');
    expectShared(!isset($cachedDet['manual']) && !isset($cachedDet['review']), 'Cached detection contains NO manual/review flags');
    expectShared(isset($cachedDet['bbox'], $cachedDet['vector'], $cachedDet['score']), 'Cached detection has raw bbox, vector, score');
    expectShared(count($cachedDet['vector']) === 128, 'Cached vector has 128 floats');

    // 7. Independent Per-User Groups & Corrections
    $bobState = $faces->state($reader);
    $bobGroupId = '22222222222222222222222222222222';
    $bobDetPayload = [
        'bbox' => $cachedDet['bbox'],
        'vector' => $cachedDet['vector'],
        'score' => $cachedDet['score'],
        'group_id' => $bobGroupId,
    ];

    $bobPub = $faces->publish(
        $reader,
        $sharedFileId,
        $sharedFileEtag,
        $bobState['epoch'],
        $bobState['generation'],
        $bobState['rules_revision'],
        Faces::MODEL_SPACE,
        [$bobDetPayload],
        $currentRevision
    );
    expectShared(count($bobPub) === 1, 'Bob published detection using cached raw');
    expectShared($bobPub[0]['group_id'] === $bobGroupId, 'Bob detection assigned Bob group');

    // Alice modifies her group: split into group 3
    $aliceGroupId3 = '33333333333333333333333333333333';
    $faces->action($owner, '11111111111111111111111111111111', 'split', $aliceGroupId3, [$alicePub[0]['id']], $ownerState['rules_revision']);

    // Check Alice detection moved
    $aliceDetRow = $faces->detection($owner, $alicePub[0]['id']);
    expectShared($aliceDetRow['group_id'] === $aliceGroupId3, 'Alice detection split into group 3');

    // Check Bob detection UNCHANGED
    $bobDetRow = $faces->detection($reader, $bobPub[0]['id']);
    expectShared($bobDetRow['group_id'] === $bobGroupId, 'Bob detection remains in Bob group 2 (isolated)');

    // 8. Zero-Face Cache Hit ([] hit)
    $aliceEmptyState = $faces->state($owner);
    $aliceEmptyPub = $faces->publish(
        $owner,
        $emptyFileId,
        $emptyFileEtag,
        $aliceEmptyState['epoch'],
        $aliceEmptyState['generation'],
        $aliceEmptyState['rules_revision'],
        Faces::MODEL_SPACE,
        [],
        $currentRevision
    );
    expectShared(count($aliceEmptyPub) === 0, 'Alice published zero-face file');

    // Bob looks up zero-face file
    $resZero = $bobWorker->artifactLookup($emptyFileId, $emptyFileEtag);
    expectShared($resZero->getStatus() === 200, 'Zero-face lookup returns 200');
    expectShared($resZero->getData()['detections'] === [], 'Zero-face lookup returns empty array [] (valid hit, not null)');

    // 9. Denied Unshared / Unopted Access
    // Bob looks up unshared private file of Alice
    $resPrivate = $bobWorker->artifactLookup($privateFileId, $privateFileEtag);
    expectShared($resPrivate->getStatus() === 404, 'Bob denied lookup on unshared private file (404)');

    // Bob un-opts from faces on SharedFaces folder (e.g. selects only descriptions)
    $settings->saveAccount($reader, [
        'folders' => ['/SharedFaces'],
        'enabled' => false,
        'folder_stages' => ['/SharedFaces' => ['descriptions']],
    ]);
    $resUnopt = $bobWorker->artifactLookup($sharedFileId, $sharedFileEtag);
    expectShared($resUnopt->getStatus() === 404, 'Bob denied lookup on un-opted folder (404)');

    // Restore Bob folder opt-in
    $settings->saveAccount($reader, [
        'folders' => ['/SharedFaces'],
        'enabled' => false,
        'folder_stages' => ['/SharedFaces' => ['faces']],
    ]);

    // 10. ETag Mismatch & Update
    $resWrongEtag = $bobWorker->artifactLookup($sharedFileId, 'stale-wrong-etag-12345');
    expectShared($resWrongEtag->getStatus() === 409, 'ETag mismatch returns 409');

    // 11. Exact Detection Signature vs Grouping-Only Changes
    // Change cosine_threshold (grouping-only): revision must NOT change
    $c = $settings->get();
    $c['faces']['cosine_threshold'] = 0.75;
    $settings->save($c);
    $revAfterGroupChange = $faceArtifacts->currentRevision();
    expectShared($revAfterGroupChange === $currentRevision, 'Grouping-only change preserves inference_revision');
    $resHitGroupOnly = $bobWorker->artifactLookup($sharedFileId, $sharedFileEtag);
    expectShared(is_array($resHitGroupOnly->getData()['detections']), 'Grouping-only change still yields cache HIT');

    // Change detection_threshold (inference hyperparameter): revision MUST change
    $c['faces']['detection_threshold'] = 0.80;
    $settings->save($c);
    $revAfterDetectionChange = $faceArtifacts->currentRevision();
    expectShared($revAfterDetectionChange !== $currentRevision, 'Detection threshold change alters inference_revision');
    $resMissDetectionChange = $bobWorker->artifactLookup($sharedFileId, $sharedFileEtag);
    expectShared($resMissDetectionChange->getData()['detections'] === null, 'Different detection threshold yields cache MISS');

    // Restore settings
    $c['faces']['cosine_threshold'] = 0.55;
    $c['faces']['detection_threshold'] = 0.90;
    $settings->save($c);

    // 12. Reset One Account Keeps Other Refs, Last Reset Erases Cache
    // Check both accounts have refs for sharedFileId
    $qb = $db->getQueryBuilder();
    $qb->select($qb->createFunction('COUNT(*) as cnt'))
       ->from('nextgallery_face_refs')
       ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($sharedFileId)));
    $refCount = (int)$qb->executeQuery()->fetchOne();
    expectShared($refCount >= 2, 'Both Alice and Bob have refs for shared file');

    // Alice resets her account
    $faces->reset($owner);

    // Artifact STILL exists because Bob references it
    $qb = $db->getQueryBuilder();
    $qb->select($qb->createFunction('COUNT(*) as cnt'))
       ->from('nextgallery_faces_artifacts')
       ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($sharedFileId)));
    $artCount = (int)$qb->executeQuery()->fetchOne();
    expectShared($artCount === 1, 'Artifact retained after Alice resets (Bob still references it)');

    // Bob resets his account
    $faces->reset($reader);

    // Now NO accounts reference it: artifact erased
    $qb = $db->getQueryBuilder();
    $qb->select($qb->createFunction('COUNT(*) as cnt'))
       ->from('nextgallery_faces_artifacts')
       ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($sharedFileId)));
    $artCountAfterBoth = (int)$qb->executeQuery()->fetchOne();
    expectShared($artCountAfterBoth === 0, 'Last reset completely erases artifact from cache');

    // 13. Stale Generation Cannot Cache
    // Bob generation bumped to 2; worker tries to publish with old generation 1
    try {
        $faces->publish($reader, $sharedFileId, $sharedFileEtag, $bobState['epoch'], 1, $bobState['rules_revision'], Faces::MODEL_SPACE, [$bobDetPayload], $currentRevision);
        expectShared(false, 'Stale generation publish accepted');
    } catch (FaceConflictException) {
        expectShared(true, 'Stale generation publish rejected with 409');
    }

    // Verify no artifact or ref was created by stale publish
    $qb = $db->getQueryBuilder();
    $qb->select($qb->createFunction('COUNT(*) as cnt'))
       ->from('nextgallery_faces_artifacts')
       ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($sharedFileId)));
    expectShared((int)$qb->executeQuery()->fetchOne() === 0, 'No artifact created by stale publish');

    // 14. Unversioned Legacy Reuse ONLY with Explicit Pin
    // Seed unversioned legacy row in faces_files and detections
    $legacyFileId = 999901;
    $legacyEtag = 'legacy-etag-xyz';
    $db->executeStatement(
        'INSERT INTO *PREFIX*nextgallery_faces_files (user_id, file_id, etag, face_count, updated_at, inference_revision) VALUES (?, ?, ?, ?, ?, ?)',
        [$owner, $legacyFileId, $legacyEtag, 1, time(), 'legacy']
    );
    $db->executeStatement(
        'INSERT INTO *PREFIX*nextgallery_face_detections (user_id, id, file_id, etag, bbox_x, bbox_y, bbox_w, bbox_h, score, group_id, model_space, vector_data, review, manual, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$owner, 'legacydet10000000000000000000000', $legacyFileId, $legacyEtag, 0.1, 0.1, 0.2, 0.2, 0.95, 'legacygrp10000000000000000000000', Faces::MODEL_SPACE, base64_encode(pack('g*', ...$sampleVector)), 0, 0, time(), time()]
    );

    // Fail closed: without explicit pin, legacy row is NOT reused
    $legacyFindNoPin = $faceArtifacts->find($legacyFileId, $legacyEtag, $currentRevision);
    expectShared($legacyFindNoPin === null, 'Unversioned legacy row fails closed without explicit pin');

    // With explicit pin set by operator/test:
    $config->setAppValue('nextgallery', 'ai_faces_legacy_inference_revision', $currentRevision);
    $legacyFindWithPin = $faceArtifacts->find($legacyFileId, $legacyEtag, $currentRevision);
    expectShared(is_array($legacyFindWithPin) && count($legacyFindWithPin) === 1, 'Unversioned legacy row lazily reused when explicit pin matches');
    $config->setAppValue('nextgallery', 'ai_faces_legacy_inference_revision', '');

    // Cleanup legacy test rows
    $db->executeStatement('DELETE FROM *PREFIX*nextgallery_face_detections WHERE file_id = ?', [$legacyFileId]);
    $db->executeStatement('DELETE FROM *PREFIX*nextgallery_faces_files WHERE file_id = ?', [$legacyFileId]);

    // 15. New Unsigned Publication (Empty Signature) Sets FF Signature Unknown
    $aliceStateFresh = $faces->state($owner);
    $pubUnsigned = $faces->publish(
        $owner,
        $sharedFileId,
        $sharedFileEtag,
        $aliceStateFresh['epoch'],
        $aliceStateFresh['generation'],
        $aliceStateFresh['rules_revision'],
        Faces::MODEL_SPACE,
        [$rawDet1],
        '' // Empty inference revision (old worker)
    );
    expectShared(count($pubUnsigned) === 1, 'Unsigned publish accepted for account');

    // Verify faces_files has inference_revision = 'unknown'
    $qb = $db->getQueryBuilder();
    $qb->select('inference_revision')
       ->from('nextgallery_faces_files')
       ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($owner)))
       ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($sharedFileId)));
    $ffRow = $qb->executeQuery()->fetch();
    expectShared($ffRow !== false && $ffRow['inference_revision'] === 'unknown', 'Unsigned publish records inference_revision = unknown');

    // Verify artifact cache is NOT populated for unsigned publish
    $qb = $db->getQueryBuilder();
    $qb->select($qb->createFunction('COUNT(*) as cnt'))
       ->from('nextgallery_faces_artifacts')
       ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($sharedFileId)));
    expectShared((int)$qb->executeQuery()->fetchOne() === 0, 'Unsigned publish does NOT populate shared artifact cache');

    // 16. Account Deletion (Purge)
    // Alice publishes with signed revision
    $faces->reset($owner);
    $aliceStateSigned = $faces->state($owner);
    $faces->publish(
        $owner,
        $sharedFileId,
        $sharedFileEtag,
        $aliceStateSigned['epoch'],
        $aliceStateSigned['generation'],
        $aliceStateSigned['rules_revision'],
        Faces::MODEL_SPACE,
        [$rawDet1],
        $currentRevision
    );
    // Artifact exists
    $qb = $db->getQueryBuilder();
    $qb->select($qb->createFunction('COUNT(*) as cnt'))
       ->from('nextgallery_faces_artifacts')
       ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($sharedFileId)));
    expectShared((int)$qb->executeQuery()->fetchOne() === 1, 'Signed publish populated artifact');

    // Purge Alice
    $faces->purge($owner);

    // Since Alice was the only account referencing it, artifact is removed
    $qb = $db->getQueryBuilder();
    $qb->select($qb->createFunction('COUNT(*) as cnt'))
       ->from('nextgallery_faces_artifacts')
       ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($sharedFileId)));
    expectShared((int)$qb->executeQuery()->fetchOne() === 0, 'Purging account removes unreferenced artifacts');

    echo "$checks real Nextcloud MariaDB faces shared inference contracts passed\n";
} finally {
    $faces->reset($owner);
    $faces->reset($reader);
    $config->setAppValue('nextgallery', 'ai_faces_legacy_inference_revision', '');
    $settings->save($beforeSettings);
}
