<?php
/** Separate face-reuse switch, index-scoped People listing and merge suggestions; synthetic/in-memory. */
declare(strict_types=1);
ob_start();
require __DIR__.'/php_faces.php';
ob_end_clean();

use OCA\Nextgallery\AI\Service\{Settings, Faces, FaceArtifacts};
use OCA\Nextgallery\Controller\{FacesController, FacesOcsController, FaceWorkerController};
use OCA\Nextgallery\Exception\FaceConflictException;
use OCP\AppFramework\OCS\OCSBadRequestException;

$checks = 0;

// 1. Face reuse has its own switch; without a choice it follows the general switch.
$shareConfig = new TestConfig();
$shareSettings = new Settings($shareConfig, new TestCrypto());
$shareDb = new TestDB();
$artifacts = new FaceArtifacts($shareDb, $shareConfig, $shareSettings);
check($artifacts->enabled(), 'Face reuse defaults to enabled like general result sharing');
$shareConfig->app['ai_share_results'] = 'no';
check(!$artifacts->enabled(), 'Without its own choice, face reuse follows a disabled general switch');
$stored = $shareSettings->defaults();
$stored['faces']['share_inference'] = true;
$shareConfig->app['ai_settings'] = json_encode($stored);
check($artifacts->enabled(), 'An explicit face choice is independent of the general switch');
$stored['faces']['share_inference'] = false;
$shareConfig->app['ai_settings'] = json_encode($stored);
$shareConfig->app['ai_share_results'] = 'yes';
check(!$artifacts->enabled(), 'Face reuse can be disabled while other results stay shared');

unset($stored['faces']['share_inference']);
$shareConfig->app['ai_settings'] = json_encode($stored);
$shareConfig->app['ai_share_results'] = 'no';
$candidate = $shareSettings->get();
unset($candidate['faces']['share_inference']);
check($shareSettings->save($candidate, null, false)['faces']['share_inference'] === false, 'A client unaware of the switch cannot silently enable face reuse');
$candidate['faces']['share_inference'] = 'yes';
checkThrows(fn() => $shareSettings->save($candidate, null, false), OCSBadRequestException::class, 'Non-boolean face reuse choice is rejected');

$shareDb->executeStatement('INSERT INTO nextgallery_faces_artifacts (file_id, etag, inference_revision, face_count, detections_json, created_at) VALUES (1, ?, ?, 0, ?, 1)', ['e', str_repeat('a', 64), '[]']);
$shareDb->executeStatement('INSERT INTO nextgallery_faces_artifact_refs (artifact_id, user_id, file_id, etag, created_at) VALUES (1, ?, 1, ?, 1)', ['alice', 'e']);
$artifacts->globalCleanup();
check((int)$shareDb->executeQuery('SELECT COUNT(*) FROM nextgallery_faces_artifacts')->fetchOne() === 0, 'Disabled face reuse withdraws the shared cache on cleanup');
check((int)$shareDb->executeQuery('SELECT COUNT(*) FROM nextgallery_faces_artifact_refs')->fetchOne() === 0, 'Disabled face reuse removes account references too');

// 2. Fresh account with three groups: A (2 photos), B (1 photo), C (2 photos).
$db = new TestDB();
$config = new TestConfig();
$settings = new Settings($config, new TestCrypto());
$settingsData = $settings->defaults();
$settingsData['faces']['enabled'] = true;
$settingsData['faces']['min_group_size'] = 2;
$config->setAppValue('nextgallery', 'ai_settings', json_encode($settingsData));
$inventory = new TestInventory();
$library = new TestLibrary($db);
$faces = new Faces($db, $settings, $inventory, $library, new TestLock(), rootFolder: new TestRootFolder());
$space = Faces::MODEL_SPACE;
$A = str_repeat('a', 32); $B = str_repeat('b', 32); $C = str_repeat('c', 32); $X = str_repeat('e', 32);
$vector = fn(int $axis) => array_merge(array_fill(0, $axis, 0.0), [1.0], array_fill(0, 127 - $axis, 0.0));
$fileGroup = [201 => $A, 202 => $A, 203 => $B, 204 => $C, 205 => $C];
foreach ($fileGroup as $fid => $gid) {
    $path = '/Synthetic/p' . $fid . '.jpg';
    $file = new TestMockFile($fid, 'etag' . $fid, path: $path);
    $inventory->files[$fid] = $file;
    $library->media[$fid] = $file;
    $db->executeStatement(
        'INSERT INTO nextgallery_media (user_id, file_id, sort_key, period, kind, etag, generation, payload, name_text, path_text) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        ['alice', $fid, '2026102000' . $fid, '2026-10', 'image', 'etag' . $fid, 'g', json_encode(['id' => $fid, 'path' => $path, 'etag' => 'etag' . $fid]), 'p' . $fid . '.jpg', strtolower($path)]
    );
    $acc = $faces->accountState('alice');
    $faces->publish('alice', $fid, 'etag' . $fid, $acc['epoch'], $acc['generation'], $acc['rules_revision'], $space,
        [['bbox' => [0.1, 0.1, 0.3, 0.3], 'vector' => $vector($fid % 3), 'score' => 0.9, 'group_id' => $gid]]);
}

$req = new MockRequest();
$sess = new MockUserSession();
$sess->user = new MockUser('alice');
$ctrl = new FacesController($req, $sess, $faces, $library);

$all = $faces->subjects('alice');
check(count($all['items']) === 2, 'Main view lists the two groups with at least two photos');
check(array_column($all['items'], 'photo_count') === [2, 2], 'Counts come from the scoped gallery index');
$library->media[201] = new TestMockFile(201, 'changed', path: '/Synthetic/p201.jpg');
$coverA = array_values(array_filter($faces->subjects('alice')['items'], fn($i) => $i['id'] === $A))[0];
check($faces->getDetection('alice', $coverA['cover_id'])['file_id'] === 202, 'A changed file is never used as cover; the next readable one is');
$library->media[202] = new TestMockFile(202, 'changed', path: '/Synthetic/p202.jpg');
check(!in_array($A, array_column($faces->subjects('alice')['items'], 'id'), true), 'A group without any readable photo is not shown');
$mediaA = $faces->media('alice', $A);
check($mediaA['items'] === [] && $mediaA['more'] === false, 'Unreadable media are dropped from the returned page');
$library->media[201] = $inventory->files[201];
$library->media[202] = $inventory->files[202];
$mediaA = $faces->media('alice', $A, '', 0, 1);
check(count($mediaA['items']) === 1 && $mediaA['more'] === true && $mediaA['next'] === 1, 'Media paginate over index rows');
check($mediaA['items'][0]['id'] === 202, 'Media keep newest-first order');
check(count($faces->media('alice', $A, '', 1, 1)['items']) === 1, 'Second media page is returned');

// 3. Fail closed without a home folder (point 5).
$noRoot = new Faces($db, $settings, $inventory, $library, new TestLock());
check($noRoot->authorizedFile('alice', 201, 'etag201', ['/Synthetic']) === null, 'Without a root folder no file is authorized');
check($noRoot->subjects('alice')['items'] === [], 'Without a root folder no group or cover is exposed');
check($noRoot->media('alice', $A)['items'] === [], 'Without a root folder no media are exposed');
$noRootCtrl = new FacesController($req, $sess, $noRoot, $library);
check($noRootCtrl->preview($coverA['cover_id'])->status === 404, 'Without a root folder no face crop is rendered');
check($faces->authorizedFile('alice', 201, 'etag201', ['/Other']) === null, 'Files outside the requested gallery are not authorized');

// 4. Worker suggestions are bound to epoch and generation and filtered on read.
$acc = $faces->accountState('alice');
checkThrows(fn() => $faces->publishSuggestions('alice', 'wrong', $acc['generation'], []), FaceConflictException::class, 'Suggestions from another epoch are rejected');
checkThrows(fn() => $faces->publishSuggestions('alice', $acc['epoch'], $acc['generation'] + 1, []), FaceConflictException::class, 'Suggestions from another generation are rejected');
checkThrows(fn() => $faces->publishSuggestions('alice', $acc['epoch'], $acc['generation'], [['a' => $A, 'b' => $A, 'similarity' => 0.5]]), OCSBadRequestException::class, 'A self pair is rejected');
checkThrows(fn() => $faces->publishSuggestions('alice', $acc['epoch'], $acc['generation'], [['a' => 'x', 'b' => $A, 'similarity' => 0.5]]), OCSBadRequestException::class, 'Malformed group IDs are rejected');
$stored = $faces->publishSuggestions('alice', $acc['epoch'], $acc['generation'], [
    ['a' => $A, 'b' => $B, 'similarity' => 0.61],
    ['a' => $C, 'b' => $A, 'similarity' => 0.48],
    ['a' => $A, 'b' => $X, 'similarity' => 0.9],
]);
check($stored === 2, 'Pairs with unknown groups are not stored');
$similarA = $faces->similar('alice', $A);
check(array_column($similarA['items'], 'id') === [$B, $C], 'Similar groups are ordered by similarity');
check($similarA['items'][0]['photo_count'] === 1 && !isset($similarA['items'][0]['vector']), 'Rare groups are suggested; no vectors are exposed');
check($similarA['subject']['photo_count'] === 2, 'The current group count is returned');
check(array_column($faces->similar('alice', $B)['items'], 'id') === [$A], 'Suggestions are symmetric');
$suggested = $faces->subjects('alice', '', 0, 60, 'suggested');
check(array_column($suggested['items'], 'id') === [$C, $A] || array_column($suggested['items'], 'id') === [$A, $C], 'Suggested filter lists main groups with open suggestions');
$similarRes = $ctrl->similar($A);
check($similarRes->getStatus() === 200 && count($similarRes->getData()['items']) === 2, 'Web endpoint returns suggestions');
$req->headers['OCS-APIRequest'] = 'true';
check(count((new FacesOcsController($req, $sess, $faces, $library))->similar($A)->getData()['items']) === 2, 'OCS endpoint returns suggestions');
check($ctrl->similar('nope')->getStatus() === 400, 'Invalid group ID is rejected');

// 5. "Different person" and split decisions suppress suggestions.
$rev = $faces->accountState('alice')['rules_revision'];
checkThrows(fn() => $faces->action('alice', $A, 'dismiss', $A, null, $rev), OCSBadRequestException::class, 'Dismissing a group against itself is rejected');
$res = $faces->action('alice', $A, 'dismiss', $C, null, $rev);
check($res['rules_revision'] === $rev + 1, 'Dismiss is a correction rule');
check(array_column($faces->similar('alice', $A)['items'], 'id') === [$B], 'Dismissed pair is no longer suggested');
check(array_column($faces->similar('alice', $C)['items'], 'id') === [], 'Dismissal covers both directions');
$faces->publishSuggestions('alice', $acc['epoch'], $acc['generation'], [['a' => $A, 'b' => $C, 'similarity' => 0.7], ['a' => $A, 'b' => $B, 'similarity' => 0.61]]);
check(array_column($faces->similar('alice', $A)['items'], 'id') === [$B], 'Dismissal survives a new worker result');

// Split one photo of C into a new group: that group must not be suggested back to C.
$detC = $faces->media('alice', $C)['items'][0]['face_detection_ids'][0];
$rev = $faces->accountState('alice')['rules_revision'];
$D = str_repeat('d', 32);
$faces->action('alice', $C, 'split', $D, [$detC], $rev);
$faces->publishSuggestions('alice', $acc['epoch'], $acc['generation'], [['a' => $C, 'b' => $D, 'similarity' => 0.8], ['a' => $A, 'b' => $B, 'similarity' => 0.61]]);
check(array_column($faces->similar('alice', $C)['items'], 'id') === [], 'A split decision implicitly marks the pair as different people');

// 6. Merging follows suggestions and decisions to the target.
$rev = $faces->accountState('alice')['rules_revision'];
$faces->action('alice', $B, 'merge', $A, null, $rev);
check($faces->similar('alice', $A)['items'] === [], 'A merged suggestion disappears');
check((int)$db->executeQuery('SELECT COUNT(*) FROM nextgallery_faces_suggestions WHERE group_a = group_b')->fetchOne() === 0, 'Merging leaves no self suggestion');
$faces->publishSuggestions('alice', $acc['epoch'], $acc['generation'], [['a' => $A, 'b' => $D, 'similarity' => 0.55]]);
$rev = $faces->accountState('alice')['rules_revision'];
$faces->action('alice', $D, 'dismiss', $C, null, $rev);
$rev = $faces->accountState('alice')['rules_revision'];
$faces->action('alice', $D, 'merge', $A, null, $rev);
$pairs = $db->executeQuery('SELECT group_a, group_b FROM nextgallery_faces_dismissed WHERE user_id = ?', ['alice'])->fetchAll();
check(in_array(['group_a' => $A, 'group_b' => $C], $pairs, true), 'Decisions of a merged group move to the target');

// 7. Cleanup and reset remove suggestion data.
$db->executeStatement('INSERT INTO nextgallery_faces_suggestions (user_id, group_a, group_b, similarity, created_at) VALUES (?, ?, ?, 0.5, 1)', ['alice', $A, $X]);
$db->executeStatement('INSERT INTO filecache (fileid, etag) VALUES (201, ?), (202, ?), (203, ?), (204, ?), (205, ?)', ['etag201', 'etag202', 'etag203', 'etag204', 'etag205']);
$faces->cleanup('alice');
check((int)$db->executeQuery('SELECT COUNT(*) FROM nextgallery_faces_suggestions WHERE group_b = ?', [$X])->fetchOne() === 0, 'Cleanup removes suggestions of vanished groups');
$faces->reset('alice');
check((int)$db->executeQuery('SELECT COUNT(*) FROM nextgallery_faces_suggestions')->fetchOne() === 0, 'Reset removes suggestions');
check((int)$db->executeQuery('SELECT COUNT(*) FROM nextgallery_faces_dismissed')->fetchOne() === 0, 'Reset removes decisions');

// 8. Worker endpoint.
$workerReq = new MockRequest();
$workerReq->headers = ['Authorization' => 'Bearer ' . $settings->workerToken(), 'X-Nextgallery-User' => 'alice'];
$worker = new FaceWorkerController($workerReq, $settings, $inventory, $faces, new MockUserManager());
$acc = $faces->accountState('alice');
check($worker->suggestions($acc['epoch'], $acc['generation'], [])->getData()['stored'] === 0, 'Worker endpoint accepts an empty result');
check($worker->suggestions('stale', $acc['generation'], [])->getStatus() === 409, 'Worker endpoint rejects a stale epoch');
check($worker->suggestions($acc['epoch'], $acc['generation'], [['a' => 1]])->getStatus() === 400, 'Worker endpoint rejects malformed pairs');
$workerReq->headers = [];
check($worker->suggestions($acc['epoch'], $acc['generation'], [])->getStatus() === 401, 'Worker endpoint requires the worker token');

echo "$checks face sharing, scoped listing and suggestion assertions passed\n";
