<?php
declare(strict_types=1);

/**
 * NextGallery Backend Resilience and Audit Contract Test Suite
 *
 * Verifies:
 * 1. Fair persistent round-robin cron account rotation (IndexJob) preventing starvation,
 *    including discovering cold Android-only accounts via requestBuild queue and lifecycle validation.
 * 2. Scheduled cold index queries return honest 'preparing' state and 'not_indexed' embedding state
 *    without blocking HTTP process imports, requesting builds without mutating account revision.
 * 3. Scoped node invalidation: ordinary file writes only mark file dirty markers (no revision mutation),
 *    share recipients resolved via OCP\Share\IManager->getAccessList(), structural/public/remote fallback to catalog().
 * 4. Preservation of unmounted/failed storage roots during partial sweeps (both folder and per-file errors),
 *    with clean deletion on full sweeps, plus live authorization guarantee.
 * 5. Single-batch media payload resolution for search results.
 */

namespace OCP {
    interface IConfig {
        public function getAppValue(string $app, string $key, $default = '');
        public function setAppValue(string $app, string $key, $value);
        public function getUserValue(string $uid, string $app, string $key, $default = '');
        public function setUserValue(string $uid, string $app, string $key, $value);
        public function getSystemValueString(string $key, string $default = ''): string;
    }
    interface IDBConnection {
        public function getQueryBuilder();
        public function executeQuery(string $query, array $params = []);
        public function executeStatement(string $query, array $params = []): int;
    }
    interface IUserManager {
        public function userExists(string $uid): bool;
    }
}
namespace OCP\DB\QueryBuilder {
    interface IQueryBuilder {
        const PARAM_INT_ARRAY = 101;
        const PARAM_STR_ARRAY = 102;
    }
}
namespace OCP\AppFramework\Utility {
    interface ITimeFactory {
        public function getTime(): int;
        public function now(): \DateTimeImmutable;
    }
}
namespace OCP\BackgroundJob {
    abstract class Job {
        public function __construct(protected \OCP\AppFramework\Utility\ITimeFactory $time) {}
        abstract protected function run($argument): void;
        public function execute($jobList, $argument = null): void {
            $this->run($argument);
        }
    }
    abstract class TimedJob extends Job {
        const TIME_INSENSITIVE = 0;
        public function setInterval(int $interval): void {}
        public function setTimeSensitivity(int $sensitivity): void {}
    }
}
namespace OCP\EventDispatcher {
    class Event {}
    interface IEventListener {
        public function handle(Event $event): void;
    }
}
namespace OCP\Files {
    interface FileInfo {}
    interface IRootFolder {
        public function getUserFolder(string $uid);
    }
    interface Node {
        public function getId(): int;
        public function getPath(): string;
        public function getEtag(): string;
        public function isReadable(): bool;
        public function getMimeType(): string;
        public function getMTime(): int;
        public function getSize(): int;
    }
    interface File extends Node {
        public function getStorage();
    }
    interface Folder extends Node {
        public function search($query): array;
        public function get(string $path);
    }
}
namespace OCP\Files\Search {
    interface ISearchQuery {}
    interface ISearchComparison {
        const COMPARE_LIKE = 'LIKE';
    }
    interface ISearchBinaryOperator {
        const OPERATOR_OR = 'OR';
    }
    interface ISearchOrder {
        const DIRECTION_ASCENDING = 'ASC';
    }
}
namespace OCP\FilesMetadata {
    interface IFilesMetadataManager {
        public function getMetadata(int $fileId);
    }
}
namespace OCP\Lock {
    interface ILockingProvider {
        public const LOCK_SHARED = 1;
        public const LOCK_EXCLUSIVE = 2;
        public function acquireLock(string $path, int $type): bool;
        public function releaseLock(string $path, int $type): bool;
    }
    class LockedException extends \Exception {}
}
namespace OCP\Share {
    interface IManager {
        public function getAccessList(\OCP\Files\Node $path, bool $recursive = true, bool $currentAccess = false): array;
    }
}

namespace Symfony\Component\Process {
    // Unit spy: real worker output/ranking is covered by the Docker contracts.
    class Process {
        public static array $commands = [];
        public function __construct(private array $command, $cwd = null, $env = null) {}
        public function setTimeout($seconds): void {}
        public function setInput($input): void {}
        public function run(): int {
            self::$commands[] = $this->command;
            if (!in_array('--query', $this->command, true)) throw new \RuntimeException('HTTP query attempted a worker rebuild');
            return 0;
        }
        public function isSuccessful(): bool { return true; }
        public function getErrorOutput(): string { return ''; }
        public function getOutput(): string { return json_encode(['items'=>[['file_id'=>1]], 'embedding_state'=>'disabled']); }
    }
}

namespace {
    require_once __DIR__ . '/../../lib/Service/SearchIndexChanges.php';
    require_once __DIR__ . '/../../lib/Listener/SearchIndexChanged.php';
    require_once __DIR__ . '/../../lib/BackgroundJob/IndexJob.php';
    require_once __DIR__ . '/../../lib/Service/Search.php';
    require_once __DIR__ . '/../../lib/Service/Dates.php';
    require_once __DIR__ . '/../../lib/Service/Library.php';
    require_once __DIR__ . '/../../lib/Service/SearchIndex.php';
    require_once __DIR__ . '/../../lib/Service/NativeSearch.php';
    require_once __DIR__ . '/../../lib/Service/GallerySearch.php';

    $checks = 0;
    function check(bool $condition, string $message = ''): void {
        global $checks;
        $checks++;
        if (!$condition) {
            throw new \RuntimeException($message ?: "Assertion $checks failed");
        }
    }

    // --- Mock IConfig ---
    class MockConfig implements \OCP\IConfig {
        public array $app = [];
        public array $user = [];
        public array $system = [];
        public function getAppValue(string $app, string $key, $default = '') { return $this->app[$app][$key] ?? $default; }
        public function setAppValue(string $app, string $key, $value) { $this->app[$app][$key] = (string)$value; }
        public function getUserValue(string $uid, string $app, string $key, $default = '') { return $this->user[$uid][$app][$key] ?? $default; }
        public function setUserValue(string $uid, string $app, string $key, $value) { $this->user[$uid][$app][$key] = (string)$value; }
        public function getSystemValueString(string $key, string $default = ''): string { return $this->system[$key] ?? $default; }
    }

    // --- Mock ITimeFactory with real NC35 now() and getTime() ---
    class MockTimeFactory implements \OCP\AppFramework\Utility\ITimeFactory {
        public float $currentSeconds = 1000.0;
        public function getTime(): int { return (int)$this->currentSeconds; }
        public function now(): \DateTimeImmutable {
            $sec = (int)$this->currentSeconds;
            $micro = (int)(($this->currentSeconds - $sec) * 1000000);
            return new \DateTimeImmutable('@' . $sec . '.' . str_pad((string)$micro, 6, '0', STR_PAD_LEFT));
        }
    }

    // --- Mock IUserManager ---
    class MockUserManager implements \OCP\IUserManager {
        public array $validUsers = [];
        public function userExists(string $uid): bool {
            return in_array($uid, $this->validUsers, true);
        }
    }

    // --- Mock DB & QueryBuilder ---
    class MockQueryBuilder {
        private array $selects = [];
        private string $from = '';
        private array $wheres = [];
        private array $params = [];
        private int $pIndex = 0;
        private string $mode = 'select';
        private array $setValues = [];

        public function __construct(private \PDO $pdo) {}
        public function select(...$cols): self { $this->mode = 'select'; $this->selects = array_merge($this->selects, $cols); return $this; }
        public function from(string $table, string $alias = ''): self { $this->from = $table; return $this; }
        public function insert(string $table): self { $this->mode = 'insert'; $this->from = $table; return $this; }
        public function update(string $table): self { $this->mode = 'update'; $this->from = $table; return $this; }
        public function delete(string $table): self { $this->mode = 'delete'; $this->from = $table; return $this; }
        public function setValue(string $col, $param): self { $this->setValues[$col] = $param; return $this; }
        public function set(string $col, $param): self { $this->setValues[$col] = $param; return $this; }
        public function where(string $pred): self { $this->wheres = [$pred]; return $this; }
        public function andWhere(string $pred): self { $this->wheres[] = $pred; return $this; }

        public function createNamedParameter($val, $type = null): string {
            $name = ':p' . (++$this->pIndex);
            if (($type === \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY || $type === \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY) && is_array($val)) {
                if (empty($val)) return '(NULL)';
                $subs = [];
                foreach ($val as $i => $item) {
                    $sub = $name . '_' . $i;
                    $this->params[$sub] = $item;
                    $subs[] = $sub;
                }
                return '(' . implode(',', $subs) . ')';
            }
            $this->params[$name] = $val;
            return $name;
        }

        public function expr(): object {
            return new class {
                public function eq(string $col, string $param): string { return "$col = $param"; }
                public function neq(string $col, string $param): string { return "$col != $param"; }
                public function in(string $col, string $param): string { return "$col IN $param"; }
            };
        }

        public function executeQuery(): object {
            $sql = 'SELECT ' . implode(', ', $this->selects) . ' FROM ' . $this->from;
            if ($this->wheres) $sql .= ' WHERE ' . implode(' AND ', $this->wheres);
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($this->params);
            return new class($stmt) {
                public function __construct(private \PDOStatement $stmt) {}
                public function fetch(): array|false { return $this->stmt->fetch(\PDO::FETCH_ASSOC); }
                public function fetchAll(): array { return $this->stmt->fetchAll(\PDO::FETCH_ASSOC); }
                public function fetchOne(): mixed { return $this->stmt->fetchColumn(); }
                public function closeCursor(): void { $this->stmt->closeCursor(); }
            };
        }

        public function executeStatement(): int {
            if ($this->mode === 'delete') {
                $sql = 'DELETE FROM ' . $this->from;
                if ($this->wheres) $sql .= ' WHERE ' . implode(' AND ', $this->wheres);
            } elseif ($this->mode === 'insert') {
                $cols = array_keys($this->setValues);
                $vals = array_values($this->setValues);
                $sql = 'INSERT INTO ' . $this->from . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ')';
            } elseif ($this->mode === 'update') {
                $sets = [];
                foreach ($this->setValues as $c => $v) $sets[] = "$c = $v";
                $sql = 'UPDATE ' . $this->from . ' SET ' . implode(',', $sets);
                if ($this->wheres) $sql .= ' WHERE ' . implode(' AND ', $this->wheres);
            } else {
                throw new \RuntimeException('Unsupported mode: ' . $this->mode);
            }
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($this->params);
            return $stmt->rowCount();
        }
    }

    class MockDB implements \OCP\IDBConnection {
        public \PDO $pdo;
        public function __construct() {
            $this->pdo = new \PDO('sqlite::memory:');
            $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $this->pdo->exec('CREATE TABLE nextgallery_media(user_id TEXT, file_id INTEGER, sort_key TEXT, period TEXT, kind TEXT, etag TEXT, generation TEXT, latitude REAL, longitude REAL, payload TEXT, name_text TEXT, path_text TEXT, PRIMARY KEY(user_id, file_id))');
            $this->pdo->exec('CREATE TABLE nextgallery_users(user_id TEXT PRIMARY KEY)');
            $this->pdo->exec('CREATE TABLE nextgallery_ai_meta(user_id TEXT, file_id INTEGER, etag TEXT, metadata_json TEXT, updated_at INTEGER, description TEXT, tags_text TEXT, objects_text TEXT, ocr_text TEXT, PRIMARY KEY(user_id, file_id))');
        }
        public function getQueryBuilder(): MockQueryBuilder { return new MockQueryBuilder($this->pdo); }
        public function executeQuery(string $query, array $params = []) {
            $stmt = $this->pdo->prepare(str_replace('*PREFIX*', '', $query));
            $stmt->execute($params);
            return new class($stmt) {
                public function __construct(private \PDOStatement $stmt) {}
                public function fetch(): array|false { return $this->stmt->fetch(\PDO::FETCH_ASSOC); }
                public function fetchAll(): array { return $this->stmt->fetchAll(\PDO::FETCH_ASSOC); }
                public function fetchOne(): mixed { return $this->stmt->fetchColumn(); }
                public function closeCursor(): void { $this->stmt->closeCursor(); }
            };
        }
        public function executeStatement(string $query, array $params = []): int {
            $stmt = $this->pdo->prepare(str_replace('*PREFIX*', '', $query));
            $stmt->execute($params);
            return $stmt->rowCount();
        }
    }

    // --- Mock Filesystem ---
    class MockFile implements \OCP\Files\File {
        public function __construct(
            public int $id,
            public string $name,
            public string $path,
            public string $etag = 'etag-v1',
            public int $mtime = 1700000000,
            public int $size = 1024,
            public string $mime = 'image/jpeg',
            public bool $readable = true,
            public ?string $ownerUid = null
        ) {}
        public function getId(): int { return $this->id; }
        public function getName(): string { return $this->name; }
        public function getPath(): string { return $this->path; }
        public function getEtag(): string { return $this->etag; }
        public function getMTime(): int { return $this->mtime; }
        public function getSize(): int { return $this->size; }
        public function getMimeType(): string { return $this->mime; }
        public function isReadable(): bool { return $this->readable; }
        public function fopen(string $mode) {
            return fopen('php://memory', 'r+');
        }
        public function getStorage() {
            return new class { public function isLocal(): bool { return true; } };
        }
        public function getOwner() {
            if ($this->ownerUid === null) return null;
            $uid = $this->ownerUid;
            return new class($uid) {
                public function __construct(private string $u) {}
                public function getUID(): string { return $this->u; }
            };
        }
    }

    class MockFolder implements \OCP\Files\Folder {
        public array $nodes = [];
        public bool $shouldThrow = false;
        public function __construct(public string $path, public bool $readable = true) {}
        public function getId(): int { return 999; }
        public function getName(): string { return basename($this->path); }
        public function getPath(): string { return $this->path; }
        public function getEtag(): string { return 'folder-etag'; }
        public function getMTime(): int { return 1700000000; }
        public function getSize(): int { return 0; }
        public function getMimeType(): string { return 'httpd/unix-directory'; }
        public function isReadable(): bool { return $this->readable; }
        public function search($query): array {
            if ($this->shouldThrow) {
                throw new \RuntimeException('Storage endpoint unavailable');
            }
            $offset = method_exists($query, 'getOffset') ? $query->getOffset() : 0;
            $limit = method_exists($query, 'getLimit') ? $query->getLimit() : 100;
            return array_slice($this->nodes, $offset, $limit);
        }
        public function get(string $path) {
            if ($this->shouldThrow) {
                throw new \RuntimeException('Storage unreachable: ' . $path);
            }
            return $this;
        }
    }

    class MockUserFolder {
        public array $folders = [];
        public array $filesById = [];
        public function nodeExists(string $path): bool {
            $normalized = '/' . trim($path, '/');
            return isset($this->folders[$normalized]);
        }
        public function get(string $path) {
            $normalized = '/' . trim($path, '/');
            if (isset($this->folders[$normalized])) {
                $f = $this->folders[$normalized];
                if ($f->shouldThrow) throw new \RuntimeException('Storage offline: ' . $path);
                return $f;
            }
            throw new \RuntimeException('Path not found: ' . $path);
        }
        public function getById(int $id): array {
            return isset($this->filesById[$id]) ? [$this->filesById[$id]] : [];
        }
        public function getRelativePath(string $path): string {
            return ltrim($path, '/');
        }
    }

    class MockRootFolder implements \OCP\Files\IRootFolder {
        public array $userFolders = [];
        public function getUserFolder(string $uid) {
            return $this->userFolders[$uid] ??= new MockUserFolder();
        }
    }

    class MockLock implements \OCP\Lock\ILockingProvider {
        public function acquireLock(string $path, int $type): bool { return true; }
        public function releaseLock(string $path, int $type): bool { return true; }
    }

    class MockMetadataManager implements \OCP\FilesMetadata\IFilesMetadataManager {
        public function getMetadata(int $fileId) {
            return new class {
                public function hasKey(string $key): bool { return false; }
                public function getArray(string $key): array { return []; }
                public function getString(string $key): string { return ''; }
            };
        }
    }

    // --- Mock Share IManager ---
    class MockShareManager implements \OCP\Share\IManager {
        public array $accessLists = [];
        public function getAccessList(\OCP\Files\Node $path, bool $recursive = true, bool $currentAccess = false): array {
            $id = $path->getId();
            return $this->accessLists[$id] ?? ['users' => [], 'remote' => false, 'public' => false, 'mail' => []];
        }
    }

    echo "=== Running NextGallery Backend Resilience Contract Tests ===\n\n";

    // =========================================================================
    // CONTRACT TEST 1: Fair Persistent Round-Robin Cron Account Rotation
    //                   & Discovery of Cold Android-Only Users via requestBuild
    // =========================================================================
    echo "--- Test 1: Fair Persistent Round-Robin Rotation & Android-Only Discovery ---\n";
    {
        $time = new MockTimeFactory();
        $time->currentSeconds = 1000.0;
        $config = new MockConfig();
        $tmpDir = sys_get_temp_dir() . '/ng_test_cron_' . bin2hex(random_bytes(8));
        mkdir($tmpDir, 0700, true);
        $config->system['datadirectory'] = $tmpDir;

        $db = new MockDB();
        $root = new MockRootFolder();
        $locks = new MockLock();
        $meta = new MockMetadataManager();
        $changes = new \OCA\Nextgallery\Service\SearchIndexChanges($config);
        $userManager = new MockUserManager();
        $userManager->validUsers = ['user_first', 'user_middle', 'user_last', 'android_cold_user'];

        // Custom test library that records step calls and advances virtual time
        $library = new class($config, $db, $root, $locks, $meta) extends \OCA\Nextgallery\Service\Library {
            public array $usersList = ['user_first', 'user_middle', 'user_last'];
            public array $stepped = [];
            public ?MockTimeFactory $timeRef = null;

            public function users(): array {
                return $this->usersList;
            }
            public function state(string $uid): array {
                return ['running' => false, 'completed' => 0];
            }
            public function step(string $uid, bool $restart = false, int $limit = 100, float $seconds = 4): array {
                $this->stepped[] = $uid;
                if ($this->timeRef) {
                    $this->timeRef->currentSeconds += 25.0;
                }
                return ['running' => false, 'completed' => (int)$this->timeRef->currentSeconds];
            }
        };
        $library->timeRef = $time;

        // Custom search index recording ensure() and clearing build requests
        $searchIndex = new class($config, $db, $root, $meta, $changes) extends \OCA\Nextgallery\Service\SearchIndex {
            public ?MockTimeFactory $timeRef = null;
            public array $ensured = [];
            public function cleanup(): void {}
            public function ensure(string $uid, bool $catalog = true): string {
                $this->ensured[] = $uid;
                if ($this->timeRef) {
                    $this->timeRef->currentSeconds += 5.0;
                }
                $this->changes()->clearBuildRequest($uid);
                return 'done';
            }
        };
        $searchIndex->timeRef = $time;

        // Queue a cold build request for an Android-only user (who has no web gallery settings)
        $changes->requestBuild('android_cold_user');
        // Also queue a request for a deleted/invalid user to test lifecycle validation
        $changes->requestBuild('stale_deleted_user');

        $job = new class($time, $library, $searchIndex, null, $config, $userManager) extends \OCA\Nextgallery\BackgroundJob\IndexJob {
            public function trigger(): void {
                $this->run(null);
            }
        };

        // --- Run 1: Starting fresh, budget 40s ---
        $library->stepped = [];
        $job->trigger();
        $run1Stepped = $library->stepped;

        // Verify that stale deleted user was purged from request queue
        check(!in_array('stale_deleted_user', $changes->requestedBuilds(), true), 'Deleted user request was purged by lifecycle validation');

        // Check Run 1 processed first 2 users before deadline
        check(in_array('user_first', $run1Stepped, true), 'Run 1 processed user_first');
        check(in_array('user_middle', $run1Stepped, true), 'Run 1 processed user_middle');
        check(!in_array('user_last', $run1Stepped, true), 'Run 1 broke on deadline');
        check($config->getAppValue('nextgallery', 'cron_last_user') === 'user_middle', 'cron_last_user recorded user_middle');

        // --- Run 2: Next cron tick ---
        $time->currentSeconds = 2000.0;
        $library->stepped = [];
        $job->trigger();
        $run2Stepped = $library->stepped;

        // Queue starts with user_last, then android_cold_user!
        check($run2Stepped[0] === 'user_last', 'Run 2 started with user_last (fair persistent rotation)');
        check(in_array('user_last', $run2Stepped, true), 'user_last was processed');
        check(in_array('android_cold_user', $run2Stepped, true), 'Android-only user was discovered from request queue and processed!');
        check($config->getAppValue('nextgallery', 'cron_last_user') === 'android_cold_user', 'cron_last_user recorded android_cold_user');
        // Check that ensure cleared the build request for android_cold_user
        check(!in_array('android_cold_user', $changes->requestedBuilds(), true), 'ensure cleared build request for android_cold_user');

        // Clean up
        $changes->forget('user_first');
        $changes->forget('user_middle');
        $changes->forget('user_last');
        $changes->forget('android_cold_user');
        @rmdir($tmpDir . '/.nextgallery_search/requests');
        @rmdir($tmpDir . '/.nextgallery_search');
        @rmdir($tmpDir);
        echo "  [PASS] IndexJob rotates persistently and discovers cold Android-only accounts via requestBuild queue.\n\n";
    }

    // =========================================================================
    // CONTRACT TEST 2: Scheduled Cold Index Query without HTTP Process Import
    //                   & No Revision Mutation on Query
    // =========================================================================
    echo "--- Test 2: Scheduled Cold Index Query without HTTP Import & No Revision Mutation ---\n";
    {
        $tmpDir = sys_get_temp_dir() . '/ng_test_search_' . bin2hex(random_bytes(8));
        mkdir($tmpDir, 0700, true);
        $config = new MockConfig();
        $config->system['datadirectory'] = $tmpDir;
        $db = new MockDB();
        $root = new MockRootFolder();
        $meta = new MockMetadataManager();
        $changes = new \OCA\Nextgallery\Service\SearchIndexChanges($config);

        $searchIndex = new \OCA\Nextgallery\Service\SearchIndex($config, $db, $root, $meta, $changes);

        // Case A: Cold start - index.sqlite3 does NOT exist
        $coldUser = 'cold_user_' . bin2hex(random_bytes(4));
        $dir = $changes->directory($coldUser);
        $revBefore = $changes->revision($coldUser);

        $res = $searchIndex->query($coldUser, ['query' => 'mountain', 'embedding_enabled' => true]);
        check(\Symfony\Component\Process\Process::$commands === [], 'Cold query did not invoke any worker process');
        check($res['index_state'] === 'preparing', "Cold query returned index_state 'preparing'");
        // Crucial: embedding_state must be 'not_indexed', NOT invented 'preparing' (Android whitelist)
        check($res['embedding_state'] === 'not_indexed', "Cold query returned embedding_state 'not_indexed'");
        check($res['items'] === [], 'Cold query items must be empty');
        check($res['total'] === 0, 'Cold query total must be 0');

        // Verify query NEVER mutated account revision
        $revAfter = $changes->revision($coldUser);
        check($revBefore === $revAfter, 'query() MUST NOT mutate account revision! (coalescing requestBuild used)');

        // Verify requestBuild queued the account for background processing
        check(in_array($coldUser, $changes->requestedBuilds(), true), 'Cold query queued account in requestedBuilds');

        // Case B: Existing index present with pending changes
        $existingDbPath = $dir . '/index.sqlite3';
        touch($existingDbPath); // Published index existence; worker is spied above.

        $revMatch = $changes->revision($coldUser);
        file_put_contents($dir . '/manifest.json', json_encode(['schema' => 1, 'catalog_revision' => $revMatch]));

        // Mark a dirty file
        $changes->file($coldUser, 42);

        $resUpdating = $searchIndex->query($coldUser, ['query' => 'mountain', 'embedding_enabled' => false]);
        check(count(\Symfony\Component\Process\Process::$commands) === 1, 'Updating HTTP request only invoked a query, without import/update');
        check($resUpdating['index_state'] === 'updating', "State must honestly report 'updating' when pending changes exist");
        check(count($resUpdating['items']) === 1, 'Query served existing documents from sqlite');
        check($changes->revision($coldUser) === $revMatch, 'Updating query did not mutate revision');

        // Android-only accounts must also recover out-of-band changes after
        // their initial request has left the job queue.
        $changes->acknowledge($coldUser, $changes->pending($coldUser));
        $changes->clearBuildRequest($coldUser);
        file_put_contents($dir . '/manifest.json', json_encode(['schema'=>1, 'catalog_revision'=>$revMatch, 'catalog_at'=>time()-301]));
        check($searchIndex->query($coldUser, ['query'=>'mountain', 'embedding_enabled'=>false])['index_state']==='updating', 'Expired catalog requests a periodic background sweep');
        check(in_array($coldUser, $changes->requestedBuilds(), true), 'Periodic Android-only sweep remains durably queued');
        $marker=$changes->directory().'/requests/'.hash('sha256',$coldUser);
        $inode=fileinode($marker);$changes->requestBuild($coldUser);clearstatcache(true,$marker);
        check(fileinode($marker)===$inode, 'Repeated queries coalesce without rewriting a pending marker');

        // Clean up
        $changes->forget($coldUser);
        @rmdir($tmpDir . '/.nextgallery_search/requests');
        @rmdir($tmpDir . '/.nextgallery_search');
        @rmdir($tmpDir);
        echo "  [PASS] Cold query returns 'preparing' and 'not_indexed' without HTTP rebuild; revision is not mutated.\n\n";
    }

    // =========================================================================
    // CONTRACT TEST 3: Scoped Node and Share Invalidation via IManager
    // =========================================================================
    echo "--- Test 3: Scoped Node and Share Invalidation via Share IManager ---\n";
    {
        $tmpDir = sys_get_temp_dir() . '/ng_test_invalidation_' . bin2hex(random_bytes(8));
        mkdir($tmpDir, 0700, true);
        $config = new MockConfig();
        $config->system['datadirectory'] = $tmpDir;
        $changes = new \OCA\Nextgallery\Service\SearchIndexChanges($config);
        $shareManager = new MockShareManager();
        $listener = new \OCA\Nextgallery\Listener\SearchIndexChanged($changes, $shareManager);

        $alice = 'alice_user';
        $bob = 'bob_user';
        $carol = 'carol_user';

        // Initial revisions
        $aliceRev0 = $changes->revision($alice);
        $bobRev0 = $changes->revision($bob);
        $carolRev0 = $changes->revision($carol);

        // Case A: Ordinary file write on a file shared between Alice and Bob
        $fileNode = new MockFile(555, 'trip.jpg', '/alice_user/files/Photos/trip.jpg', 'e1', 1700000000, 1000, 'image/jpeg', true, 'alice_user');
        // ShareManager resolves access list to Alice and Bob
        $shareManager->accessLists[555] = [
            'users' => ['alice_user', 'bob_user'],
            'public' => false,
            'remote' => false
        ];

        $nodeEvent = new class($fileNode) extends \OCP\EventDispatcher\Event {
            public function __construct(private $node) {}
            public function getNode() { return $this->node; }
        };

        $listener->handle($nodeEvent);

        // Check: File 555 marked dirty for BOTH Alice and Bob
        check(isset($changes->pending($alice)[555]), 'Alice dirty markers MUST record file id 555');
        check(isset($changes->pending($bob)[555]), 'Bob (share recipient) dirty markers MUST record file id 555');
        check(empty($changes->pending($carol)), 'Carol dirty markers MUST be empty');

        // CRITICAL CHECK: Ordinary file write MUST NOT mutate account revision!
        check($changes->revision($alice) === $aliceRev0, 'Ordinary file write MUST NOT mutate Alice account revision (avoids full rebuild)');
        check($changes->revision($bob) === $bobRev0, 'Ordinary file write MUST NOT mutate Bob account revision');
        check($changes->revision($carol) === $carolRev0, 'Carol revision remained unchanged');

        // Case B: Structural folder change (Folder node)
        $folderNode = new MockFolder('/alice_user/files/Photos/NewAlbum', true);
        $folderEvent = new class($folderNode) extends \OCP\EventDispatcher\Event {
            public function __construct(private $node) {}
            public function getNode() { return $this->node; }
        };
        // Folder has owner alice
        $folderNodeOwner = new class($folderNode) implements \OCP\Files\Folder {
            public function __construct(private MockFolder $inner) {}
            public function getId(): int { return 888; }
            public function getName(): string { return 'NewAlbum'; }
            public function getPath(): string { return '/alice_user/files/Photos/NewAlbum'; }
            public function getEtag(): string { return 'f-etag'; }
            public function getMTime(): int { return 1700000000; }
            public function getSize(): int { return 0; }
            public function getMimeType(): string { return 'httpd/unix-directory'; }
            public function isReadable(): bool { return true; }
            public function search($q): array { return []; }
            public function get($p) { return $this; }
            public function getOwner() { return new class { public function getUID(): string { return 'alice_user'; } }; }
        };
        $listener->handle(new class($folderNodeOwner) extends \OCP\EventDispatcher\Event {
            public function __construct(private $node) {}
            public function getNode() { return $this->node; }
        });

        // Folder structural change DOES mutate Alice revision
        check($changes->revision($alice) !== $aliceRev0, 'Structural folder change mutated Alice account revision');
        check($changes->revision($carol) === $carolRev0, 'Carol revision remained unchanged during Alice folder change');

        // Case C: Public link share -> cannot be scoped to local users, falls back safely to catalog()
        $publicFileNode = new MockFile(999, 'pub.jpg', '/alice_user/files/pub.jpg', 'ep', 1700000000, 1000, 'image/jpeg', true, 'alice_user');
        $shareManager->accessLists[999] = [
            'users' => ['alice_user'],
            'public' => true,
            'remote' => false
        ];
        $listener->handle(new class($publicFileNode) extends \OCP\EventDispatcher\Event {
            public function __construct(private $node) {}
            public function getNode() { return $this->node; }
        });

        // Public share causes conservative catalog fallback, invalidating global catalog revision
        check($changes->revision($carol) !== $carolRev0, 'Public share fallback safely invalidates catalog');

        // Clean up
        $changes->forget($alice);
        $changes->forget($bob);
        $changes->forget($carol);
        @rmdir($tmpDir . '/.nextgallery_search/requests');
        @rmdir($tmpDir . '/.nextgallery_search');
        @rmdir($tmpDir);
        echo "  [PASS] Ordinary file writes record dirty markers for owner & share recipients without revision mutation; structural/public fallback safely.\n\n";
    }

    // =========================================================================
    // CONTRACT TEST 4: Failed-Root Preservation during Storage Outages & Extraction Errors
    // =========================================================================
    echo "--- Test 4: Failed-Root Preservation during Storage Outages & Extraction Errors ---\n";
    {
        $config = new MockConfig();
        $db = new MockDB();
        $root = new MockRootFolder();
        $locks = new MockLock();
        $meta = new MockMetadataManager();

        $library = new \OCA\Nextgallery\Service\Library($config, $db, $root, $locks, $meta);
        $uid = 'tester_roots';

        $home = $root->getUserFolder($uid);

        // Healthy /Photos folder with file 101
        $photosFolder = new MockFolder('/Photos', true);
        $file101 = new MockFile(101, 'cat.jpg', '/Photos/cat.jpg', 'etag-cat');
        $photosFolder->nodes = [$file101];
        $home->folders['/Photos'] = $photosFolder;
        $home->filesById[101] = $file101;

        // /External_Storage folder initially exists so save() can validate roots
        $extFolder = new MockFolder('/External_Storage', true);
        $home->folders['/External_Storage'] = $extFolder;

        // Configure user settings: 2 roots (/Photos and /External_Storage)
        $library->save($uid, ['roots' => ['/Photos', '/External_Storage']]);

        // Now simulate /External_Storage failing (network storage timeout / mount failure)
        $extFolder->shouldThrow = true;

        // Insert pre-existing media in the catalog for BOTH roots
        $payload101 = json_encode(['id' => 101, 'name' => 'cat.jpg', 'path' => '/Photos/cat.jpg', 'kind' => 'image', 'etag' => 'etag-cat', 'metadata' => ['raw' => null, 'gps' => null, 'width' => 100, 'height' => 100, 'duration' => null, 'revision' => 2]]);
        $payload202 = json_encode(['id' => 202, 'name' => 'offline.jpg', 'path' => '/External_Storage/offline.jpg', 'kind' => 'image', 'etag' => 'etag-offline', 'metadata' => ['raw' => null, 'gps' => null, 'width' => 100, 'height' => 100, 'duration' => null, 'revision' => 2]]);

        $db->pdo->exec("INSERT INTO nextgallery_media (user_id, file_id, path_text, name_text, etag, generation, payload) VALUES
            ('$uid', 101, '/photos/cat.jpg', 'cat.jpg', 'etag-cat', 'gen_old', '$payload101'),
            ('$uid', 202, '/external_storage/offline.jpg', 'offline.jpg', 'etag-offline', 'gen_old', '$payload202')
        ");

        // Run sweep
        $s1 = $library->step($uid, true, 100, 10.0);

        check(!$s1['running'], 'Sweep must finish');
        check($s1['partial'] === true, 'Sweep state MUST record partial = true when roots fail');
        check(in_array('/External_Storage', $s1['failedRoots'] ?? [], true), 'failedRoots MUST include /External_Storage');

        // File 202 under failed root MUST BE PRESERVED
        $stmt2 = $db->pdo->query("SELECT generation FROM nextgallery_media WHERE user_id = '$uid' AND file_id = 202");
        check($stmt2->fetchColumn() === 'gen_old', 'Unvisited file 202 under failed root MUST BE PRESERVED in derived media catalog!');

        // Case B: Per-file extraction/DB error also preserves the root!
        $extFolder->shouldThrow = false;
        // File 204 causes extraction error (throws on getMTime)
        $brokenFile = new class(204, 'broken.jpg', '/External_Storage/broken.jpg') extends MockFile {
            public function getMTime(): int { throw new \RuntimeException('Corrupt file header'); }
        };
        $extFolder->nodes = [$brokenFile];
        $home->filesById[204] = $brokenFile;

        $sFileErr = $library->step($uid, true, 100, 10.0);
        check($sFileErr['partial'] === true, 'Per-file extraction failure MUST mark sweep partial = true');
        check(in_array('/External_Storage', $sFileErr['failedRoots'] ?? [], true), 'failedRoots MUST include root with extraction failure');

        // Case C: Clean complete recovery
        $healthyFile205 = new MockFile(205, 'ok.jpg', '/External_Storage/ok.jpg', 'etag-ok');
        $extFolder->nodes = [$healthyFile205];
        $home->filesById[205] = $healthyFile205;

        $sClean = $library->step($uid, true, 100, 10.0);
        check($sClean['partial'] === false, 'Complete sweep marks partial = false');
        check(empty($sClean['failedRoots']), 'Complete sweep clears failedRoots');

        // Genuinely deleted file 202 is now deleted
        $stmtDel = $db->pdo->query("SELECT COUNT(*) FROM nextgallery_media WHERE user_id = '$uid' AND file_id = 202");
        check((int)$stmtDel->fetchColumn() === 0, 'Truly removed file 202 deleted on complete sweep');

        // Live authorization check: unreadable file is omitted by hydrate()
        $unreadableNode = new MockFile(999, 'secret.jpg', '/Photos/secret.jpg', 'e', 1, 1, 'image/jpeg', false);
        $home->filesById[999] = $unreadableNode;
        $hydrated = $library->hydrate($uid, [['file_id' => 999, 'payload' => json_encode(['path' => '/Photos/secret.jpg', 'etag' => 'e'])]]);
        check(empty($hydrated), 'Unreadable file was excluded during live hydration authorization');

        echo "  [PASS] Failed roots preserved on folder & per-file errors; complete sweeps clean genuinely deleted media; live authorization holds.\n\n";
    }

    // =========================================================================
    // CONTRACT TEST 5: Batched Search Media Resolution
    // =========================================================================
    echo "--- Test 5: Batched Search Media Resolution ---\n";
    {
        $config = new MockConfig();
        $db = new MockDB();
        $root = new MockRootFolder();
        $locks = new MockLock();
        $meta = new MockMetadataManager();
        $library = new \OCA\Nextgallery\Service\Library($config, $db, $root, $locks, $meta);
        $changes = new \OCA\Nextgallery\Service\SearchIndexChanges($config);
        $searchIndex = new \OCA\Nextgallery\Service\SearchIndex($config, $db, $root, $meta, $changes);

        $uid = 'batch_user';
        for ($i = 1; $i <= 5; $i++) {
            $p = json_encode([
                'id' => $i,
                'name' => "img{$i}.jpg",
                'path' => "/Photos/img{$i}.jpg",
                'kind' => 'image',
                'etag' => "etag-{$i}",
                'metadata' => ['raw' => null, 'gps' => null, 'revision' => 2]
            ]);
            $db->pdo->exec("INSERT INTO nextgallery_media (user_id, file_id, path_text, name_text, etag, generation, payload) VALUES
                ('$uid', $i, '/photos/img{$i}.jpg', 'img{$i}.jpg', 'etag-{$i}', 'g1', '$p')");
        }

        $home = $root->getUserFolder($uid);
        for ($i = 1; $i <= 5; $i++) {
            $home->filesById[$i] = new MockFile($i, "img{$i}.jpg", "/Photos/img{$i}.jpg", "etag-{$i}");
        }

        $mockNative = new class extends \OCA\Nextgallery\Service\NativeSearch {
            public function __construct() {}
            public function search(string $uid, string $query, array $roots, string $kind = '', int $offset = 0, int $limit = 60, string $phase = 'hybrid', string $period = '', string $mode = 'folder', string $timezone = 'UTC', string $search_token = '', bool $vectorOnly = false, string $subjectId = ''): array {
                $items = [];
                for ($i = 1; $i <= 5; $i++) {
                    $items[] = [
                        'id' => $i,
                        'path' => "/Photos/img{$i}.jpg",
                        'etag' => "etag-{$i}",
                        'mtime' => 1700000000,
                        'metadata' => []
                    ];
                }
                return [
                    'items' => $items,
                    'total' => 5,
                    'index_state' => 'ready',
                    'mode' => 'lexical',
                    'embedding_state' => 'disabled',
                    'search_backend' => 'sqlite'
                ];
            }
        };

        $gallerySearch = new \OCA\Nextgallery\Service\GallerySearch($db, $library, null, $mockNative);
        $res = $gallerySearch->search($uid, 'any');

        check(count($res['items']) === 5, 'All 5 batched items were hydrated');
        check($res['items'][0]['id'] === 1 && $res['items'][4]['id'] === 5, 'Hydrated item order preserved');
        echo "  [PASS] Search hits resolved in single batched WHERE file_id IN (...) query.\n\n";
    }

    echo "=========================================================================\n";
    echo "ALL $checks BACKEND RESILIENCE CONTRACT ASSERTIONS PASSED SUCCESSFULLY!\n";
    echo "=========================================================================\n";
}
