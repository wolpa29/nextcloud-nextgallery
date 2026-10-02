<?php
declare(strict_types=1);
namespace OCA\MediaAI\Service;
use OCP\Files\IRootFolder;
use OCP\Files\Folder;
use OCP\IDBConnection;
use OCP\AppFramework\OCS\OCSBadRequestException;

class Inventory {
    public function __construct(private IRootFolder $root, private IDBConnection $db, private Settings $settings, private Files $files) {}
    private function scopes(): array {
        $config = $this->settings->get();
        $root = $this->root->getUserFolder($config['user_id']);
        $result = [];
        foreach ($config['folders'] as $path) {
            $node = $path === '/' ? $root : $root->get(ltrim($path, '/'));
            if (!$node instanceof Folder || !$node->isReadable()) { throw new OCSBadRequestException('Quellordner nicht lesbar: ' . $path); }
            $result[] = ['storage' => (int)$node->getStorage()->getCache()->getNumericStorageId(),
                         'path' => trim($node->getInternalPath(), '/'), 'user_path' => rtrim($path, '/')];
        }
        return $result;
    }
    private function escaped(string $value): string { return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value); }
    public function page(int $cursor = 0): array {
        if ($cursor < 0) { throw new OCSBadRequestException('Inventar-Cursor ungültig'); }
        $scopes = $this->scopes();
        $conditions = []; $parameters = [$cursor];
        foreach ($scopes as $scope) {
            $conditions[] = "(f.storage=? AND f.path LIKE ? ESCAPE '!')";
            $parameters[] = $scope['storage']; $parameters[] = $this->escaped(($scope['path'] === '' ? '' : $scope['path'] . '/')) . '%';
        }
        // Read core file cache only; versions, paths and metadata are never modified.
        $sql = 'SELECT DISTINCT f.fileid,f.storage,f.path,f.etag,f.size,m.mimetype FROM `*PREFIX*filecache` f JOIN `*PREFIX*mimetypes` m ON m.id=f.mimetype WHERE f.fileid>? AND m.mimetype LIKE \'image/%\' AND (' . implode(' OR ', $conditions) . ') ORDER BY f.fileid LIMIT 1000';
        $result = $this->db->executeQuery($sql, $parameters);
        try { $rows = $result->fetchAllAssociative(); } finally { $result->closeCursor(); }
        $config = $this->settings->get(); $items = [];
        foreach ($rows as $row) {
            $path = null;
            foreach ($scopes as $scope) {
                if ((int)$row['storage'] === $scope['storage'] && str_starts_with($row['path'], ($scope['path'] === '' ? '' : $scope['path'] . '/'))) {
                    $path = $scope['user_path'] . '/' . substr($row['path'], ($scope['path'] === '' ? 0 : strlen($scope['path']) + 1)); break;
                }
            }
            if ($path === null) { continue; }
            foreach ($config['exclude_folders'] as $exclude) {
                if ($path === $exclude || str_starts_with($path, rtrim($exclude, '/') . '/')) { continue 2; }
            }
            $items[] = ['file_id' => (int)$row['fileid'], 'path' => ltrim($path, '/'), 'etag' => $row['etag'],
                        'size' => (int)$row['size'], 'mime' => $row['mimetype']];
        }
        return ['items' => $items, 'cursor' => count($rows) === 1000 ? (int)end($rows)['fileid'] : null];
    }
    public function allowedFile(int $id): \OCP\Files\File {
        $config = $this->settings->get();
        $file = $this->files->byId($config['user_id'], $id);
        $path = '/' . ltrim($this->files->relativePath($config['user_id'], $file), '/');
        $allowed = false;
        foreach ($config['folders'] as $folder) { if ($folder === '/' || $path === $folder || str_starts_with($path, $folder . '/')) { $allowed = true; } }
        foreach ($config['exclude_folders'] as $folder) { if ($folder === '/' || $path === $folder || str_starts_with($path, $folder . '/')) { $allowed = false; } }
        if (!$allowed) { throw new \OCP\AppFramework\OCS\OCSNotFoundException('Bild außerhalb der ausgewählten Ordner'); }
        return $file;
    }
}
