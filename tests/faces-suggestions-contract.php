<?php
/** Real Nextcloud/MariaDB contract: face-reuse switch, index-scoped People listing, merge suggestions. Disposable synthetic instance only. */
declare(strict_types=1);
$_SERVER['HTTP_OCS_APIREQUEST'] = 'true';
require '/var/www/html/lib/base.php';
\OC_User::setIncognitoMode(false);
set_exception_handler(function(\Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . ' at ' . $error->getFile() . ':' . $error->getLine() . "\n");
    exit(1);
});

use OCP\Server;
use OCP\{IDBConnection, IConfig, IUserSession, IUserManager};
use OCP\Files\IRootFolder;
use OCA\Nextgallery\AI\Service\{Settings, Faces, FaceArtifacts};
use OCA\Nextgallery\Service\Library;
use OCA\Nextgallery\Exception\FaceConflictException;

if (!is_file('/test-output/ISOLATED_SYNTHETIC_TEST')) {
    throw new RuntimeException('Synthetic guard missing');
}

$checks = 0;
function expectSuggestion(bool $condition, string $message): void {
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException('Suggestion contract assertion failed: ' . $message);
    }
}
function count_rows(IDBConnection $db, string $sql, array $params): int {
    $r = $db->executeQuery($sql, $params);
    try { return (int)$r->fetchOne(); } finally { $r->closeCursor(); }
}

$db = Server::get(IDBConnection::class);
$appConfig = Server::get(IConfig::class);
$settings = Server::get(Settings::class);
$faces = Server::get(Faces::class);
$artifacts = Server::get(FaceArtifacts::class);
$library = Server::get(Library::class);
$users = Server::get(IUserManager::class);
$session = Server::get(IUserSession::class);
$rootFolder = Server::get(IRootFolder::class);

$beforeSettings = $appConfig->getAppValue('nextgallery', 'ai_settings', '{}');
$beforeShare = $appConfig->getAppValue('nextgallery', 'ai_share_results', "\x01");
$uid = 'account-suggest';

try {
    // 1. Separate face-reuse switch with the general switch as legacy fallback.
    $stored = json_decode($appConfig->getAppValue('nextgallery', 'ai_settings', '{}'), true) ?: [];
    unset($stored['faces']['share_inference']);
    $appConfig->setAppValue('nextgallery', 'ai_settings', json_encode($stored));
    $appConfig->setAppValue('nextgallery', 'ai_share_results', 'no');
    expectSuggestion(!$artifacts->enabled(), 'Unchosen face reuse follows the general switch');
    $c = $settings->get();
    $c['faces']['enabled'] = true;
    $c['faces']['min_group_size'] = 2;
    unset($c['faces']['share_inference']);
    $settings->save($c);
    expectSuggestion($settings->get()['faces']['share_inference'] === false, 'Saving without the field keeps reuse disabled');
    $c['faces']['share_inference'] = true;
    $settings->save($c);
    expectSuggestion($artifacts->enabled(), 'Explicit face reuse is independent of the general switch');
    $appConfig->setAppValue('nextgallery', 'ai_share_results', 'yes');
    $c['faces']['share_inference'] = false;
    $settings->save($c);
    expectSuggestion(!$artifacts->enabled(), 'Face reuse can be off while other results are shared');
    $c['faces']['share_inference'] = true;
    $settings->save($c);

    // 2. Account with four synthetic photos in two folders.
    $user = $users->get($uid) ?? $users->createUser($uid, 'SyntheticAccountOnly2026!');
    $session->setUser($user);
    $home = $rootFolder->getUserFolder($uid);
    $data = is_file('/samples/dog.png') ? file_get_contents('/samples/dog.png') : "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x10\x00\x00\x00\x10\x08\x02\x00\x00\x00\x90\x91h6\x00\x00\x00\x0cIDATx\x9cc\xf8\xff\xff?\x00\x05\xfe\x02\xfe\xa74\x813\x00\x00\x00\x00IEND\xaeB`\x82";
    $files = [];
    foreach (['Suggest/a1.png', 'Suggest/a2.png', 'Suggest/b1.png', 'Suggest/c1.png', 'Suggest/c2.png', 'Suggest/w1.png', 'Elsewhere/e1.png'] as $path) {
        [$dir, $name] = explode('/', $path);
        $folder = $home->nodeExists($dir) ? $home->get($dir) : $home->newFolder($dir);
        $files[$path] = $folder->nodeExists($name) ? $folder->get($name) : $folder->newFile($name, $data . $path);
    }
    $settings->saveAccount($uid, ['folders' => ['/Suggest', '/Elsewhere'], 'enabled' => false,
        'folder_stages' => ['/Suggest' => ['faces'], '/Elsewhere' => ['faces']]]);
    $library->save($uid, ['activeGallery' => 'default', 'roots' => ['/Suggest']]);
    $other = array_values(array_filter($library->settings($uid)['galleries'], fn($g) => $g['name'] === 'Elsewhere'))[0]['id'] ?? null;
    if ($other === null) {
        $library->save($uid, ['createGallery' => 'Elsewhere', 'roots' => ['/Elsewhere']]);
        $other = $library->settings($uid)['activeGallery'];
        $library->save($uid, ['activeGallery' => 'default']);
    }
    $st = $library->step($uid, true, 50, 5);
    while (!empty($st['running'])) $st = $library->step($uid, false, 50, 5);
    $faces->purge($uid);

    $A = str_repeat('a', 32); $B = str_repeat('b', 32); $C = str_repeat('c', 32); $E = str_repeat('e', 32); $W = str_repeat('9', 32);
    $groups = ['Suggest/a1.png' => $A, 'Suggest/a2.png' => $A, 'Suggest/b1.png' => $B, 'Suggest/c1.png' => $C, 'Suggest/c2.png' => $C, 'Suggest/w1.png' => $W, 'Elsewhere/e1.png' => $E];
    $axis = 0;
    foreach ($groups as $path => $gid) {
        $acc = $faces->accountState($uid);
        $vector = array_fill(0, 128, 0.0); $vector[$axis++ % 128] = 1.0;
        // The W face covers 0.16% of the image: below the default area filter.
        $bbox = $gid === $W ? [0.1, 0.1, 0.04, 0.04] : [0.1, 0.1, 0.3, 0.3];
        $faces->publish($uid, $files[$path]->getId(), $files[$path]->getEtag(), $acc['epoch'], $acc['generation'], $acc['rules_revision'], Faces::MODEL_SPACE,
            [['bbox' => $bbox, 'vector' => $vector, 'score' => 0.9, 'group_id' => $gid]]);
    }

    // 3. Index-scoped counts with live-checked covers and media on MariaDB.
    $main = $faces->subjects($uid, 'default');
    expectSuggestion(count($main['items']) === 2 && array_column($main['items'], 'photo_count') === [2, 2], 'Main view counts two groups from the gallery index');
    expectSuggestion(!in_array($E, array_column($faces->subjects($uid, 'default', 0, 60, 'single')['items'], 'id'), true), 'Another gallery\'s group is out of scope');
    $media = $faces->media($uid, $A, 'default', 0, 1);
    expectSuggestion(count($media['items']) === 1 && $media['more'] === true, 'Media paginate over the index');
    $previewOwner = $faces->authorizedFile($uid, $files['Suggest/a1.png']->getId(), $files['Suggest/a1.png']->getEtag(), ['/Suggest']);
    expectSuggestion($previewOwner !== null && $previewOwner['path'] === '/Suggest/a1.png', 'Live authorization returns the current path');
    expectSuggestion($faces->authorizedFile($uid, $files['Suggest/a1.png']->getId(), 'stale', ['/Suggest']) === null, 'A stale version is not authorized');
    expectSuggestion($faces->authorizedFile($uid, $files['Suggest/a1.png']->getId(), $files['Suggest/a1.png']->getEtag(), ['/Elsewhere']) === null, 'Another gallery is not authorized');

    // 3b. Filtered faces never anchor: only strong groups are offered, and merging confirms them.
    $acc = $faces->accountState($uid);
    $faces->publishSuggestions($uid, $acc['epoch'], $acc['generation'], [
        ['a' => $A, 'b' => $W, 'similarity' => 0.7], ['a' => $B, 'b' => $W, 'similarity' => 0.8],
    ]);
    expectSuggestion(in_array($W, array_column($faces->subjects($uid, 'default', 0, 60, 'filtered')['items'], 'id'), true), 'Weak group is listed as filtered');
    $weak = $faces->similar($uid, $W, 'default');
    expectSuggestion(array_column($weak['items'], 'id') === [$A], 'A filtered group is offered only groups with two good faces');
    expectSuggestion($weak['subject']['photo_count'] === 0 && $weak['subject']['filtered_count'] === 1, 'Source reports its filtered faces');
    expectSuggestion($faces->similar($uid, $A, 'default')['items'] === [] && $faces->similar($uid, $B, 'default')['items'] === [], 'A filtered group is never offered to others');
    expectSuggestion(!in_array($A, array_column($faces->subjects($uid, 'default', 0, 60, 'suggested')['items'], 'id'), true), 'Weak pairs do not list a known person as suggested');
    $faces->action($uid, $W, 'dismiss', $A, null, $faces->accountState($uid)['rules_revision']);
    expectSuggestion($faces->similar($uid, $W, 'default')['items'] === [], '"Andere Person" hides the weak pair');
    $faces->publishSuggestions($uid, $acc['epoch'], $acc['generation'], [['a' => $A, 'b' => $W, 'similarity' => 0.7]]);
    expectSuggestion($faces->similar($uid, $W, 'default')['items'] === [], 'The decision survives a new worker result');
    $db->executeStatement('DELETE FROM `*PREFIX*nextgallery_faces_dismissed` WHERE user_id = ?', [$uid]);
    $res = $faces->action($uid, $W, 'merge', $A, null, $faces->accountState($uid)['rules_revision']);
    expectSuggestion(count($res['merged_detection_ids']) === 1, 'Merge reports the moved filtered face');
    expectSuggestion(count_rows($db, 'SELECT COUNT(*) FROM `*PREFIX*nextgallery_faces_detections` WHERE user_id = ? AND group_id = ? AND manual = 1 AND bbox_w < 0.05', [$uid, $A]) === 1, 'Merging marks the filtered face as confirmed (manual)');
    $mainAfter = array_column($faces->subjects($uid, 'default')['items'], 'photo_count', 'id');
    expectSuggestion(($mainAfter[$A] ?? 0) === 3, 'The confirmed face counts for the known person');
    expectSuggestion(!in_array($A, array_column($faces->subjects($uid, 'default', 0, 60, 'filtered')['items'], 'id'), true), 'Nothing of the merge stays filtered');
    $faces->action($uid, $A, 'split', null, $res['merged_detection_ids'], $faces->accountState($uid)['rules_revision']);
    expectSuggestion(($mainAfter = array_column($faces->subjects($uid, 'default')['items'], 'photo_count', 'id'))[$A] === 2, 'Undo moves the face out again');

    // 4. Suggestions, decisions and merge on MariaDB.
    $acc = $faces->accountState($uid);
    try {
        $faces->publishSuggestions($uid, $acc['epoch'], $acc['generation'] + 1, []);
        expectSuggestion(false, 'Stale suggestion generation accepted');
    } catch (FaceConflictException) {
        expectSuggestion(true, 'Stale suggestion generation rejected');
    }
    $stored = $faces->publishSuggestions($uid, $acc['epoch'], $acc['generation'], [
        ['a' => $A, 'b' => $B, 'similarity' => 0.61], ['a' => $A, 'b' => $C, 'similarity' => 0.48],
        ['a' => $A, 'b' => $E, 'similarity' => 0.52], ['a' => $A, 'b' => str_repeat('f', 32), 'similarity' => 0.9],
    ]);
    expectSuggestion($stored === 3, 'Unknown groups are not stored');
    expectSuggestion(array_column($faces->similar($uid, $A, 'default')['items'], 'id') === [$B, $C], 'Similar groups respect gallery scope and order');
    expectSuggestion(array_column($faces->similar($uid, $A, $other)['items'], 'id') === [$E], 'Similar groups of another gallery');
    expectSuggestion(count($faces->subjects($uid, 'default', 0, 60, 'suggested')['items']) === 2, 'Suggested filter lists main groups with open suggestions');
    $faces->action($uid, $A, 'dismiss', $C, null, $faces->accountState($uid)['rules_revision']);
    expectSuggestion(array_column($faces->similar($uid, $A, 'default')['items'], 'id') === [$B], 'Dismissed pair is hidden');
    $faces->action($uid, $C, 'merge', $E, null, $faces->accountState($uid)['rules_revision']);
    expectSuggestion(count_rows($db, 'SELECT COUNT(*) FROM `*PREFIX*nextgallery_faces_dismissed` WHERE user_id = ? AND group_a = ? AND group_b = ?', [$uid, $A, $E]) === 1, 'Decision follows the merged group');
    expectSuggestion(count_rows($db, 'SELECT COUNT(*) FROM `*PREFIX*nextgallery_faces_suggestions` WHERE user_id = ? AND group_a = group_b', [$uid]) === 0, 'Merge leaves no self suggestion');
    $detB = $faces->media($uid, $B, 'default')['items'][0]['face_detection_ids'][0];
    $faces->action($uid, $B, 'merge', $A, null, $faces->accountState($uid)['rules_revision']);
    $faces->action($uid, $A, 'split', $B, [$detB], $faces->accountState($uid)['rules_revision']);
    $faces->publishSuggestions($uid, $acc['epoch'], $acc['generation'], [['a' => $A, 'b' => $B, 'similarity' => 0.9]]);
    expectSuggestion($faces->similar($uid, $A, 'default')['items'] === [], 'A split decision suppresses the pair (MariaDB join)');
    $faces->cleanup($uid);
    expectSuggestion(count_rows($db, 'SELECT COUNT(*) FROM `*PREFIX*nextgallery_faces_suggestions` WHERE user_id = ?', [$uid]) === 1, 'Cleanup keeps suggestions of existing groups');
    $faces->reset($uid);
    expectSuggestion(count_rows($db, 'SELECT COUNT(*) FROM `*PREFIX*nextgallery_faces_suggestions` WHERE user_id = ?', [$uid]) === 0, 'Reset removes suggestions');
    expectSuggestion(count_rows($db, 'SELECT COUNT(*) FROM `*PREFIX*nextgallery_faces_dismissed` WHERE user_id = ?', [$uid]) === 0, 'Reset removes decisions');

    echo "$checks real Nextcloud face reuse/scoped listing/suggestion contracts passed\n";
} finally {
    $faces->purge($uid);
    $appConfig->setAppValue('nextgallery', 'ai_settings', $beforeSettings);
    if ($beforeShare === "\x01") $appConfig->deleteAppValue('nextgallery', 'ai_share_results');
    else $appConfig->setAppValue('nextgallery', 'ai_share_results', $beforeShare);
}
