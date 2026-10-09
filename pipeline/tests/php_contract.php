<?php
declare(strict_types=1);
// Deliberately small test doubles, NOT a replacement for a real Nextcloud installation.
namespace OCP {
    interface IDBConnection {}
    interface IRequest { public function getHeader(string $name): string; }
    interface IUser { public function getUID(): string; }
    interface IUserSession { public function getUser(): ?IUser; }
    interface IURLGenerator { public function linkToRoute(string $route, array $parameters = []): string; }
}
namespace OCP\Files {
    interface IRootFolder {}
    interface File {}
    class NotFoundException extends \Exception {}
    class NotPermittedException extends \Exception {}
}
namespace OCP\AppFramework {
    class OCSController {
        public function __construct(string $name, protected \OCP\IRequest $request) {}
    }
}
namespace OCP\AppFramework\Http {
    class DataResponse { public function __construct(public array $data) {} }
}
namespace OCP\AppFramework\OCS {
    class OCSException extends \Exception {}
    class OCSBadRequestException extends OCSException {}
    class OCSNotFoundException extends OCSException {}
}
namespace OCP\Search {
    interface IProvider {
        public function getId(): string;
        public function getName(): string;
        public function getOrder(string $route, array $routeParameters): int;
        public function search(\OCP\IUser $user, ISearchQuery $query): SearchResult;
    }
    interface ISearchQuery { public function getCursor(); public function getLimit(): int; public function getTerm(): string; }
    class SearchResult {
        public static function complete(string $name, array $entries): self { $r = new self(); $r->entries = $entries; return $r; }
        public static function paginated(string $name, array $entries, $cursor): self { return self::complete($name, $entries); }
        public array $entries;
    }
    class SearchResultEntry { public function __construct(public string $thumbnail, public string $title, public string $subline, public string $url, public string $icon = '') {} }
}
namespace {
    use OCA\Chronicle\AI\Service\Metadata;
    use OCA\Chronicle\AI\Service\Files;
    use OCA\Chronicle\Controller\AiMetadataController;
    use OCA\Chronicle\AI\Search\Provider;
    require __DIR__ . '/../../lib/AI/Service/Metadata.php';
    require __DIR__ . '/../../lib/AI/Service/Files.php';
    require __DIR__ . '/../../lib/Controller/AiMetadataController.php';
    require __DIR__ . '/../../lib/AI/Search/Provider.php';
    $checks = 0;
    function check(bool $ok, string $message): void { global $checks; $checks++; if (!$ok) { throw new \RuntimeException($message); } }
    function refuses(callable $f, ?int $code = null): void {
        try { $f(); } catch (\OCP\AppFramework\OCS\OCSException $e) { check($code === null || $e->getCode() === $code, 'wrong refusal code'); return; }
        throw new \RuntimeException('request unexpectedly accepted');
    }
    class DB implements \OCP\IDBConnection {
 public function setValues($table,array $keys,array $data):int {$this->writes[]=[$table,array_values($keys+$data)];return 1;}
        public array $writes = [];
        public function executeStatement($sql, array $params): int { $this->writes[] = [$sql, $params]; return 1; }
    }
    class Image implements \OCP\Files\File {
        public string $etag = 'v1'; public bool $readable = true;
        public function getEtag(): string { return $this->etag; }
        public function getId(): int { return 12; }
        public function getMimeType(): string { return 'image/jpeg'; }
        public function getName(): string { return 'a.jpg'; }
        public function getPath(): string { return '/paul/files/Camera/a.jpg'; }
        public function getSize(): int { return 100; }
        public function isReadable(): bool { return $this->readable; }
    }
    class Folder {
        public function __construct(public Image $image) {}
        public function getById(int $id): array { return $id === 12 ? [$this->image] : []; }
        public function get(string $path): Image { return $this->image; }
        public function getRelativePath(string $path): string { return '/Camera/a.jpg'; }
    }
    class Root implements \OCP\Files\IRootFolder {
        public function __construct(public Folder $folder) {}
        public function getUserFolder(string $uid): Folder { if ($uid !== 'paul') { throw new \RuntimeException('cross-user access'); } return $this->folder; }
    }
    class Request implements \OCP\IRequest {
        public bool $api = true;
        public function getHeader(string $name): string { return $this->api ? 'true' : ''; }
    }
    class User implements \OCP\IUser { public function getUID(): string { return 'paul'; } }
    class Session implements \OCP\IUserSession {
        public bool $loggedIn = true;
        public function getUser(): ?\OCP\IUser { return $this->loggedIn ? new User() : null; }
    }
    $metadata = ['vision' => ['type' => 'foto', 'description' => 'Fahrrad am Strand', 'tags' => ['Fahrrad'], 'objects' => [], 'uncertain_details' => []], 'ocr' => ['text' => 'Rechnung 123']];
    $args = [12, 'v1', str_repeat('a', 64), str_repeat('b', 64), $metadata, []];
    $data = Metadata::validate(...$args);
    check($data['ocr_text'] === 'rechnung 123', 'search text normalization lost');
    check(json_decode($data['metadata_json'],true)['analysis']['ocr']['text']==='Rechnung 123','original OCR case lost');
    check($data['description']==='fahrrad am strand','description index normalization lost');
    check(json_decode($data['metadata_json'], true)['source_exif'] === [], 'EXIF separation lost');
    $bad = $args; $bad[2] = 'invalid'; refuses(fn() => Metadata::validate(...$bad));
    $bad = $args; $bad[4]['vision']['tags'] = [7]; refuses(fn() => Metadata::validate(...$bad));
    $bad = $args; $bad[4]['ocr']['text'] = str_repeat('x', 500001); refuses(fn() => Metadata::validate(...$bad));
    $db = new DB(); $service = new Metadata($db);
    $image = new Image(); $files = new Files(new Root(new Folder($image)));
    $request = new Request(); $session = new Session();
    $controller = new AiMetadataController($request, $session, $files, $service);
    $controller->upsert(...$args);
    check(count($db->writes) === 1, 'successful request missing');
    check(str_contains($db->writes[0][0], 'media_ai_meta'), 'writes outside custom table');
    check(!str_contains($db->writes[0][0], 'Rechnung'), 'SQL interpolation');
    $image->etag = 'v2'; refuses(fn() => $controller->upsert(...$args), 409);
    $image->etag = 'v1'; $image->readable = false; refuses(fn() => $controller->upsert(...$args));
    $image->readable = true; $request->api = false; refuses(fn() => $controller->upsert(...$args), 403);
    $request->api = true; $session->loggedIn = false; refuses(fn() => $controller->upsert(...$args), 401);
    check(count($db->writes) === 1, 'rejected requests wrote data');
    refuses(fn() => $files->byPath('paul', '../a.jpg'));
    refuses(fn() => $files->byId('paul', 99));
    class SearchMetadata extends Metadata {
        public function __construct(public array $rows) {}
        public function search(string $uid, string $term, int $cursor, int $limit): array { check($uid === 'paul', 'search UID'); return $this->rows; }
    }
    class URL implements \OCP\IURLGenerator {
        public function linkToRoute(string $route, array $parameters = []): string { return '/index.php/apps/files/?' . http_build_query($parameters); }
    }
    class Query implements \OCP\Search\ISearchQuery {
        public function getCursor() { return null; }
        public function getLimit(): int { return 20; }
        public function getTerm(): string { return 'Fahrrad'; }
    }
    $row = ['id' => 1, 'file_id' => 12, 'etag' => 'v1', 'description' => 'Fahrrad', 'ocr_text' => 'Rechnung'];
    $provider = new Provider(new SearchMetadata([$row]), $files, new URL());
    check(count($provider->search(new User(), new Query())->entries) === 1, 'search result missing');
    $image->etag = 'v2'; check($provider->search(new User(), new Query())->entries === [], 'stale search result');
    $image->etag = 'v1'; $image->readable = false; check($provider->search(new User(), new Query())->entries === [], 'permission leak');
    echo "$checks PHP contract assertions passed\n";
}
