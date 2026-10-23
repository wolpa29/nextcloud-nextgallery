<?php
declare(strict_types=1);

namespace OCP {
    interface IDBConnection {}
    interface IConfig {
        public function getAppValue(string $app, string $key, $default = null);
        public function setAppValue(string $app, string $key, $value): void;
    }
    interface IUserSession {
        public function getUser();
    }
    interface IUserManager {
        public function userExists(string $uid): bool;
    }
    interface IGroupManager {
        public function isAdmin(string $uid): bool;
    }
    interface IRequest {
        public function getHeader(string $key): string;
    }
}

namespace OCP\Files {
    interface IRootFolder {
        public function getUserFolder(string $uid);
    }
    interface File {
        public function getId(): int;
        public function getEtag(): string;
        public function getMimeType(): string;
        public function getContent(): string;
    }
    interface Folder {}
}

namespace OCP\AppFramework {
    class Controller {
        public function __construct(string $appName, protected \OCP\IRequest $request) {}
    }
    #[\Attribute]
    class PublicPage {}
    #[\Attribute]
    class NoCSRFRequired {}
    #[\Attribute]
    class NoAdminRequired {}
}

namespace OCP\AppFramework\OCS {
    class OCSException extends \Exception {}
    class OCSBadRequestException extends OCSException {}
    class OCSNotFoundException extends OCSException {}
}

namespace OCP\AppFramework\Http {
    class DataResponse {
        public function __construct(public mixed $data, public int $status = 200, private array $headers = []) {}
        public function getData(): mixed { return $this->data; }
        public function getStatus(): int { return $this->status; }
        public function addHeader(string $k, string $v): void { $this->headers[$k] = $v; }
    }
}

namespace OCA\Nextgallery\Service {
    class SearchIndexChanges {
        public array $accounts = [];
        public array $files = [];
        public function account(string $uid): void { $this->accounts[$uid] = true; }
        public function file(string $uid, int $id): void { $this->files[$uid][] = $id; }
    }
}

namespace {
    $libDir = is_file(__DIR__ . '/../lib/AI/Service/Metadata.php') ? __DIR__ . '/../lib' : '/var/www/html/custom_apps/nextgallery/lib';
    require_once $libDir . '/AI/Service/Metadata.php';
    require_once $libDir . '/AI/Service/State.php';
    require_once $libDir . '/AI/Service/Settings.php';
    require_once $libDir . '/AI/Service/Inventory.php';
    require_once $libDir . '/AI/Service/SharedResults.php';
    require_once $libDir . '/Controller/AiWorkerController.php';
    require_once $libDir . '/Controller/AiAdminController.php';

    use OCA\Nextgallery\AI\Service\Metadata;
    use OCA\Nextgallery\AI\Service\State;
    use OCA\Nextgallery\AI\Service\Settings;
    use OCA\Nextgallery\AI\Service\SharedResults;
    use OCA\Nextgallery\Controller\AiWorkerController;
    use OCA\Nextgallery\Controller\AiAdminController;
    use OCP\AppFramework\OCS\OCSBadRequestException;
    use OCP\AppFramework\Http\DataResponse;

    class TestDB implements \OCP\IDBConnection {
        public \PDO $pdo;
        public function __construct() {
            $this->pdo = new \PDO('sqlite::memory:');
            $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $this->pdo->exec('CREATE TABLE nextgallery_ai_meta(id INTEGER PRIMARY KEY,user_id TEXT,file_id INTEGER,etag TEXT,sha256 TEXT,revision TEXT,type TEXT,description TEXT,ocr_text TEXT,tags_text TEXT,objects_text TEXT,metadata_json TEXT,updated_at INTEGER,UNIQUE(user_id,file_id))');
        }
        public function executeStatement($sql, $args = []) {
            $q = $this->pdo->prepare(str_replace('*PREFIX*', '', $sql));
            $q->execute($args);
            return $q->rowCount();
        }
        public function executeQuery($sql, $args = []) {
            $q = $this->pdo->prepare(str_replace('*PREFIX*', '', $sql));
            $q->execute($args);
            return new class($q) {
                public function __construct(private \PDOStatement $q) {}
                public function fetch() { return $this->q->fetch(\PDO::FETCH_ASSOC); }
                public function fetchAll() { return $this->q->fetchAll(\PDO::FETCH_ASSOC); }
                public function fetchOne() { return $this->q->fetchColumn(); }
                public function closeCursor() { $this->q->closeCursor(); }
            };
        }
        public function getDatabaseProvider() { return 'sqlite'; }
    }

    $checks = 0;
    function checkAudio($cond, string $msg): void {
        global $checks;
        $checks++;
        if (!$cond) {
            throw new \RuntimeException("Audio Contract Failure: $msg");
        }
    }

    $tmpDir = sys_get_temp_dir() . '/ng_audio_contract_' . bin2hex(random_bytes(8));
    mkdir($tmpDir, 0777, true);

    try {
        $db = new TestDB();
        $changes = new \OCA\Nextgallery\Service\SearchIndexChanges();
        $meta = new Metadata($db, $changes);

        // 1. Validation tests
        $validAudio = [
            'text' => 'The secret word is pineapple and Vienna.',
            'language' => 'en',
            'segments' => [
                ['start' => 0.0, 'end' => 4.32, 'text' => 'The secret word is pineapple and Vienna.']
            ],
            'duration' => 11.5,
            'model' => 'small',
            'no_audio' => false
        ];

        $res = Metadata::validate(101, 'etag1', str_repeat('a', 64), str_repeat('b', 64), ['audio' => $validAudio], [], 'audio');
        checkAudio($res['file_id'] === 101, 'Validation returns correct file_id');
        $wire = json_decode($res['metadata_json'], true)['analysis'];
        checkAudio($wire['audio']['text'] === $validAudio['text'], 'Validated audio text preserved');
        checkAudio(!empty($wire['completed_stages']['audio']), 'Audio marked in completed_stages');
        checkAudio(!isset($wire['completed_stages']['descriptions']), 'Audio validation did not create fake vision stage');
        checkAudio(!isset($wire['completed_stages']['ocr']), 'Audio validation did not create fake ocr stage');

        // Infinite / NaN duration rejected
        $threw = false;
        try {
            $bad = $validAudio; $bad['duration'] = INF;
            Metadata::validate(101, 'etag1', str_repeat('a', 64), str_repeat('b', 64), ['audio' => $bad], [], 'audio');
        } catch (OCSBadRequestException) { $threw = true; }
        checkAudio($threw, 'Infinite duration must be rejected');

        $threw = false;
        try {
            $bad = $validAudio; $bad['duration'] = -1.0;
            Metadata::validate(101, 'etag1', str_repeat('a', 64), str_repeat('b', 64), ['audio' => $bad], [], 'audio');
        } catch (OCSBadRequestException) { $threw = true; }
        checkAudio($threw, 'Negative duration must be rejected');

        // Segment infinite / NaN rejected
        $threw = false;
        try {
            $bad = $validAudio; $bad['segments'] = [['start' => 0.0, 'end' => INF, 'text' => 'test']];
            Metadata::validate(101, 'etag1', str_repeat('a', 64), str_repeat('b', 64), ['audio' => $bad], [], 'audio');
        } catch (OCSBadRequestException) { $threw = true; }
        checkAudio($threw, 'Infinite segment end must be rejected');

        // Segment start > end rejected
        $threw = false;
        try {
            $bad = $validAudio; $bad['segments'] = [['start' => 5.0, 'end' => 2.0, 'text' => 'test']];
            Metadata::validate(101, 'etag1', str_repeat('a', 64), str_repeat('b', 64), ['audio' => $bad], [], 'audio');
        } catch (OCSBadRequestException) { $threw = true; }
        checkAudio($threw, 'Segment start > end must be rejected');

        // Segment count > 20000 rejected
        $threw = false;
        try {
            $bad = $validAudio;
            $bad['segments'] = array_fill(0, 20001, ['start' => 0.0, 'end' => 1.0, 'text' => 'hi']);
            Metadata::validate(101, 'etag1', str_repeat('a', 64), str_repeat('b', 64), ['audio' => $bad], [], 'audio');
        } catch (OCSBadRequestException) { $threw = true; }
        checkAudio($threw, 'Segment count > 20000 must be rejected');

        // 2. Publication and Metadata Storage tests
        $meta->upsert('alice', $res, 'audio');
        checkAudio($meta->count('alice', 'audio') === 1, 'Audio stage count is 1');
        checkAudio($meta->count('alice', 'descriptions') === 0, 'Audio row has no descriptions');
        checkAudio($meta->count('alice', 'ocr') === 0, 'Audio row has no ocr');

        $stored = $meta->get('alice', 101);
        $stages = Metadata::stages($stored);
        checkAudio(isset($stages['audio']) && !isset($stages['descriptions']) && !isset($stages['ocr']), 'Audio only row has only audio stage');

        // Multi-stage upsert: add description
        $visionData = ['vision' => ['type' => 'video', 'description' => 'A mountain landscape', 'tags' => ['mountain'], 'objects' => [], 'uncertain_details' => []]];
        $visionRes = Metadata::validate(101, 'etag1', str_repeat('a', 64), str_repeat('b', 64), $visionData, [], 'descriptions');
        $meta->upsert('alice', $visionRes, 'descriptions');

        checkAudio($meta->count('alice', 'audio') === 1, 'Audio preserved after description upsert');
        checkAudio($meta->count('alice', 'descriptions') === 1, 'Description counted after upsert');
        $multiStages = Metadata::stages($meta->get('alice', 101));
        checkAudio(isset($multiStages['audio']) && isset($multiStages['descriptions']), 'Row has both audio and descriptions stages');

        // 3. Search awareness tests
        // Search for 'pineapple' which is in audio text
        $searchRes = $meta->search('alice', 'pineapple', 0, 10);
        checkAudio(count($searchRes) === 1, 'Audio text found by Metadata::search');
        checkAudio((int)$searchRes[0]['file_id'] === 101, 'Search returned correct file_id');

        // Search case-insensitivity: 'VIENNA'
        $searchRes2 = $meta->search('alice', 'VIENNA', 0, 10);
        checkAudio(count($searchRes2) === 1, 'Audio text search is case-insensitive');

        checkAudio(count($meta->search('alice','small',0,10))===0,'Model name must not become searchable speech');

        // Description search also still works
        $searchRes3 = $meta->search('alice', 'mountain', 0, 10);
        checkAudio(count($searchRes3) === 1, 'Description search still works');

        // Reset descriptions: audio preserved and searchable
        $meta->reset('alice', 'descriptions');
        checkAudio($meta->count('alice', 'descriptions') === 0, 'Descriptions reset');
        checkAudio($meta->count('alice', 'audio') === 1, 'Audio preserved after descriptions reset');
        checkAudio(count($meta->search('alice', 'pineapple', 0, 10)) === 1, 'Audio text still searchable after description reset');
        checkAudio(count($meta->search('alice', 'mountain', 0, 10)) === 0, 'Description text no longer searchable');

        // Reset audio: audio cleared
        $meta->reset('alice', 'audio');
        checkAudio($meta->count('alice', 'audio') === 0, 'Audio reset');
        checkAudio(count($meta->search('alice', 'pineapple', 0, 10)) === 0, 'Audio text no longer searchable after audio reset');

        // 4. AiWorkerController::reset contract tests
        // Verify audio and faces do NOT throw when reset is called
        $mockConfig = new class implements \OCP\IConfig {
            public function getAppValue(string $a, string $k, $d = null) { return $d; }
            public function setAppValue(string $a, string $k, $v): void {}
        };
        $sharedResults = new class($db, $mockConfig) extends SharedResults {
            public array $forgotten = [];
            public function forget(string $uid, ?string $pipeline = null): void {
                if ($pipeline === 'audio' || $pipeline === 'faces') {
                    throw new \RuntimeException("forget($pipeline) called on SharedResults! Audio and faces are never shared!");
                }
                $this->forgotten[] = [$uid, $pipeline];
            }
        };

        // Create mock state
        $stateMock = new class($tmpDir) extends State {
            public function __construct(private string $dir) {}
            public function directory(): string { return $this->dir; }
            public function rootDirectory(): string { return $this->dir; }
            public function forUser(string $u): static { return $this; }
            public function forPipeline(string $p): static { return $this; }
            public function control(): array { return ['reset_requested' => true, 'paused' => true]; }
        };

        $dummyInventory = new class extends \OCA\Nextgallery\AI\Service\Inventory {
            public function __construct() {}
        };
        $dummySettings = new class extends Settings {
            public function __construct() {}
            public function get(): array { return ['user_id' => 'alice']; }
            public function verifyWorker(?string $token): bool { return true; }
        };

        $workerController = new class(
            new class implements \OCP\IRequest { public function getHeader(string $k): string { return ''; } },
            $dummySettings,
            $dummyInventory,
            $meta,
            $stateMock,
            null,
            $sharedResults,
            null
        ) extends AiWorkerController {
            protected function denied(): ?DataResponse { return null; }
        };

        // Calling reset with kind='audio' must NOT call shared->forget('audio') and must not throw!
        $resetResp = $workerController->reset('audio');
        checkAudio($resetResp->getStatus() === 200, 'Worker reset audio returned HTTP 200');
        checkAudio($resetResp->getData() === ['reset' => true], 'Worker reset audio returned reset: true');

        // Calling reset with kind='faces' must NOT call shared->forget('faces')
        $resetFacesResp = $workerController->reset('faces');
        checkAudio($resetFacesResp->getStatus() === 200, 'Worker reset faces returned HTTP 200');

        // Calling reset with kind='ocr' DOES call shared->forget('ocr')
        $resetOcrResp = $workerController->reset('ocr');
        checkAudio($resetOcrResp->getStatus() === 200, 'Worker reset ocr returned HTTP 200');
        checkAudio($sharedResults->forgotten === [['alice', 'ocr']], 'Worker reset ocr forwarded to shared results');

        // 5. AiAdminController::audioStatus tests
        // Test audio-local/setup.json reading
        $localAudioDir = $tmpDir . '/audio-local';
        mkdir($localAudioDir, 0777, true);

        $stateService = new class($tmpDir) extends State {
            public function __construct(private string $root) {}
            public function rootDirectory(): string { return $this->root; }
        };

        $mockGroupManager = new class implements \OCP\IGroupManager {
            public function isAdmin(string $uid): bool { return true; }
        };
        $mockUserSession = new class implements \OCP\IUserSession {
            public function getUser() {
                return new class { public function getUID(): string { return 'admin'; } };
            }
        };

        $adminController = new class(
            new class implements \OCP\IRequest { public function getHeader(string $k): string { return ''; } },
            $dummySettings,
            $stateService,
            $mockUserSession,
            $mockGroupManager,
            new class implements \OCP\Files\IRootFolder { public function getUserFolder(string $u) {} }
        ) extends AiAdminController {
            protected function denied(): ?DataResponse { return null; }
        };

        // Before any setup files exist:
        $st1 = $adminController->audioStatus()->getData();
        checkAudio($st1['phase'] === 'not_installed', 'Initial audio status phase is not_installed');
        checkAudio($st1['installed'] === false, 'Initial audio status installed is false');
        checkAudio($st1['ready'] === false, 'Initial audio status ready is false');

        // When setup.json has installing:
        file_put_contents($localAudioDir . '/setup.json', json_encode([
            'state' => 'installing',
            'message' => 'Lokale Spracherkennung wird eingerichtet'
        ]));
        $st2 = $adminController->audioStatus()->getData();
        checkAudio($st2['phase'] === 'installing', 'Installing audio status phase is installing');
        checkAudio($st2['state'] === 'installing', 'Installing audio status state is installing');
        checkAudio($st2['installed'] === false, 'Installing audio status installed is false');
        checkAudio($st2['ready'] === false, 'Installing audio status ready is false');
        checkAudio(str_contains($st2['message'], 'eingerichtet'), 'Installing audio status message matches');

        // When ready.json exists:
        file_put_contents($localAudioDir . '/ready.json', json_encode(['identity' => 'abc']));
        file_put_contents($localAudioDir . '/setup.json', json_encode(['state' => 'ready', 'installed' => true]));
        $st3 = $adminController->audioStatus()->getData();
        checkAudio($st3['phase'] === 'ready', 'Ready audio status phase is ready');
        checkAudio($st3['state'] === 'ready', 'Ready audio status state is ready');
        checkAudio($st3['installed'] === true, 'Ready audio status installed is true');
        checkAudio($st3['ready'] === true, 'Ready audio status ready is true');

        // Verify audio-setup.json in root is NOT used when audio-local exists
        file_put_contents($tmpDir . '/audio-setup.json', json_encode(['phase' => 'WRONG_SETUP_JSON']));
        $st4 = $adminController->audioStatus()->getData();
        checkAudio($st4['phase'] === 'ready', 'audio-local/setup.json used instead of audio-setup.json');

        echo "$checks isolated audio pipeline PHP contract assertions passed\n";
    } finally {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($tmpDir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            if ($f->isDir()) rmdir($f->getPathname()); else unlink($f->getPathname());
        }
        rmdir($tmpDir);
    }
}
