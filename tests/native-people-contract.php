<?php
declare(strict_types=1);

require '/var/www/html/lib/base.php';
\OC_User::setIncognitoMode(false);

set_exception_handler(function(\Throwable $e): void {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
});

use OCP\Server;
use OCP\{IDBConnection, IUserSession, IUserManager, IConfig, IRequest};
use OCP\Files\IRootFolder;
use OCA\Nextgallery\AI\Service\{Settings, Faces, FaceArtifacts};
use OCA\Nextgallery\Service\{Library, NativeSearch, NativePeople};
use OCA\Nextgallery\Controller\{NativePeopleController, FacesController};
use OCA\Nextgallery\Exception\FaceConflictException;

if (!is_file('/test-output/ISOLATED_SYNTHETIC_TEST')) {
    throw new RuntimeException('Synthetic guard missing');
}

$checks = 0;
function peopleCheck(bool $ok, string $label): void {
    global $checks;
    $checks++;
    if (!$ok) {
        throw new RuntimeException("Assertion failed: $label");
    }
}

class PeopleSyntheticEmbeddingClient extends \OCA\Nextgallery\AI\Service\EmbeddingClient {
    protected function request(array $config, string $endpoint, ?array $body = null, int $timeout = 10): array {
        if ($endpoint === 'info') return ['space' => str_repeat('a', 64), 'model' => $config['model'], 'family' => $config['family'], 'dimensions' => 128, 'modalities' => ['text', 'image']];
        return ['space' => str_repeat('a', 64), 'embedding' => array_merge([1.], array_fill(0, 127, 0.))];
    }
}

$users = Server::get(IUserManager::class);
$session = Server::get(IUserSession::class);
$root = Server::get(IRootFolder::class);
$settings = Server::get(Settings::class);
$faces = Server::get(Faces::class);
$art = Server::get(FaceArtifacts::class);
$library = Server::get(Library::class);
$cfg = Server::get(IConfig::class);
$db = Server::get(IDBConnection::class);

$uids = ['native-people-owner', 'native-people-other'];
foreach ($uids as $uid) {
    $users->get($uid)?->delete();
}

$oldSettings = $settings->get();
$oldShare = $cfg->getAppValue('nextgallery', 'ai_share_results', 'yes');

try {
    $c = $oldSettings;
    $c['faces']['enabled'] = true;
    $settings->save($c);
    $cfg->setAppValue('nextgallery', 'ai_share_results', 'yes');

    foreach ($uids as $uid) {
        $users->createUser($uid, 'SyntheticPeoplePass2026!');
    }

    $ownerUid = $uids[0];
    $session->setUser($users->get($ownerUid));
    $userHome = $root->getUserFolder($ownerUid);

    // Setup folder tree
    $photosFolder = $userHome->newFolder('Photos');
    $privateFolder = $userHome->newFolder('Private');

    $sampleImg = file_get_contents('/samples/dog.png');

    // Create files with dates in names or folders
    $files = [];
    foreach (['p1-a', 'p1-b', 'p1-c', 'p2-weak', 'p3-hidden', 'multi', 'outside'] as $name) {
        $folder = ($name === 'outside') ? $privateFolder : $photosFolder;
        $files[$name] = $folder->newFile($name . '.png', $sampleImg);
    }

    // Configure account settings and index into library
    $settings->saveAccount($ownerUid, [
        'enabled' => false,
        'folders' => ['/Photos', '/Private'],
        'folder_stages' => ['/Photos' => ['faces'], '/Private' => ['faces']],
        'faces_filters' => Settings::DEFAULT_FACE_FILTERS,
    ]);
    $library->save($ownerUid, ['roots' => ['/Photos', '/Private']]);
    do {
        $step = $library->step($ownerUid, false, 100, 10);
    } while ($step['running']);

    $account = $faces->accountState($ownerUid);
    $rev = $art->currentRevision();
    $vec = array_merge([1.0], array_fill(0, 127, 0.0));

    // Groups
    $gid1 = str_repeat('1', 32); // Ordinary visible good quality group (3 photos)
    $gid2 = str_repeat('2', 32); // Under min_group_photos (<3 photos)
    $gid3 = str_repeat('3', 32); // Hidden group
    $gid4 = str_repeat('4', 32); // Secondary group on multi-face file
    $gid5 = str_repeat('5', 32); // Outside roots (/Private)

    // Publish detections
    // gid1 on p1-a, p1-b, p1-c (all sharp, large)
    foreach (['p1-a', 'p1-b', 'p1-c'] as $fn) {
        $faces->publish($ownerUid, $files[$fn]->getId(), $files[$fn]->getEtag(), $account['epoch'], $account['generation'], $account['rules_revision'], Faces::MODEL_SPACE, [
            ['bbox' => [.1, .1, .3, .3], 'vector' => $vec, 'score' => .95, 'sharpness' => 80.0, 'group_id' => $gid1]
        ], $rev);
    }

    // gid2 on p2-weak (only 1 photo, small face area < min_face_area)
    $faces->publish($ownerUid, $files['p2-weak']->getId(), $files['p2-weak']->getEtag(), $account['epoch'], $account['generation'], $account['rules_revision'], Faces::MODEL_SPACE, [
        ['bbox' => [.1, .1, .02, .02], 'vector' => $vec, 'score' => .90, 'sharpness' => 80.0, 'group_id' => $gid2]
    ], $rev);

    // gid3 on p3-hidden (3 sharp photos, but will be marked hidden)
    $faces->publish($ownerUid, $files['p3-hidden']->getId(), $files['p3-hidden']->getEtag(), $account['epoch'], $account['generation'], $account['rules_revision'], Faces::MODEL_SPACE, [
        ['bbox' => [.1, .1, .3, .3], 'vector' => $vec, 'score' => .95, 'sharpness' => 80.0, 'group_id' => $gid3]
    ], $rev);
    $db->executeStatement('UPDATE *PREFIX*nextgallery_faces_groups SET hidden = 1 WHERE user_id = ? AND id = ?', [$ownerUid, $gid3]);

    // multi file has both gid1 and gid4
    // gid4 also needs 3 photos to be ordinary visible
    $faces->publish($ownerUid, $files['multi']->getId(), $files['multi']->getEtag(), $account['epoch'], $account['generation'], $account['rules_revision'], Faces::MODEL_SPACE, [
        ['bbox' => [.1, .1, .3, .3], 'vector' => $vec, 'score' => .92, 'sharpness' => 85.0, 'group_id' => $gid1],
        ['bbox' => [.5, .5, .3, .3], 'vector' => $vec, 'score' => .91, 'sharpness' => 82.0, 'group_id' => $gid4],
    ], $rev);
    // Add 2 more photos for gid4 so gid4 is ordinary visible
    foreach (['p1-b', 'p1-c'] as $fn) {
        $faces->publish($ownerUid, $files[$fn]->getId(), $files[$fn]->getEtag(), $account['epoch'], $account['generation'], $account['rules_revision'], Faces::MODEL_SPACE, [
            ['bbox' => [.1, .1, .3, .3], 'vector' => $vec, 'score' => .95, 'sharpness' => 80.0, 'group_id' => $gid1],
            ['bbox' => [.5, .5, .3, .3], 'vector' => $vec, 'score' => .88, 'sharpness' => 75.0, 'group_id' => $gid4],
        ], $rev);
    }

    // gid5 in /Private
    $faces->publish($ownerUid, $files['outside']->getId(), $files['outside']->getEtag(), $account['epoch'], $account['generation'], $account['rules_revision'], Faces::MODEL_SPACE, [
        ['bbox' => [.1, .1, .3, .3], 'vector' => $vec, 'score' => .95, 'sharpness' => 80.0, 'group_id' => $gid5],
    ], $rev);

    // Initialize Services
    $nativePeople = new NativePeople($db, $root, $settings, $cfg, $faces, $library);
    $nativeSearch = new NativeSearch($db, $root, Server::get(\OCP\FilesMetadata\IFilesMetadataManager::class), $settings, Server::get(\OCA\Nextgallery\AI\Service\EmbeddingClient::class), null, $nativePeople);

    // --- TEST 1: Root scoping and validation ---
    try {
        $nativePeople->folders($ownerUid, ['/NonExistentFolder']);
        peopleCheck(false, 'Non-existent folder must throw InvalidArgumentException');
    } catch (\InvalidArgumentException) {
        peopleCheck(true, 'Non-existent folder throws InvalidArgumentException');
    }

    try {
        $nativePeople->folders($ownerUid, ['/../etc']);
        peopleCheck(false, 'Path traversal must throw InvalidArgumentException');
    } catch (\InvalidArgumentException) {
        peopleCheck(true, 'Path traversal throws InvalidArgumentException');
    }

    // --- TEST 2: POST /people/subjects (Fresh live query + deterministic overview revision token) ---
    // Only /Photos root: gid1 and gid4 are visible; gid2 (weak), gid3 (hidden), gid5 (outside root) excluded
    $subjResp = $nativePeople->subjects($ownerUid, ['/Photos']);
    peopleCheck($subjResp['enabled'] === true, 'enabled flag is true');
    peopleCheck(is_string($subjResp['snapshot_token']) && strlen($subjResp['snapshot_token']) === 32, 'snapshot_token is 32 hex chars');
    peopleCheck(count($subjResp['items']) === 2, 'exact 2 ordinary visible groups returned in /Photos');
    $returnedGids = array_column($subjResp['items'], 'id');
    peopleCheck(in_array($gid1, $returnedGids, true), 'gid1 is returned');
    peopleCheck(in_array($gid4, $returnedGids, true), 'gid4 is returned');
    peopleCheck(!in_array($gid2, $returnedGids, true), 'gid2 (weak) excluded');
    peopleCheck(!in_array($gid3, $returnedGids, true), 'gid3 (hidden) excluded');
    peopleCheck(!in_array($gid5, $returnedGids, true), 'gid5 (outside /Photos) excluded');
    peopleCheck(!str_contains(json_encode($subjResp), 'vector'), 'no vectors exposed in subjects');

    // Deterministic overview token across subjects calls
    $subjRespPage2 = $nativePeople->subjects($ownerUid, ['/Photos'], 0, 60, $subjResp['snapshot_token']);
    peopleCheck($subjRespPage2['snapshot_token'] === $subjResp['snapshot_token'], 'same overview token accepted');
    try {
        $nativePeople->subjects($ownerUid, ['/Photos'], 0, 60, str_repeat('9', 32));
        peopleCheck(false, 'stale overview token on subjects must throw FaceConflictException');
    } catch (FaceConflictException) {
        peopleCheck(true, 'stale overview token on subjects throws FaceConflictException');
    }

    // Each subject has label === null and cover_id as UUID32
    foreach ($subjResp['items'] as $item) {
        peopleCheck($item['label'] === null, 'label is null');
        peopleCheck(is_string($item['cover_id']) && strlen($item['cover_id']) === 32, 'cover_id is UUID32');
        peopleCheck($item['photo_count'] >= 3, 'photo_count respects min_group_photos');
    }

    // --- TEST 3: POST /people/media (Android date resolution, refill skipped candidates) ---
    $mediaResp = $nativePeople->media($ownerUid, $gid1, ['/Photos'], 0, 60, '', '', 'folder', 'UTC', 'desc');
    peopleCheck($mediaResp['rules_revision'] >= 1, 'rules_revision returned');
    // gid1 is in p1-a, p1-b, p1-c, and multi (4 photos)
    peopleCheck(count($mediaResp['items']) === 4, '4 media items for gid1');
    peopleCheck(!str_contains(json_encode($mediaResp), 'vector'), 'no vectors exposed in media');
    foreach ($mediaResp['items'] as $mi) {
        peopleCheck(isset($mi['id'], $mi['name'], $mi['path'], $mi['kind'], $mi['etag'], $mi['mtime'], $mi['size'], $mi['metadata']), 'standard media payload keys present');
        peopleCheck(isset($mi['date']['date']), 'date structure matching Android MediaDates');
    }

    // Test asc vs desc ordering
    $mediaAsc = $nativePeople->media($ownerUid, $gid1, ['/Photos'], 0, 60, '', '', 'folder', 'UTC', 'asc');
    peopleCheck(count($mediaAsc['items']) === 4, '4 media items in asc order');
    peopleCheck($mediaAsc['items'][0]['id'] !== $mediaResp['items'][0]['id'] || count($mediaAsc['items']) <= 1, 'asc order starts with different element than desc or list is 1');

    // Media pagination with filling
    $mediaPage1 = $nativePeople->media($ownerUid, $gid1, ['/Photos'], 0, 2);
    peopleCheck(count($mediaPage1['items']) === 2, 'media page 1 has 2 items');
    peopleCheck($mediaPage1['more'] === true && $mediaPage1['next'] === 2, 'more is true, next is 2');
    $mediaPage2 = $nativePeople->media($ownerUid, $gid1, ['/Photos'], 2, 2);
    peopleCheck(count($mediaPage2['items']) === 2, 'media page 2 has 2 items');
    peopleCheck($mediaPage2['more'] === false && $mediaPage2['next'] === null, 'page 2 is last');

    // --- TEST 4: POST /people/snapshot (Fresh initial materialization, pinned download pages, deterministic empty token) ---
    // Initial call without token must create a fresh materialized snapshot
    $snap1 = $nativePeople->snapshot($ownerUid, ['/Photos'], 0, 200);
    $snap2 = $nativePeople->snapshot($ownerUid, ['/Photos'], 0, 200);
    // Two initial calls without token produce fresh materializations with different tokens!
    peopleCheck($snap1['snapshot_token'] !== $snap2['snapshot_token'], 'initial /snapshot calls without token produce fresh materializations');

    $activeToken = $snap1['snapshot_token'];
    // Deduplication check: file 'multi' has 2 face detections (gid1 and gid4)
    // In snapshot items, multi must appear only ONCE with subject_ids containing both gid1 and gid4
    $multiId = $files['multi']->getId();
    $foundMulti = array_values(array_filter($snap1['items'], fn($it) => $it['file_id'] === $multiId));
    peopleCheck(count($foundMulti) === 1, 'multi file appears exactly once in snapshot items');
    peopleCheck(count($foundMulti[0]['subject_ids']) === 2, 'multi file has both subject IDs');
    peopleCheck(in_array($gid1, $foundMulti[0]['subject_ids'], true) && in_array($gid4, $foundMulti[0]['subject_ids'], true), 'subject_ids contain both gid1 and gid4');

    // Subsequent page with provided token uses the same materialization
    $snapPageWithToken = $nativePeople->snapshot($ownerUid, ['/Photos'], 0, 200, $activeToken);
    peopleCheck($snapPageWithToken['snapshot_token'] === $activeToken, 'provided token reuses frozen materialization');
    peopleCheck(count($snapPageWithToken['items']) === count($snap1['items']), 'same frozen items returned');

    // Invalidation check 1: Stale / random token => 409
    try {
        $nativePeople->snapshot($ownerUid, ['/Photos'], 0, 200, str_repeat('f', 32));
        peopleCheck(false, 'Unknown snapshot token must throw FaceConflictException');
    } catch (FaceConflictException) {
        peopleCheck(true, 'Unknown snapshot token throws FaceConflictException');
    }

    // Invalidation check 2: Rules revision bump => 409
    $faces->bumpRulesRevision($ownerUid);
    try {
        $nativePeople->snapshot($ownerUid, ['/Photos'], 0, 200, $activeToken);
        peopleCheck(false, 'Snapshot with bumped rules revision must throw FaceConflictException');
    } catch (FaceConflictException) {
        peopleCheck(true, 'Snapshot with bumped rules revision throws FaceConflictException');
    }

    // Invalidation check 3: Filter change => 409
    $snapFresh = $nativePeople->snapshot($ownerUid, ['/Photos'], 0, 200);
    $tokBeforeFilter = $snapFresh['snapshot_token'];
    $settings->saveAccount($ownerUid, ['faces_filters' => ['min_group_photos' => 4, 'min_face_area' => 0.0025, 'min_sharpness' => 45.0]]);
    try {
        $nativePeople->snapshot($ownerUid, ['/Photos'], 0, 200, $tokBeforeFilter);
        peopleCheck(false, 'Snapshot after filter change must throw FaceConflictException');
    } catch (FaceConflictException) {
        peopleCheck(true, 'Snapshot after filter change throws FaceConflictException');
    }
    // Restore filter
    $settings->saveAccount($ownerUid, ['faces_filters' => Settings::DEFAULT_FACE_FILTERS]);

    // Test disabled / empty deterministic token
    $cOff = $settings->get();
    $cOff['faces']['enabled'] = false;
    $settings->save($cOff);
    $disabledSnap1 = $nativePeople->snapshot($ownerUid, ['/Photos'], 0, 200);
    peopleCheck($disabledSnap1['enabled'] === false, 'disabled snap enabled is false');
    peopleCheck(is_string($disabledSnap1['snapshot_token']) && strlen($disabledSnap1['snapshot_token']) === 32, 'disabled snap token is 32 hex chars');
    $disabledSnap2 = $nativePeople->snapshot($ownerUid, ['/Photos'], 0, 200);
    peopleCheck($disabledSnap1['snapshot_token'] === $disabledSnap2['snapshot_token'], 'disabled snap returns stable deterministic token');
    // Verifying with matching token succeeds:
    $disabledVerify = $nativePeople->snapshot($ownerUid, ['/Photos'], 999, 200, $disabledSnap1['snapshot_token']);
    peopleCheck($disabledVerify['items'] === [] && $disabledVerify['more'] === false, 'disabled verify succeeds');
    // Verifying with mismatching token throws 409:
    try {
        $nativePeople->snapshot($ownerUid, ['/Photos'], 999, 200, str_repeat('0', 32));
        peopleCheck(false, 'disabled snapshot with stale token must throw FaceConflictException');
    } catch (FaceConflictException) {
        peopleCheck(true, 'disabled snapshot with stale token throws FaceConflictException');
    }
    // Re-enable faces
    $cOn = $settings->get();
    $cOn['faces']['enabled'] = true;
    $settings->save($cOn);

    // Empty last offset check on active download: requesting past total returns items: [], more: false, next: null and valid token
    $freshSnap = $nativePeople->snapshot($ownerUid, ['/Photos'], 0, 200);
    $freshToken = $freshSnap['snapshot_token'];
    $pastSnap = $nativePeople->snapshot($ownerUid, ['/Photos'], 9999, 200, $freshToken);
    peopleCheck($pastSnap['items'] === [], 'past offset returns empty items');
    peopleCheck($pastSnap['more'] === false && $pastSnap['next'] === null, 'past offset more is false, next is null');
    peopleCheck($pastSnap['snapshot_token'] === $freshToken, 'past offset preserves valid snapshot token');

    // --- TEST 5: Preview endpoint (FacesController::previewPeople) ---
    $facesController = new FacesController(
        Server::get(IRequest::class),
        $session,
        $faces,
        $library,
        Server::get(\OCP\IPreview::class),
        $root
    );

    // Get a detection ID from gid1 in /Photos
    $detRow = $db->executeQuery('SELECT id FROM *PREFIX*nextgallery_faces_detections WHERE user_id=? AND group_id=? LIMIT 1', [$ownerUid, $gid1])->fetch();
    $detIdInPhotos = (string)$detRow['id'];

    // Get detection ID from gid5 in /Private
    $detRow5 = $db->executeQuery('SELECT id FROM *PREFIX*nextgallery_faces_detections WHERE user_id=? AND group_id=? LIMIT 1', [$ownerUid, $gid5])->fetch();
    $detIdInPrivate = (string)$detRow5['id'];

    // Preview for detIdInPhotos with roots=['/Photos'] -> 200 JPEG
    $previewOk = $facesController->previewPeople($detIdInPhotos, ['/Photos']);
    peopleCheck($previewOk->getStatus() === 200, 'preview inside explicit roots returns 200');

    // Preview for detIdInPrivate with roots=['/Photos'] -> 404 (strictly honors explicit roots!)
    $previewForbidden = $facesController->previewPeople($detIdInPrivate, ['/Photos']);
    peopleCheck($previewForbidden->getStatus() === 404, 'preview outside explicit roots returns 404');

    // Preview with invalid roots -> 404
    $previewInvalid = $facesController->previewPeople($detIdInPhotos, ['/NonExistent']);
    peopleCheck($previewInvalid->getStatus() === 404, 'preview with invalid roots returns 404');

    // Preview with empty roots MUST DENY (not fallback to web gallery!)
    $previewEmptyRoots = $facesController->previewPeople($detIdInPhotos, []);
    peopleCheck($previewEmptyRoots->getStatus() === 404, 'previewPeople with empty roots denies 404');

    // --- TEST 6: subjectFileIds & NativeSearch with subject_id filter ---
    // subjectFileIds rejects hidden groups:
    $hiddenIds = $nativePeople->subjectFileIds($ownerUid, $gid3, ['/Photos']);
    peopleCheck(empty($hiddenIds), 'subjectFileIds rejects hidden group');

    // subjectFileIds rejects groups below min_group_photos:
    $weakIds = $nativePeople->subjectFileIds($ownerUid, $gid2, ['/Photos']);
    peopleCheck(empty($weakIds), 'subjectFileIds rejects group below min_group_photos');

    // subjectFileIds returns [fileId => face_etag] map for eligible group:
    $goodIds = $nativePeople->subjectFileIds($ownerUid, $gid1, ['/Photos']);
    peopleCheck(count($goodIds) === 4, 'subjectFileIds returns 4 files for gid1');
    peopleCheck(is_string(reset($goodIds)), 'subjectFileIds returns string etag values');

    // Search for 'p1' without subject_id:
    $searchAll = $nativeSearch->search($ownerUid, 'p1', ['/Photos'], '', 0, 60);
    peopleCheck(count($searchAll['items']) >= 3, 'unfiltered search finds p1 files');
    peopleCheck(!isset($searchAll['subject_id']), 'old search response has no subject_id');

    // Search with subject_id = gid1:
    $searchWithPerson = $nativeSearch->search($ownerUid, 'p1', ['/Photos'], '', 0, 60, 'hybrid', '', 'folder', 'UTC', '', false, $gid1);
    peopleCheck(isset($searchWithPerson['subject_id']) && $searchWithPerson['subject_id'] === $gid1, 'response includes matching subject_id');
    peopleCheck(count($searchWithPerson['items']) >= 3, 'person search returns files matching query AND person');

    // Search with subject_id for a person not in p1 files (e.g. gid5 outside /Photos):
    $searchPersonMismatch = $nativeSearch->search($ownerUid, 'p1', ['/Photos'], '', 0, 60, 'hybrid', '', 'folder', 'UTC', '', false, $gid5);
    peopleCheck(isset($searchPersonMismatch['subject_id']) && $searchPersonMismatch['subject_id'] === $gid5, 'subject_id preserved on empty search result');
    peopleCheck(count($searchPersonMismatch['items']) === 0, 'no hits when person does not match');

    // Search with hidden group subject_id (gid3):
    $searchHiddenPerson = $nativeSearch->search($ownerUid, 'p3', ['/Photos'], '', 0, 60, 'hybrid', '', 'folder', 'UTC', '', false, $gid3);
    peopleCheck(isset($searchHiddenPerson['subject_id']) && $searchHiddenPerson['subject_id'] === $gid3, 'subject_id preserved on hidden person search');
    peopleCheck(count($searchHiddenPerson['items']) === 0, 'hidden person yields 0 search results');

    // Real sqlite-vec candidates, deterministic local query vectors, no external model calls.
    $beforeVectorConfig = $settings->get();
    try {
        $vectorConfig = $beforeVectorConfig; $vectorConfig['embedding']['enabled'] = true; $settings->save($vectorConfig);
        $embeddingConfig = $settings->workerConfig()['embedding'];
        $embeddingClient = new PeopleSyntheticEmbeddingClient(); $info = $embeddingClient->info($embeddingConfig);
        $space = \OCA\Nextgallery\AI\Service\EmbeddingClient::space($info, $embeddingConfig);
        $positive = array_merge([1.], array_fill(0, 127, 0.));
        $vectors = Server::get(\OCA\Nextgallery\AI\Service\Embeddings::class);
        foreach (['p1-a', 'p3-hidden'] as $key) $vectors->publish($ownerUid, $files[$key]->getId(), $files[$key]->getEtag(), $space, [['frame' => 0, 'seconds' => 0, 'values' => $positive]]);
        $hybrid = new NativeSearch($db, $root, Server::get(\OCP\FilesMetadata\IFilesMetadataManager::class), $settings, $embeddingClient, null, $nativePeople);
        $query = 'syntheticsemanticnomatch';
        $allVectors = $hybrid->search($ownerUid, $query, ['/Photos'], '', 0, 60, 'hybrid');
        peopleCheck($allVectors['embedding_state'] === 'ready' && count($allVectors['items']) === 2, 'two real vector candidates without lexical matches');
        $personVectors = $hybrid->search($ownerUid, $query, ['/Photos'], '', 0, 60, 'hybrid', '', 'folder', 'UTC', '', false, $gid1);
        peopleCheck(array_column($personVectors['items'], 'id') === [$files['p1-a']->getId()], 'person AND real vector search excludes other group');
        peopleCheck(in_array('embedding', $personVectors['items'][0]['search_match']['sources'], true), 'person vector hit retains accurate match reason');
        try {
            $hybrid->search($ownerUid, $query, ['/Photos'], '', 0, 60, 'hybrid', '', 'folder', 'UTC', $personVectors['search_token'], false, $gid4);
            peopleCheck(false, 'cross-person search token must be rejected');
        } catch (\InvalidArgumentException) { peopleCheck(true, 'cross-person search token rejected'); }
    } finally { $settings->save($beforeVectorConfig); }

    // Search when faces is disabled:
    $cOff = $settings->get();
    $cOff['faces']['enabled'] = false;
    $settings->save($cOff);
    $searchDisabled = $nativeSearch->search($ownerUid, 'p1', ['/Photos'], '', 0, 60, 'hybrid', '', 'folder', 'UTC', '', false, $gid1);
    peopleCheck(count($searchDisabled['items']) === 0, 'disabled faces yields 0 person search results');
    peopleCheck($searchDisabled['subject_id'] === $gid1, 'subject_id echoed when disabled');
    $cOn = $settings->get();
    $cOn['faces']['enabled'] = true;
    $settings->save($cOn);

    // Stale ETag check: modify a file's content in Nextcloud, so its ETag changes
    $p1aFile = $files['p1-a'];
    $p1aId = $p1aFile->getId();
    $oldFileEtag = $p1aFile->getEtag();
    $p1aFile->putContent('new modified content ' . time());
    $newFileEtag = $p1aFile->getEtag();
    peopleCheck($oldFileEtag !== $newFileEtag, 'file etag has changed on disk');

    // NativeSearch should now exclude p1-a because its live ETag does not match the detection face_etag:
    $searchStaleEtag = $nativeSearch->search($ownerUid, 'p1', ['/Photos'], '', 0, 60, 'hybrid', '', 'folder', 'UTC', '', false, $gid1);
    $returnedFileIds = array_column($searchStaleEtag['items'], 'id');
    peopleCheck(!in_array($p1aId, $returnedFileIds, true), 'file with modified etag excluded from person search');

    // Invalid subject_id format throws InvalidArgumentException
    try {
        $nativeSearch->search($ownerUid, 'p1', ['/Photos'], '', 0, 60, 'hybrid', '', 'folder', 'UTC', '', false, 'invalid-not-hex');
        peopleCheck(false, 'Invalid subject_id must throw InvalidArgumentException');
    } catch (\InvalidArgumentException) {
        peopleCheck(true, 'Invalid subject_id throws InvalidArgumentException');
    }

    // --- TEST 7: NativePeopleController OCS envelope & error codes ---
    $dummyReq = Server::get(IRequest::class);
    $peopleController = new NativePeopleController($dummyReq, $session, $nativePeople);

    // Request missing OCS-APIRequest header throws OCSForbiddenException
    try {
        $peopleController->subjects(['/Photos']);
        peopleCheck(false, 'Missing OCS-APIRequest header must throw OCSForbiddenException');
    } catch (\OCP\AppFramework\OCS\OCSForbiddenException) {
        peopleCheck(true, 'Missing OCS-APIRequest header throws OCSForbiddenException');
    }

    echo "$checks real Android People integration tests passed successfully!\n";
} finally {
    foreach ($uids as $uid) {
        $users->get($uid)?->delete();
    }
    $settings->save($oldSettings);
    $cfg->setAppValue('nextgallery', 'ai_share_results', $oldShare);
}
