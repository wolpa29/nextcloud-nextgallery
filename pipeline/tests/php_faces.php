<?php
declare(strict_types=1);
namespace OCP {
    interface IDBConnection {
        public function getQueryBuilder();
        public function executeStatement(string $query, array $params = []): int;
        public function executeQuery(string $query, array $params = []);
        public function beginTransaction(): void;
        public function commit(): void;
        public function rollBack(): void;
    }
    interface IConfig {
        public function getAppValue(string $app, string $key, $default = '');
        public function setAppValue(string $app, string $key, $value);
        public function getUserValue(string $userId, string $app, string $key, $default = '');
        public function setUserValue(string $userId, string $app, string $key, $value);
        public function getSystemValueString(string $key): string;
    }
    interface IRequest {
        public function getHeader(string $name): string;
        public function getParam(string $key, $default = null);
    }
    interface IUser { public function getUID(): string; }
    interface IUserSession { public function getUser(): ?IUser; }
    interface IUserManager { public function userExists(string $uid): bool; }
    interface IPreview {
        public const MODE_FILL = 'fill';
        public const MODE_COVER = 'cover';
        public function getPreview(\OCP\Files\File $file, int $width, int $height, bool $crop = false, string $mode = self::MODE_FILL);
    }
    class Server {
        public static $instance;
        public static function get(string $class) {
            return self::$instance->get($class);
        }
    }
}

namespace OCP\Security {
    interface ICrypto {
        public function encrypt(string $plain): string;
        public function decrypt(string $cipher): string;
    }
}

namespace OCP\Lock {
    interface ILockingProvider {
        public const LOCK_SHARED = 1;
        public const LOCK_EXCLUSIVE = 2;
        public function acquireLock(string $path, int $type): bool;
        public function releaseLock(string $path, int $type): bool;
    }
}

namespace OCP\Files {
    interface IRootFolder {}
    interface Folder {}
    interface File {
        public function getId(): int;
        public function getEtag(): string;
        public function getSize(): int;
        public function getMimeType(): string;
        public function fopen(string $mode);
        public function getPath(): string;
    }
    class NotFoundException extends \Exception {}
    class NotPermittedException extends \Exception {}
}

namespace OCP\FilesMetadata {
    interface IFilesMetadataManager {}
}

namespace OCP\AppFramework {
    class Controller {
        public function __construct(string $appName, protected \OCP\IRequest $request) {}
    }
    class OCSController extends Controller {}
}

namespace OCP\AppFramework\Http {
    class DataResponse {
        public array $headers = [];
        public function __construct(public array $data = [], public int $status = 200) {}
        public function addHeader(string $name, string $value): void { $this->headers[$name] = $value; }
        public function getData(): array { return $this->data; }
        public function getStatus(): int { return $this->status; }
    }
    class Response {
        public array $headers = [];
        public function __construct(public int $status = 200, array $headers = []) {
            $this->headers = $headers;
        }
        public function addHeader(string $name, string $value): void { $this->headers[$name] = $value; }
        public function getHeaders(): array { return $this->headers; }
        public function getStatus(): int { return $this->status; }
    }
}

namespace OCP\AppFramework\Http\Attribute {
    #[\Attribute] class PublicPage {}
    #[\Attribute] class NoCSRFRequired {}
    #[\Attribute] class NoAdminRequired {}
}

namespace OCP\AppFramework\OCS {
    class OCSException extends \Exception {
        public function __construct(string $message = '', int $code = 400) { parent::__construct($message, $code); }
    }
    class OCSBadRequestException extends OCSException {
        public function __construct(string $message = 'Bad request') { parent::__construct($message, 400); }
    }
    class OCSNotFoundException extends OCSException {
        public function __construct(string $message = 'Not found') { parent::__construct($message, 404); }
    }
    class OCSForbiddenException extends OCSException {
        public function __construct(string $message = 'Forbidden') { parent::__construct($message, 403); }
    }
}

namespace OCP\DB\QueryBuilder {
    interface IQueryBuilder {
        public const PARAM_INT_ARRAY = 101;
        public const PARAM_STR_ARRAY = 102;
    }
}

namespace {
    $lib = __DIR__ . '/../../lib/';
    require_once $lib . 'Exception/FaceConflictException.php';
    require_once $lib . 'Http/Bytes.php';
    require_once $lib . 'AI/Service/Settings.php';
    require_once $lib . 'AI/Service/Files.php';
    require_once $lib . 'AI/Service/Inventory.php';
    require_once $lib . 'Service/Library.php';
    require_once $lib . 'AI/Service/FaceArtifacts.php';
    require_once $lib . 'AI/Service/Faces.php';
    require_once $lib . 'Controller/FaceWorkerController.php';
    require_once $lib . 'Controller/FacesController.php';
    require_once $lib . 'Controller/FacesOcsController.php';

    use OCA\Nextgallery\AI\Service\{Settings, Inventory, Faces, FaceArtifacts};
    use OCA\Nextgallery\Service\Library;
    use OCA\Nextgallery\Controller\{FaceWorkerController, FacesController, FacesOcsController};
    use OCA\Nextgallery\Exception\FaceConflictException;
    use OCP\AppFramework\OCS\{OCSBadRequestException, OCSNotFoundException};

    $checks = 0;
    function check(bool $condition, string $message): void {
        global $checks;
        $checks++;
        if (!$condition) {
            throw new \RuntimeException('Assertion failed: ' . $message);
        }
    }

    function checkThrows(callable $fn, string $expectedClass, string $message): void {
        global $checks;
        $checks++;
        try {
            $fn();
        } catch (\Throwable $e) {
            if ($e instanceof $expectedClass) {
                return;
            }
            throw new \RuntimeException($message . ' (threw ' . get_class($e) . ' instead of ' . $expectedClass . ': ' . $e->getMessage() . ')');
        }
        throw new \RuntimeException($message . ' (did not throw)');
    }

    // 1. Vector normalization tests
    $validVec = array_merge([3.0, 4.0], array_fill(0, 126, 0.0));
    $normVec = Faces::normalizeVector($validVec);
    check(count($normVec) === 128, 'Normalized vector must have 128 dimensions');
    check(abs($normVec[0] - 0.6) < 1e-6 && abs($normVec[1] - 0.8) < 1e-6, 'L2 normalization math correct');
    $l2 = 0.0;
    foreach ($normVec as $v) $l2 += $v * $v;
    check(abs($l2 - 1.0) < 1e-6, 'Norm must equal 1.0');

    checkThrows(fn() => Faces::normalizeVector(array_fill(0, 127, 0.1)), OCSBadRequestException::class, 'Vector with 127 dimensions must be rejected');
    checkThrows(fn() => Faces::normalizeVector(array_fill(0, 129, 0.1)), OCSBadRequestException::class, 'Vector with 129 dimensions must be rejected');
    checkThrows(fn() => Faces::normalizeVector(array_fill(0, 128, 0.0)), OCSBadRequestException::class, 'Zero vector must be rejected');
    checkThrows(fn() => Faces::normalizeVector(array_merge([NAN], array_fill(0, 127, 0.1))), OCSBadRequestException::class, 'NaN in vector must be rejected');
    checkThrows(fn() => Faces::normalizeVector(array_merge([INF], array_fill(0, 127, 0.1))), OCSBadRequestException::class, 'INF in vector must be rejected');

    // 2. Bbox validation tests
    $box = Faces::validateBbox([0.1, 0.2, 0.3, 0.4]);
    check($box === [0.1, 0.2, 0.3, 0.4], 'Valid bbox list accepted');
    $dictBox = Faces::validateBbox(['x' => 0.1, 'y' => 0.2, 'w' => 0.3, 'h' => 0.4]);
    check($dictBox === [0.1, 0.2, 0.3, 0.4], 'Valid bbox dict accepted');
    checkThrows(fn() => Faces::validateBbox([0.1, 0.2, 0.0, 0.4]), OCSBadRequestException::class, 'Zero width bbox must be rejected');
    checkThrows(fn() => Faces::validateBbox([0.1, 0.2, 0.3, -0.1]), OCSBadRequestException::class, 'Negative height bbox must be rejected');
    checkThrows(fn() => Faces::validateBbox([0.8, 0.2, 0.4, 0.4]), OCSBadRequestException::class, 'Bbox extending past 1.0 must be rejected');

    // 3. IoU tests
    $boxA = [0.1, 0.1, 0.2, 0.2]; // area 0.04
    check(abs(Faces::iou($boxA, $boxA) - 1.0) < 1e-6, 'Identical box IoU is 1.0');
    $boxDisjoint = [0.5, 0.5, 0.2, 0.2];
    check(Faces::iou($boxA, $boxDisjoint) === 0.0, 'Disjoint boxes IoU is 0.0');
    $boxPartial = [0.2, 0.1, 0.2, 0.2]; // overlap [0.2..0.3, 0.1..0.3] -> w=0.1, h=0.2, inter=0.02, union=0.04+0.04-0.02=0.06 -> iou=1/3
    check(abs(Faces::iou($boxA, $boxPartial) - (1.0 / 3.0)) < 1e-5, 'Partial overlap IoU accurate');

    // Setup Test Mock Database and Environment
    class TestQueryBuilder {
        private array $selects = [];
        private string $from = '';
        private array $joins = [];
        private array $wheres = [];
        private array $groupBys = [];
        private array $orderBys = [];
        private ?int $limit = null;
        private ?int $offset = null;
        private array $params = [];
        private int $pIndex = 0;

        public function __construct(private \PDO $pdo) {}
        public function select(...$cols): self { $this->selects = array_merge($this->selects, $cols); return $this; }
        public function addSelect(...$cols): self { $this->selects = array_merge($this->selects, $cols); return $this; }
        public function from(string $table, string $alias = ''): self {
            $this->from = $table . ($alias !== '' ? ' ' . $alias : '');
            return $this;
        }
        public function innerJoin(string $fromAlias, string $joinTable, string $joinAlias, string $condition): self {
            $this->joins[] = "INNER JOIN $joinTable $joinAlias ON $condition";
            return $this;
        }
        public function where(string $pred): self { $this->wheres = [$pred]; return $this; }
        public function andWhere(string $pred): self { $this->wheres[] = $pred; return $this; }
        public function groupBy(...$cols): self { $this->groupBys = array_merge($this->groupBys, $cols); return $this; }
        public function orderBy(string $col, string $dir = 'ASC'): self { $this->orderBys = ["$col $dir"]; return $this; }
        public function addOrderBy(string $col, string $dir = 'ASC'): self { $this->orderBys[] = "$col $dir"; return $this; }
        public function setFirstResult(int $offset): self { $this->offset = $offset; return $this; }
        public function setMaxResults(int $limit): self { $this->limit = $limit; return $this; }

        public function createNamedParameter($val, $type = null): string {
            $name = ':p' . (++$this->pIndex);
            if (($type === \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY || $type === \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY) && is_array($val)) {
                if (!$val) return '(NULL)';
                $placeholders = [];
                foreach ($val as $i => $item) {
                    $pSub = $name . '_' . $i;
                    $this->params[$pSub] = $item;
                    $placeholders[] = $pSub;
                }
                return '(' . implode(',', $placeholders) . ')';
            }
            $this->params[$name] = $val;
            return $name;
        }

        public function expr(): object {
            return new class {
                public function eq(string $col, string $param): string { return "$col = $param"; }
                public function in(string $col, string $param): string { return "$col IN $param"; }
            };
        }

        public function func(): object {
            return new class {
                public function countDistinct(string $col, string $alias): string { return "COUNT(DISTINCT $col) AS $alias"; }
                public function count(string $col, string $alias): string { return "COUNT($col) AS $alias"; }
            };
        }

        public function executeQuery(): object {
            $sql = 'SELECT ' . implode(', ', $this->selects) . ' FROM ' . $this->from;
            if ($this->joins) $sql .= ' ' . implode(' ', $this->joins);
            if ($this->wheres) $sql .= ' WHERE ' . implode(' AND ', $this->wheres);
            if ($this->groupBys) $sql .= ' GROUP BY ' . implode(', ', $this->groupBys);
            if ($this->orderBys) $sql .= ' ORDER BY ' . implode(', ', $this->orderBys);
            if ($this->limit !== null) $sql .= ' LIMIT ' . $this->limit;
            if ($this->offset !== null) $sql .= ' OFFSET ' . $this->offset;

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
    }

    class TestDB implements \OCP\IDBConnection {
        public \PDO $pdo;
        public function __construct() {
            $this->pdo = new \PDO('sqlite::memory:');
            $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $this->pdo->exec('CREATE TABLE nextgallery_faces_account(user_id TEXT PRIMARY KEY, epoch TEXT, generation INTEGER, rules_revision INTEGER, updated_at INTEGER)');
            $this->pdo->exec('CREATE TABLE nextgallery_faces_files(id INTEGER PRIMARY KEY AUTOINCREMENT, user_id TEXT, file_id INTEGER, etag TEXT, face_count INTEGER, updated_at INTEGER, inference_revision TEXT DEFAULT \'legacy\', UNIQUE(user_id, file_id))');
            $this->pdo->exec('CREATE TABLE nextgallery_faces_artifacts(id INTEGER PRIMARY KEY AUTOINCREMENT, file_id INTEGER, etag TEXT, inference_revision TEXT, face_count INTEGER, detections_json TEXT, created_at INTEGER, UNIQUE(file_id, etag, inference_revision))');
            $this->pdo->exec('CREATE TABLE nextgallery_faces_artifact_refs(id INTEGER PRIMARY KEY AUTOINCREMENT, artifact_id INTEGER, user_id TEXT, file_id INTEGER, etag TEXT, created_at INTEGER, UNIQUE(artifact_id, user_id))');
            $this->pdo->exec('CREATE TABLE nextgallery_faces_groups(user_id TEXT, id TEXT, hidden INTEGER, created_at INTEGER, updated_at INTEGER, PRIMARY KEY(user_id, id))');
            $this->pdo->exec('CREATE TABLE nextgallery_faces_detections(user_id TEXT, id TEXT, file_id INTEGER, etag TEXT, bbox_x REAL, bbox_y REAL, bbox_w REAL, bbox_h REAL, score REAL, sharpness REAL, group_id TEXT, model_space TEXT, vector_data TEXT, review INTEGER, manual INTEGER, created_at INTEGER, updated_at INTEGER, PRIMARY KEY(user_id, id))');
            $this->pdo->exec('CREATE TABLE nextgallery_faces_constraints(id INTEGER PRIMARY KEY AUTOINCREMENT, user_id TEXT, kind TEXT, detection_id TEXT, group_id TEXT, created_at INTEGER, UNIQUE(user_id, detection_id, group_id))');
            $this->pdo->exec('CREATE TABLE nextgallery_media(user_id TEXT, file_id INTEGER, sort_key TEXT, period TEXT, kind TEXT, etag TEXT, generation TEXT, latitude REAL, longitude REAL, payload TEXT, name_text TEXT, path_text TEXT, PRIMARY KEY(user_id, file_id))');
            $this->pdo->exec('CREATE TABLE filecache(fileid INTEGER PRIMARY KEY, storage INTEGER, path TEXT, etag TEXT, size INTEGER, mimetype INTEGER)');
        }

        public function getQueryBuilder(): TestQueryBuilder {
            return new TestQueryBuilder($this->pdo);
        }

        public function executeStatement(string $query, array $params = []): int {
            $stmt = $this->pdo->prepare(str_replace('*PREFIX*', '', $query));
            $stmt->execute($params);
            return $stmt->rowCount();
        }

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

        public function beginTransaction(): void { $this->pdo->beginTransaction(); }
        public function commit(): void { $this->pdo->commit(); }
        public function rollBack(): void { $this->pdo->rollBack(); }
    }

    class TestConfig implements \OCP\IConfig {
        public array $app = [];
        public array $user = [];
        public function getAppValue(string $a, string $k, $d = '') { return $this->app[$k] ?? $d; }
        public function setAppValue(string $a, string $k, $v) { $this->app[$k] = $v; }
        public function getUserValue(string $u, string $a, string $k, $d = '') { return $this->user[$u][$k] ?? $d; }
        public function setUserValue(string $u, string $a, string $k, $v) { $this->user[$u][$k] = $v; }
        public function getSystemValueString(string $k): string { return ''; }
    }

    class TestCrypto implements \OCP\Security\ICrypto {
        public function encrypt(string $plain): string { return $plain; }
        public function decrypt(string $cipher): string { return $cipher; }
    }

    class TestLock implements \OCP\Lock\ILockingProvider {
        public function acquireLock(string $path, int $type): bool { return true; }
        public function releaseLock(string $path, int $type): bool { return true; }
    }

    class TestMockFile implements \OCP\Files\File {
        public function __construct(public int $id, public string $etag, public int $size = 100, public string $mime = 'image/jpeg') {}
        public function getId(): int { return $this->id; }
        public function getEtag(): string { return $this->etag; }
        public function getSize(): int { return $this->size; }
        public function getMTime(): int { return 1700000000; }
        public function getMimeType(): string { return $this->mime; }
        public function fopen(string $mode) { return fopen('php://memory', 'r+'); }
        public function getPath(): string { return '/Synthetic/photo.jpg'; }
    }

    class TestInventory extends Inventory {
        public array $files = [];
        public function __construct() {}
        public function forUser(string $uid): self { return $this; }
        public function allowedFile(int $id, ?string $kind = null): \OCP\Files\File {
            if (!isset($this->files[$id])) {
                throw new OCSNotFoundException('File not found in inventory');
            }
            return $this->files[$id];
        }
    }

    class TestLibrary extends Library {
        public array $media = [];
        public function __construct(private TestDB $db) {}
        public function settings(string $uid, string $gallery = ''): array {
            return ['roots' => ['/Synthetic'], 'galleries' => [['id' => 'default', 'roots' => ['/Synthetic']]], 'order' => 'desc'];
        }
        public function scopeQuery($q, string $uid, string $column = 'm.path_text', string $gallery = ''): void {
            $param = $q->createNamedParameter('/synthetic/%');
            $q->andWhere("$column LIKE $param");
        }
        public function hydrate(string $uid, array $rows): array {
            $res = [];
            foreach ($rows as $r) {
                $payload = json_decode((string)$r['payload'], true) ?? [];
                $res[] = $payload;
            }
            return $res;
        }
        public function file(string $uid, int $id): \OCP\Files\File {
            if (isset($this->media[$id])) return $this->media[$id];
            throw new \OCP\Files\NotFoundException('Media not found');
        }
    }

    $db = new TestDB();
    $config = new TestConfig();
    $crypto = new TestCrypto();
    $settings = new Settings($config, $crypto);
    $inventory = new TestInventory();
    $library = new TestLibrary($db);
    $lock = new TestLock();
    $faces = new Faces($db, $settings, $inventory, $library, $lock);

    // 4. Account State and Generation
    $acc = $faces->accountState('alice');
    check(strlen($acc['epoch']) === 32, 'Initial epoch is 32-char hex string');
    check($acc['generation'] === 1, 'Initial generation is 1');
    check($acc['rules_revision'] === 1, 'Initial rules_revision is 1');

    $acc2 = $faces->accountState('alice');
    check($acc2['epoch'] === $acc['epoch'], 'Subsequent accountState returns same epoch');

    $rev2 = $faces->bumpRulesRevision('alice');
    check($rev2 === 2, 'bumpRulesRevision returns 2');
    check($faces->accountState('alice')['rules_revision'] === 2, 'bumpRulesRevision persisted');

    // 5. Global enable check
    $invFile = new TestMockFile(101, 'etag_v1');
    $inventory->files[101] = $invFile;
    $library->media[101] = $invFile;
    $space = Faces::MODEL_SPACE;
    $sampleVector = array_merge([1.0], array_fill(0, 127, 0.0));
    $det1 = [
        'bbox' => [0.1, 0.1, 0.2, 0.2],
        'vector' => $sampleVector,
        'score' => 0.95,
        'group_id' => '11111111111111111111111111111111',
    ];

    // Faces globally disabled by default -> must throw
    checkThrows(
        fn() => $faces->publish('alice', 101, 'etag_v1', $acc['epoch'], 1, 2, $space, [$det1]),
        OCSBadRequestException::class,
        'Publish while globally disabled must be rejected'
    );

    // Enable faces in settings
    $settingsData = $settings->defaults();
    $settingsData['faces']['enabled'] = true;
    $settingsData['faces']['min_group_size'] = 2; // This legacy grouping contract explicitly uses two photos.
    $config->setAppValue('nextgallery', 'ai_settings', json_encode($settingsData));

    // Reject non-matching model space
    checkThrows(
        fn() => $faces->publish('alice', 101, 'etag_v1', $acc['epoch'], 1, 2, 'opencv-yunet-sface-v1', [$det1]),
        OCSBadRequestException::class,
        'Publish with mismatched model space must be rejected'
    );

    // Stale epoch rejected
    checkThrows(
        fn() => $faces->publish('alice', 101, 'etag_v1', 'bad_epoch_000000000000000000000', 1, 2, $space, [$det1]),
        FaceConflictException::class,
        'Publish with mismatched epoch must throw 409 Conflict'
    );

    // Stale generation rejected
    checkThrows(
        fn() => $faces->publish('alice', 101, 'etag_v1', $acc['epoch'], 999, 2, $space, [$det1]),
        FaceConflictException::class,
        'Publish with mismatched generation must throw 409 Conflict'
    );

    // Stale rules_revision rejected
    checkThrows(
        fn() => $faces->publish('alice', 101, 'etag_v1', $acc['epoch'], 1, 999, $space, [$det1]),
        FaceConflictException::class,
        'Publish with mismatched rules_revision must throw 409 Conflict'
    );

    // Successful publish
    $pub1 = $faces->publish('alice', 101, 'etag_v1', $acc['epoch'], 1, 2, $space, [$det1]);
    check(count($pub1) === 1, 'Publish returned 1 detection');
    $stableId1 = $pub1[0]['id'];
    check(strlen($stableId1) === 32, 'Stable detection ID generated (32 hex chars)');
    check($pub1[0]['group_id'] === '11111111111111111111111111111111', 'Assigned group matched input');
    check($pub1[0]['review'] === false, 'Fresh detection not flagged for review');

    // Query statuses & count
    $st = $faces->statuses('alice', [101, 102]);
    check($st === [101 => 'etag_v1'], 'Statuses returned file mapping');
    check($faces->count('alice') === 1, 'Processed file count is 1');

    // Second publish for same file content: IoU reconciliation retains stable ID
    $det1SlightMove = [
        'bbox' => [0.105, 0.105, 0.2, 0.2], // IoU ~ 0.95 with first detection
        'vector' => $sampleVector,
        'score' => 0.96,
        'group_id' => '11111111111111111111111111111111',
    ];
    $pub2 = $faces->publish('alice', 101, 'etag_v1', $acc['epoch'], 1, 2, $space, [$det1SlightMove]);
    check(count($pub2) === 1, 'Republish returned 1 detection');
    check($pub2[0]['id'] === $stableId1, 'High IoU match retained stable detection ID across republish');

    // Zero-detection file publish (no faces in photo)
    $invFile2 = new TestMockFile(102, 'etag_v2');
    $inventory->files[102] = $invFile2;
    $pubZero = $faces->publish('alice', 102, 'etag_v2', $acc['epoch'], 1, 2, $space, []);
    check(count($pubZero) === 0, 'Zero-detection publish returned empty array');
    $stZero = $faces->statuses('alice', [102]);
    check($stZero === [102 => 'etag_v2'], 'Zero-detection file recorded in processed statuses');
    check($faces->count('alice') === 2, 'Processed count now 2');

    // 6. Action tests: hide, show, split, merge, cannot-link, resolve
    $rulesRev = 2;
    // Hide
    $hideRes = $faces->action('alice', '11111111111111111111111111111111', 'hide', null, null, $rulesRev);
    check($hideRes['success'] && $hideRes['rules_revision'] === 3, 'Hide action succeeded and bumped rules_revision to 3');
    $rulesRev = 3;

    // Show
    $showRes = $faces->action('alice', '11111111111111111111111111111111', 'show', null, null, $rulesRev);
    check($showRes['success'] && $showRes['rules_revision'] === 4, 'Show action succeeded and bumped rules_revision to 4');
    $rulesRev = 4;

    // Action with stale rules_revision must fail
    checkThrows(
        fn() => $faces->action('alice', '11111111111111111111111111111111', 'hide', null, null, 2),
        FaceConflictException::class,
        'Action with stale rules_revision must throw 409'
    );

    // Split: move detection $stableId1 out to new group $splitTarget
    $splitTarget = '22222222222222222222222222222222';
    $splitRes = $faces->action('alice', '11111111111111111111111111111111', 'split', $splitTarget, [$stableId1], $rulesRev);
    check($splitRes['success'] && $splitRes['rules_revision'] === 5, 'Split action succeeded and bumped rules_revision to 5');
    $rulesRev = 5;

    // Verify detection moved and manual flag set
    $detAfterSplit = $faces->detection('alice', $stableId1);
    check($detAfterSplit['group_id'] === $splitTarget, 'Detection group_id updated to target');
    check((int)$detAfterSplit['manual'] === 1, 'Manual flag set on split detection');

    // Now test cannot-link preservation:
    // If worker republishes detection suggesting old group '11111111111111111111111111111111',
    // backend must preserve manual assignment and flag review = 1 because of cannot-link!
    $detWorkerWantsOldGroup = [
        'bbox' => [0.105, 0.105, 0.2, 0.2],
        'vector' => $sampleVector,
        'score' => 0.96,
        'group_id' => '11111111111111111111111111111111', // worker suggests old group
    ];
    $pubProtected = $faces->publish('alice', 101, 'etag_v1', $acc['epoch'], 1, $rulesRev, $space, [$detWorkerWantsOldGroup]);
    check(count($pubProtected) === 1, 'Publish returned 1 detection');
    check($pubProtected[0]['id'] === $stableId1, 'Detection ID retained');
    check($pubProtected[0]['group_id'] === $splitTarget, 'Manual assignment preserved against worker suggestion');
    check($pubProtected[0]['review'] === true, 'Cannot-link conflict flagged review = true');

    // Resolve review
    $resolveRes = $faces->action('alice', $splitTarget, 'resolve', null, [$stableId1], $rulesRev);
    check($resolveRes['success'] && $resolveRes['rules_revision'] === 6, 'Resolve action succeeded');
    $rulesRev = 6;
    check($faces->detection('alice', $stableId1)['review'] === 0, 'Review flag cleared after resolve');

    // 7. Reset and Purge
    // Reset bumps generation and clears detections
    $resetResult = $faces->reset('alice');
    check($resetResult['generation'] === 2, 'Reset returned new generation 2');
    $accAfterReset = $faces->accountState('alice');
    check($accAfterReset['generation'] === 2, 'Generation bumped to 2 on reset');
    check($accAfterReset['epoch'] === $acc['epoch'], 'Epoch preserved on reset');
    check($faces->count('alice') === 0, 'Detections and files cleared on reset');
    check($faces->detection('alice', $stableId1) === null, 'Detection row deleted on reset');

    // Stale publish with old generation 1 must be rejected
    checkThrows(
        fn() => $faces->publish('alice', 101, 'etag_v1', $acc['epoch'], 1, $rulesRev, $space, [$det1]),
        FaceConflictException::class,
        'Late publish with old generation must be rejected with 409'
    );

    // Purge (account deletion) removes account row completely
    $faces->purge('alice');
    $accAfterPurge = $faces->accountState('alice');
    check($accAfterPurge['epoch'] !== $acc['epoch'], 'Purged account gets brand new epoch on subsequent access');
    check($accAfterPurge['generation'] === 1, 'New generation resets to 1');

    // 8. Controller tests
    class MockRequest implements \OCP\IRequest {
        public array $headers = [];
        public array $params = [];
        public function getHeader(string $k): string { return $this->headers[$k] ?? ''; }
        public function getParam(string $k, $default = null) { return $this->params[$k] ?? $default; }
    }

    class MockUser implements \OCP\IUser {
        public function __construct(public string $uid) {}
        public function getUID(): string { return $this->uid; }
    }

    class MockUserSession implements \OCP\IUserSession {
        public ?\OCP\IUser $user = null;
        public function getUser(): ?\OCP\IUser { return $this->user; }
    }

    class MockUserManager implements \OCP\IUserManager {
        public function userExists(string $uid): bool { return $uid === 'alice'; }
    }

    $req = new MockRequest();
    $sess = new MockUserSession();
    $sess->user = new MockUser('alice');
    $uMgr = new MockUserManager();

    $workerCtrl = new FaceWorkerController($req, $settings, $inventory, $faces, $uMgr);

    // Unauthenticated worker call fails with 401
    $req->headers = [];
    $resState = $workerCtrl->state();
    check($resState->getStatus() === 401, 'Unauthenticated worker request returned 401');

    // Worker auth
    $token = $settings->workerToken();
    $req->headers = ['Authorization' => 'Bearer ' . $token, 'X-Nextgallery-User' => 'alice'];
    $resStateAuth = $workerCtrl->state();
    check($resStateAuth->getStatus() === 200, 'Authenticated worker state returns 200');
    check(isset($resStateAuth->getData()['epoch']), 'Worker state contains epoch');

    // User FacesController
    $facesCtrl = new FacesController($req, $sess, $faces, $library);
    // Subjects endpoint
    $resSub = $facesCtrl->subjects();
    check($resSub->getStatus() === 200, 'Subjects endpoint returns 200');
    check(isset($resSub->getData()['items']), 'Subjects response has items');
    check($resSub->getData()['enabled'] === true, 'Faces enabled is true');

    // Worker Controller publish endpoint
    $accFresh = $faces->accountState('alice');
    $req->params = [
        'file_id' => 101,
        'etag' => 'etag_v1',
        'epoch' => $accFresh['epoch'],
        'generation' => $accFresh['generation'],
        'rules_revision' => $accFresh['rules_revision'],
        'space' => $space,
        'detections' => [$det1],
    ];
    $workerPubRes = $workerCtrl->publish();
    check($workerPubRes->getStatus() === 200, 'Worker publish returned 200');
    check($workerPubRes->getData()['stored'] === true, 'Worker publish stored is true');
    $workerDets = $workerPubRes->getData()['detections'];
    check(count($workerDets) === 1, 'Worker publish returned canonical detections');
    $pubDetId = $workerDets[0]['id'];
    $pubGroupId = $workerDets[0]['group_id'];

    // Seed nextgallery_media for media & preview queries
    $mediaPayload = json_encode([
        'id' => 101,
        'name' => 'photo.jpg',
        'path' => '/synthetic/photo.jpg',
        'kind' => 'image',
        'mime' => 'image/jpeg',
        'etag' => 'etag_v1',
        'size' => 100,
        'metadata' => ['raw' => '2026:10:20 00:00:00'],
    ]);
    $db->executeStatement(
        'INSERT INTO *PREFIX*nextgallery_media (user_id, file_id, sort_key, period, kind, etag, generation, payload, name_text, path_text) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        ['alice', 101, '20261020000000', '2026-10', 'image', 'etag_v1', 'gen1', $mediaPayload, 'photo.jpg', '/synthetic/photo.jpg']
    );

    // Subjects listing with seeded media
    // photo_count is 1, default min_group_size is 2 -> filter=single returns it, filter=all requires photo_count >= min_group_size
    $resSubSingle = $facesCtrl->subjects(filter: 'single');
    check($resSubSingle->getStatus() === 200, 'Subjects list (filter=single) returned 200');
    $subItemsSingle = $resSubSingle->getData()['items'];
    check(count($subItemsSingle) >= 1, 'Subjects list (filter=single) has 1 subject');
    check($subItemsSingle[0]['photo_count'] === 1, 'Subject photo_count is 1');
    check(isset($subItemsSingle[0]['cover_id']), 'Subject has cover_id');
    check(!isset($subItemsSingle[0]['vector']), 'No vector exposure via subjects endpoint');

    // Add a second photo for the same group -> appears in filter=all
    $mediaPayload2 = json_encode([
        'id' => 103,
        'name' => 'photo2.jpg',
        'path' => '/synthetic/photo2.jpg',
        'kind' => 'image',
        'mime' => 'image/jpeg',
        'etag' => 'etag_v3',
        'size' => 100,
        'metadata' => ['raw' => '2026:10:20 00:00:01'],
    ]);
    $db->executeStatement(
        'INSERT INTO *PREFIX*nextgallery_media (user_id, file_id, sort_key, period, kind, etag, generation, payload, name_text, path_text) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        ['alice', 103, '20261020000001', '2026-10', 'image', 'etag_v3', 'gen1', $mediaPayload2, 'photo2.jpg', '/synthetic/photo2.jpg']
    );
    $invFile3 = new TestMockFile(103, 'etag_v3');
    $inventory->files[103] = $invFile3;
    $library->media[103] = $invFile3;
    $detSecond = [
        'bbox' => [0.2, 0.2, 0.3, 0.3],
        'vector' => $sampleVector,
        'score' => 0.98,
        'group_id' => $pubGroupId,
    ];
    $faces->publish('alice', 103, 'etag_v3', $accFresh['epoch'], 1, $faces->accountState('alice')['rules_revision'], $space, [$detSecond]);

    $resSubAll = $facesCtrl->subjects(filter: 'all');
    check($resSubAll->getStatus() === 200, 'Subjects list (filter=all) returned 200');
    $subItemsAll = $resSubAll->getData()['items'];
    check(count($subItemsAll) >= 1, 'Subjects list (filter=all) now has subject with 2 photos');
    check($subItemsAll[0]['photo_count'] === 2, 'Subject photo_count is now 2');

    // Media endpoint for this subject
    $resMedia = $facesCtrl->media($pubGroupId);
    check($resMedia->getStatus() === 200, 'Media endpoint returned 200');
    $mediaData = $resMedia->getData();
    check(count($mediaData['items']) === 2, 'Media items contains 2 photos');
    $item101 = array_values(array_filter($mediaData['items'], fn($it) => $it['id'] === 101))[0];
    check($item101['face_detection_ids'] === [$pubDetId], 'face_detection_ids for file 101 contains published detection');
    check(!isset($mediaData['items'][0]['vector']), 'No vector exposure via media endpoint');

    // Preview endpoint
    // Create synthetic test JPEG in memory
    $gdImg = imagecreatetruecolor(100, 100);
    $bgColor = imagecolorallocate($gdImg, 200, 100, 50);
    imagefill($gdImg, 0, 0, $bgColor);
    ob_start();
    imagejpeg($gdImg);
    $jpegBytes = ob_get_clean();

    class TestPreviewFile extends TestMockFile {
        public function __construct(int $id, string $etag, private string $bytes) {
            parent::__construct($id, $etag, strlen($bytes), 'image/jpeg');
        }
        public function fopen(string $mode) {
            $stream = fopen('php://memory', 'r+');
            fwrite($stream, $this->bytes);
            rewind($stream);
            return $stream;
        }
    }
    $previewFile = new TestPreviewFile(101, 'etag_v1', $jpegBytes);
    $library->media[101] = $previewFile;

    $previews = new class($jpegBytes) implements \OCP\IPreview {
        public function __construct(private string $bytes) {}
        public function getPreview(\OCP\Files\File $file,int $width,int $height,bool $crop=false,string $mode=self::MODE_FILL) {
            return new class($this->bytes) {public function __construct(private string $bytes) {} public function getContent(){return $this->bytes;}};
        }
    };
    $facesCtrl = new FacesController($req, $sess, $faces, $library, $previews);
    $previewRes = $facesCtrl->preview($pubDetId);
    check($previewRes->status === 200, 'Preview endpoint returned status 200');
    check(($previewRes->getHeaders()['Content-Type'] ?? '') === 'image/jpeg', 'Preview returned Content-Type image/jpeg');
    check(strlen($previewRes->render()) > 0, 'Preview returned non-empty bytes');
    check(str_contains($previewRes->getHeaders()['Cache-Control'] ?? '', 'private, no-store'), 'Preview has Cache-Control private, no-store');

    // Preview with nonexistent detection -> 404
    $badPrev = $facesCtrl->preview('00000000000000000000000000000000');
    check($badPrev->status === 404, 'Preview for nonexistent detection returns 404');

    // Action: merge
    $targetGid = '99999999999999999999999999999999';
    $accMerge = $faces->accountState('alice');
    $mergeRes = $facesCtrl->action($pubGroupId, 'merge', $targetGid, null, $accMerge['rules_revision']);
    check($mergeRes->getStatus() === 200, 'Merge action returned 200');
    check($faces->detection('alice', $pubDetId)['group_id'] === $targetGid, 'Detection moved to target group on merge');

    // Reset via subjects/reset with confirmation
    $req->params = ['confirmation' => 'wrong'];
    $resResetBad = $facesCtrl->reset();
    check($resResetBad->getStatus() === 400, 'Reset with invalid confirmation returned 400');

    $req->params = ['confirmation' => 'faces'];
    $resResetGood = $facesCtrl->reset();
    check($resResetGood->getStatus() === 200, 'Reset with "faces" confirmation returned 200');

    // Test FacesOcsController
    $req->headers['OCS-APIRequest'] = 'true';
    $facesOcs = new FacesOcsController($req, $sess, $faces, $library);
    $resOcsSub = $facesOcs->subjects();
    check($resOcsSub->getStatus() === 200, 'FacesOcsController subjects returned 200');
    check(is_array($resOcsSub->getData()['items']), 'FacesOcsController subjects has items');

    $req->params = ['confirmation' => 'faces'];
    $resOcsReset = $facesOcs->reset();
    check($resOcsReset->getStatus() === 200, 'FacesOcsController reset returned 200');
    check($resOcsReset->getData()['reset'] === true, 'FacesOcsController reset has reset=true');

    echo "$checks faces storage and API contract assertions passed\n";
}
