<?php
declare(strict_types=1);

namespace OCA\Nextgallery\Service;

use OCP\IDBConnection;
use OCP\IConfig;
use OCP\Files\{IRootFolder, Folder, File};
use OCA\Nextgallery\AI\Service\{Settings, Faces};
use OCA\Nextgallery\Exception\FaceConflictException;

/**
 * Android People integration service.
 * Scoped to explicit mobile roots, independent of web galleries.
 */
class NativePeople {
    private const SNAPSHOT_TTL = 3600;
    private const MAX_SNAPSHOTS = 3;

    public function __construct(
        private IDBConnection $db,
        private IRootFolder $root,
        private Settings $settings,
        private IConfig $config,
        private ?Faces $faces = null,
        private ?Library $library = null
    ) {}

    private function facesService(): Faces {
        return $this->faces ??= \OCP\Server::get(Faces::class);
    }

    private function libraryService(): Library {
        return $this->library ??= \OCP\Server::get(Library::class);
    }

    /**
     * Canonicalize and validate explicit Android folder roots.
     */
    public static function validateFolders(?IRootFolder $rootFolder, string $uid, array $roots): array {
        if (!array_is_list($roots) || count($roots) > 32) {
            throw new \InvalidArgumentException('Maximal 32 Ordner auswählen.');
        }
        if ($rootFolder === null) {
            throw new \InvalidArgumentException('Dateisystem nicht verfügbar.');
        }
        $home = $rootFolder->getUserFolder($uid);
        $folders = [];
        foreach ($roots as $path) {
            if (!is_string($path) || strlen($path) > 4096 || str_contains($path, "\0") || str_contains($path, '\\') || preg_match('~(^|/)(\.|\.\.)(/|$)~', $path)) {
                throw new \InvalidArgumentException('Ungültiger Ordner.');
            }
            $path = '/' . trim($path, '/');
            try {
                $folder = $path === '/' ? $home : $home->get($path);
            } catch (\OCP\Files\NotFoundException|\OCP\Files\NotPermittedException) {
                throw new \InvalidArgumentException('Ordner nicht verfügbar.');
            }
            if (!$folder instanceof Folder || !$folder->isReadable()) {
                throw new \InvalidArgumentException('Ordner nicht verfügbar.');
            }
            $folders[$path] = $folder;
        }
        ksort($folders);
        $selected = [];
        foreach ($folders as $path => $folder) {
            if (!array_filter(array_keys($selected), fn($parent) => $parent === '/' || str_starts_with($path, $parent . '/'))) {
                $selected[$path] = $folder;
            }
        }
        return [$home, $selected];
    }

    public function folders(string $uid, array $roots): array {
        return self::validateFolders($this->root, $uid, $roots);
    }

    /**
     * Verify live read access, path within roots, and exact ETag.
     */
    public function authorizedFile(string $uid, ?Folder $home, int $fileId, string $etag, array $roots): ?array {
        if ($home === null) {
            return null;
        }
        try {
            $node = $this->libraryService()->file($uid, $fileId);
            $path = '/' . ltrim($home->getRelativePath($node->getPath()), '/');
            if (!hash_equals($etag, $node->getEtag()) || !Faces::inRoots($path, $roots)) {
                return null;
            }
            return ['file' => $node, 'path' => $path];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Query scoped detections joined with nextgallery_faces_files and nextgallery_media.
     */
    private function scopedDetections(string $uid, array $roots, ?string $groupId = null): array {
        if (empty($roots)) {
            return [];
        }
        $q = $this->db->getQueryBuilder();
        $q->select('d.id', 'd.group_id', 'd.file_id', 'd.bbox_x', 'd.bbox_y', 'd.bbox_w', 'd.bbox_h',
                   'd.score', 'd.review', 'd.manual', 'd.sharpness', 'ff.etag AS face_etag', 'm.sort_key', 'm.path_text', 'm.kind', 'm.period')
          ->from('nextgallery_faces_detections', 'd')
          ->innerJoin('d', 'nextgallery_faces_files', 'ff', 'ff.user_id = d.user_id AND ff.file_id = d.file_id')
          ->innerJoin('d', 'nextgallery_media', 'm', 'm.user_id = d.user_id AND m.file_id = d.file_id AND m.etag = ff.etag')
          ->where($q->expr()->eq('d.user_id', $q->createNamedParameter($uid)));
        if ($groupId !== null) {
            $q->andWhere($q->expr()->eq('d.group_id', $q->createNamedParameter($groupId)));
        }
        $paths = [];
        foreach ($roots as $root) {
            if ($root === '/') {
                $paths[] = "m.path_text LIKE '/%'";
                continue;
            }
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(rtrim($root, '/') . '/'));
            $paths[] = 'm.path_text LIKE ' . $q->createNamedParameter($escaped . '%') . " ESCAPE '!'";
        }
        $q->andWhere($paths ? '(' . implode(' OR ', $paths) . ')' : '1=0');
        $q->orderBy('d.score', 'DESC')->addOrderBy('d.id', 'ASC');
        $r = $q->executeQuery();
        try {
            return $r->fetchAll();
        } finally {
            $r->closeCursor();
        }
    }

    /**
     * Best-scoring detection whose file is readable now within roots.
     */
    private function readableDetection(string $uid, Folder $home, array $detections, array $roots, array &$access, int $attempts = 12): ?string {
        foreach (array_slice($detections, 0, $attempts) as $d) {
            $key = (int)$d['file_id'] . '|' . $d['face_etag'];
            $access[$key] ??= $this->authorizedFile($uid, $home, (int)$d['file_id'], (string)$d['face_etag'], $roots) !== null;
            if ($access[$key]) {
                return (string)$d['id'];
            }
        }
        return null;
    }

    /**
     * Deterministic token representing current overview / settings / scope state.
     */
    public function overviewToken(string $uid, array $canonicalRoots, array $acc, array $filters, bool $enabled): string {
        return substr(hash('sha256', json_encode([
            $acc['epoch'],
            $acc['generation'],
            $acc['rules_revision'],
            $canonicalRoots,
            $filters,
            $enabled,
        ], JSON_THROW_ON_ERROR)), 0, 32);
    }

    /**
     * Compute eligible visible subjects and their detection mapping within roots.
     */
    private function eligibleSubjectsAndFiles(string $uid, Folder $home, array $canonicalRoots, array $qualityFilters, int $minGroupPhotos): array {
        $gq = $this->db->getQueryBuilder();
        $gq->select('id', 'hidden', 'updated_at')
           ->from('nextgallery_faces_groups')
           ->where($gq->expr()->eq('user_id', $gq->createNamedParameter($uid)))
           ->orderBy('updated_at', 'DESC')
           ->addOrderBy('id', 'ASC');
        $groups = $gq->executeQuery()->fetchAll();

        $hiddenGroups = [];
        foreach ($groups as $g) {
            if ((int)$g['hidden'] === 1) {
                $hiddenGroups[(string)$g['id']] = true;
            }
        }

        $allDetections = $this->scopedDetections($uid, $canonicalRoots);
        $groupStats = [];
        foreach ($allDetections as $d) {
            $gid = (string)$d['group_id'];
            if (isset($hiddenGroups[$gid])) {
                continue;
            }
            if (!Faces::qualityEligible($d, $qualityFilters)) {
                continue;
            }
            $groupStats[$gid] ??= [];
            $groupStats[$gid][] = $d;
        }

        $eligibleSubjects = [];
        $access = [];
        $fileToSubjects = [];
        $fileToEtag = [];

        foreach ($groups as $g) {
            $gid = (string)$g['id'];
            if (isset($hiddenGroups[$gid]) || !isset($groupStats[$gid])) {
                continue;
            }
            $dets = $groupStats[$gid];
            $distinctFiles = [];
            foreach ($dets as $d) {
                $distinctFiles[(int)$d['file_id']] = true;
            }
            $photoCount = count($distinctFiles);
            if ($photoCount < $minGroupPhotos) {
                continue;
            }

            usort($dets, fn($a, $b) => (float)$b['score'] <=> (float)$a['score']);
            $cover = $this->readableDetection($uid, $home, $dets, $canonicalRoots, $access);
            if ($cover === null) {
                continue;
            }

            $eligibleSubjects[] = [
                'id' => $gid,
                'cover_id' => $cover,
                'photo_count' => $photoCount,
                'label' => null,
            ];

            foreach ($dets as $d) {
                $fid = (int)$d['file_id'];
                $fileToSubjects[$fid][$gid] = true;
                $fileToEtag[$fid] = (string)$d['face_etag'];
            }
        }

        return [$eligibleSubjects, $fileToSubjects, $fileToEtag];
    }

    /**
     * Snapshot directory private to user.
     */
    private function snapshotDir(string $uid): string {
        $dataDir = $this->config->getSystemValue('datadirectory', '/var/www/html/data');
        $dir = rtrim($dataDir, '/') . '/.nextgallery_ai/native-people-snapshots/' . hash('sha256', $uid);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        return $dir;
    }

    /**
     * Prune expired or excessive snapshots.
     */
    private function pruneSnapshots(string $dir): void {
        $now = time();
        $files = glob($dir . '/*.json') ?: [];
        $valid = [];
        foreach ($files as $file) {
            if (is_link($file)) {
                @unlink($file);
                continue;
            }
            $mtime = filemtime($file) ?: 0;
            if ($now - $mtime > self::SNAPSHOT_TTL) {
                @unlink($file);
                continue;
            }
            $valid[$file] = $mtime;
        }
        if (count($valid) >= self::MAX_SNAPSHOTS) {
            asort($valid);
            $excess = count($valid) - (self::MAX_SNAPSHOTS - 1);
            foreach (array_slice(array_keys($valid), 0, $excess) as $fileToDelete) {
                @unlink($fileToDelete);
            }
        }
    }

    /**
     * Create a fresh materialized snapshot file for download.
     */
    private function createFreshSnapshot(string $uid, Folder $home, array $canonicalRoots, array $acc, array $filters, bool $enabled): array {
        $dir = $this->snapshotDir($uid);
        $this->pruneSnapshots($dir);

        [$subjects, $fileToSubjects, $fileToEtag] = $this->eligibleSubjectsAndFiles($uid, $home, $canonicalRoots, $filters, (int)$filters['min_group_photos']);

        $candidateFileIds = array_keys($fileToSubjects);
        sort($candidateFileIds, SORT_NUMERIC);

        $cachedItems = [];
        foreach ($candidateFileIds as $fid) {
            $subIds = array_keys($fileToSubjects[$fid]);
            sort($subIds, SORT_STRING);
            $cachedItems[] = [
                'file_id' => $fid,
                'etag' => $fileToEtag[$fid],
                'subject_ids' => $subIds,
            ];
        }

        $token = bin2hex(random_bytes(16));
        $snapshot = [
            'token' => $token,
            'created_at' => time(),
            'uid' => $uid,
            'roots' => $canonicalRoots,
            'epoch' => $acc['epoch'],
            'generation' => $acc['generation'],
            'rules_revision' => $acc['rules_revision'],
            'filters' => $filters,
            'enabled' => $enabled,
            'subjects' => $subjects,
            'items' => $cachedItems,
        ];

        $tmpFile = $dir . '/' . bin2hex(random_bytes(16)) . '.tmp';
        $targetFile = $dir . '/' . $token . '.json';
        $written = @file_put_contents($tmpFile, json_encode($snapshot, JSON_THROW_ON_ERROR), LOCK_EX);
        if ($written === false) {
            @unlink($tmpFile);
            throw new \RuntimeException('Fehler beim Speichern des Personen-Snapshots');
        }
        chmod($tmpFile, 0600);
        if (!@rename($tmpFile, $targetFile)) {
            @unlink($tmpFile);
            throw new \RuntimeException('Fehler beim Veröffentlichen des Personen-Snapshots');
        }

        return $snapshot;
    }

    /**
     * Load and validate an existing materialized snapshot.
     */
    private function loadSnapshot(string $uid, string $token, array $canonicalRoots, array $acc, array $filters, bool $enabled): array {
        if (!preg_match('/^[a-f0-9]{32}$/D', $token)) {
            throw new FaceConflictException('Snapshot token stale');
        }
        $dir = $this->snapshotDir($uid);
        $file = $dir . '/' . $token . '.json';
        if (!is_file($file) || is_link($file)) {
            throw new FaceConflictException('Snapshot token stale');
        }
        $data = json_decode((string)file_get_contents($file), true);
        if (!is_array($data)) {
            @unlink($file);
            throw new FaceConflictException('Snapshot token stale');
        }
        if (($data['created_at'] ?? 0) + self::SNAPSHOT_TTL < time()) {
            @unlink($file);
            throw new FaceConflictException('Snapshot token stale');
        }
        // Invalidation conditions: roots, account state, filters, enabled
        if (($data['roots'] ?? []) !== $canonicalRoots
            || ($data['epoch'] ?? '') !== $acc['epoch']
            || ($data['generation'] ?? 0) !== $acc['generation']
            || ($data['rules_revision'] ?? 0) !== $acc['rules_revision']
            || ($data['filters'] ?? []) != $filters
            || (bool)($data['enabled'] ?? false) !== $enabled
        ) {
            @unlink($file);
            throw new FaceConflictException('Snapshot token stale');
        }
        return $data;
    }

    /**
     * POST /people/subjects
     * Always reads fresh live index; returns deterministic current overview revision token.
     */
    public function subjects(string $uid, array $roots, int $cursor = 0, int $limit = 60, string $snapshotToken = ''): array {
        $limit = max(1, min(100, $limit));
        $cursor = max(0, $cursor);
        [$home, $folders] = $this->folders($uid, $roots);
        $canonicalRoots = array_keys($folders);

        $acc = $this->facesService()->accountState($uid);
        $config = $this->settings->forUser($uid)->get();
        $enabled = (bool)($config['faces']['enabled'] ?? false);
        $filters = $config['faces_filters'];

        $overviewToken = $this->overviewToken($uid, $canonicalRoots, $acc, $filters, $enabled);
        if ($enabled && $canonicalRoots !== []) {
            // Refuse mixed live overview pages when recognition adds groups or assignments.
            $overviewToken = substr(hash('sha256', $overviewToken . json_encode($this->scopedDetections($uid, $canonicalRoots), JSON_THROW_ON_ERROR)), 0, 32);
        }
        if ($snapshotToken !== '' && !hash_equals($overviewToken, $snapshotToken)) {
            throw new FaceConflictException('Snapshot token stale');
        }

        if (!$enabled || empty($canonicalRoots)) {
            return [
                'items' => [],
                'next' => null,
                'more' => false,
                'enabled' => false,
                'snapshot_token' => $overviewToken,
            ];
        }

        // Fresh live group overview query
        $gq = $this->db->getQueryBuilder();
        $gq->select('id', 'hidden', 'updated_at')
           ->from('nextgallery_faces_groups')
           ->where($gq->expr()->eq('user_id', $gq->createNamedParameter($uid)))
           ->orderBy('updated_at', 'DESC')
           ->addOrderBy('id', 'ASC');
        $groups = $gq->executeQuery()->fetchAll();

        $hiddenGroups = [];
        foreach ($groups as $g) {
            if ((int)$g['hidden'] === 1) {
                $hiddenGroups[(string)$g['id']] = true;
            }
        }

        $allDetections = $this->scopedDetections($uid, $canonicalRoots);
        $groupStats = [];
        foreach ($allDetections as $d) {
            $gid = (string)$d['group_id'];
            if (isset($hiddenGroups[$gid])) {
                continue;
            }
            if (!Faces::qualityEligible($d, $filters)) {
                continue;
            }
            $groupStats[$gid] ??= [];
            $groupStats[$gid][] = $d;
        }

        $minGroupPhotos = (int)$filters['min_group_photos'];
        $eligibleGroups = [];
        foreach ($groups as $g) {
            $gid = (string)$g['id'];
            if (isset($hiddenGroups[$gid]) || !isset($groupStats[$gid])) {
                continue;
            }
            $dets = $groupStats[$gid];
            $distinctFiles = [];
            foreach ($dets as $d) {
                $distinctFiles[(int)$d['file_id']] = true;
            }
            $photoCount = count($distinctFiles);
            if ($photoCount < $minGroupPhotos) {
                continue;
            }
            usort($dets, fn($a, $b) => (float)$b['score'] <=> (float)$a['score']);
            $eligibleGroups[] = [
                'id' => $gid,
                'photo_count' => $photoCount,
                'dets' => $dets,
            ];
        }

        $total = count($eligibleGroups);
        $slice = array_slice($eligibleGroups, $cursor, $limit);
        $items = [];
        $access = [];
        foreach ($slice as $entry) {
            // Live-validate cover on returned page only
            $cover = $this->readableDetection($uid, $home, $entry['dets'], $canonicalRoots, $access);
            if ($cover === null) {
                continue;
            }
            $items[] = [
                'id' => $entry['id'],
                'cover_id' => $cover,
                'photo_count' => $entry['photo_count'],
                'label' => null,
            ];
        }

        $more = ($cursor + count($slice)) < $total;

        return [
            'items' => $items,
            'next' => $more ? $cursor + count($slice) : null,
            'more' => $more,
            'enabled' => $enabled,
            'snapshot_token' => $overviewToken,
        ];
    }

    /**
     * POST /people/media
     * Orders by requested Android date resolution (mode/timezone) and refills skipped candidates.
     */
    public function media(
        string $uid,
        string $subjectId,
        array $roots,
        int $cursor = 0,
        int $limit = 60,
        string $kind = '',
        string $period = '',
        string $mode = 'folder',
        string $timezone = 'UTC',
        string $order = 'desc'
    ): array {
        if (!preg_match('/^[a-f0-9]{32}$/iD', $subjectId)) {
            throw new \InvalidArgumentException('Ungültige Subjekt-ID.');
        }
        $subjectId = strtolower($subjectId);
        if (!in_array($kind, ['', 'image', 'video'], true) || $cursor < 0 || $limit < 1 || $limit > 200 || ($period !== '' && !preg_match('/^\d{4}(?:-\d{2})?(?:-\d{2})?$/D', $period))) {
            throw new \InvalidArgumentException('Ungültige Anfrage.');
        }
        if (!in_array($mode, ['folder', 'metadata', 'filename'], true) || !in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            throw new \InvalidArgumentException('Datumseinstellung ungültig.');
        }
        if (!in_array($order, ['asc', 'desc'], true)) {
            throw new \InvalidArgumentException('Sortierung ungültig.');
        }

        [$home, $folders] = $this->folders($uid, $roots);
        $canonicalRoots = array_keys($folders);
        $acc = $this->facesService()->accountState($uid);
        $rulesRevision = $acc['rules_revision'];

        if (empty($canonicalRoots)) {
            return [
                'items' => [],
                'next' => null,
                'more' => false,
                'rules_revision' => $rulesRevision,
            ];
        }

        // Verify group exists and is not hidden
        $gq = $this->db->getQueryBuilder();
        $gq->select('hidden')->from('nextgallery_faces_groups')
           ->where($gq->expr()->eq('user_id', $gq->createNamedParameter($uid)))
           ->andWhere($gq->expr()->eq('id', $gq->createNamedParameter($subjectId)));
        $groupRow = $gq->executeQuery()->fetch();
        if (!$groupRow || (int)$groupRow['hidden'] === 1) {
            return [
                'items' => [],
                'next' => null,
                'more' => false,
                'rules_revision' => $rulesRevision,
            ];
        }

        $config = $this->settings->forUser($uid)->get();
        if (!($config['faces']['enabled'] ?? false)) return ['items' => [], 'next' => null, 'more' => false, 'rules_revision' => $rulesRevision];
        $qualityFilters = $config['faces_filters'];
        $minGroupPhotos = (int)$qualityFilters['min_group_photos'];

        $rows = $this->scopedDetections($uid, $canonicalRoots, $subjectId);
        $files = [];
        foreach ($rows as $row) {
            if (!Faces::qualityEligible($row, $qualityFilters)) {
                continue;
            }
            if ($kind !== '' && ($row['kind'] ?? '') !== $kind) {
                continue;
            }
            $fid = (int)$row['file_id'];
            $files[$fid] ??= [
                'file_id' => $fid,
                'face_etag' => (string)$row['face_etag'],
                'path_text' => (string)($row['path_text'] ?? ''),
            ];
        }

        if (count($files) < $minGroupPhotos) {
            return [
                'items' => [],
                'next' => null,
                'more' => false,
                'rules_revision' => $rulesRevision,
            ];
        }

        // Fetch payload to resolve date according to requested mode/timezone
        $fileIds = array_keys($files);
        $payloads = [];
        $pq = $this->db->getQueryBuilder();
        $pq->select('file_id', 'payload')->from('nextgallery_media')
           ->where($pq->expr()->eq('user_id', $pq->createNamedParameter($uid)))
           ->andWhere($pq->expr()->in('file_id', $pq->createNamedParameter($fileIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
        foreach ($pq->executeQuery()->fetchAll() as $pr) {
            $payloads[(int)$pr['file_id']] = (string)$pr['payload'];
        }

        $candidates = [];
        foreach ($files as $fid => $entry) {
            $payload = json_decode($payloads[$fid] ?? '', true);
            $meta = is_array($payload) && isset($payload['metadata']) ? $payload['metadata'] : [];
            $capture = $meta['raw'] ?? ($meta['capture'] ?? null);
            $path = is_array($payload) && isset($payload['path']) ? (string)$payload['path'] : $entry['path_text'];
            $mtime = is_array($payload) && isset($payload['mtime']) ? (int)$payload['mtime'] : 0;

            $date = Dates::resolve($path, $capture, $mtime, $mode, $timezone);
            if ($period !== '' && !str_starts_with($date['date'], $period)) {
                continue;
            }

            $candidates[] = [
                'file_id' => $fid,
                'face_etag' => $entry['face_etag'],
                'sort' => $date['sort'],
                'date' => $date,
                'path' => $path,
                'payload' => $payload,
            ];
        }

        if (empty($candidates)) {
            return [
                'items' => [],
                'next' => null,
                'more' => false,
                'rules_revision' => $rulesRevision,
            ];
        }

        $dir = $order === 'asc' ? 1 : -1;
        usort($candidates, fn($a, $b) => $dir * ([(string)$a['sort'], (int)$a['file_id']] <=> [(string)$b['sort'], (int)$b['file_id']]));

        $total = count($candidates);
        $items = [];
        $pos = $cursor;
        while ($pos < $total && count($items) < $limit) {
            $cand = $candidates[$pos];
            $pos++;

            $live = $this->authorizedFile($uid, $home, $cand['file_id'], $cand['face_etag'], $canonicalRoots);
            if ($live === null) {
                continue;
            }
            $file = $live['file'];
            $payload = $cand['payload'];
            $meta = is_array($payload) && isset($payload['metadata']) ? $payload['metadata'] : [];
            $meta['capture'] = $meta['raw'] ?? ($meta['capture'] ?? null);
            unset($meta['raw']);

            $items[] = [
                'id' => $file->getId(),
                'name' => $file->getName(),
                'path' => $live['path'],
                'kind' => str_starts_with($file->getMimeType(), 'image/') ? 'image' : 'video',
                'etag' => $file->getEtag(),
                'mtime' => $file->getMTime(),
                'size' => $file->getSize(),
                'metadata' => $meta,
                'date' => $cand['date'],
            ];
        }

        $more = $pos < $total;
        $next = $more ? $pos : null;

        return [
            'items' => $items,
            'next' => $next,
            'more' => $more,
            'rules_revision' => $rulesRevision,
        ];
    }

    /**
     * POST /people/snapshot
     * Initial call (without token) creates fresh materialization; subsequent pages use token.
     */
    public function snapshot(string $uid, array $roots, int $cursor = 0, int $limit = 200, string $providedToken = ''): array {
        $limit = max(1, min(500, $limit));
        $cursor = max(0, $cursor);
        [$home, $folders] = $this->folders($uid, $roots);
        $canonicalRoots = array_keys($folders);

        $acc = $this->facesService()->accountState($uid);
        $config = $this->settings->forUser($uid)->get();
        $enabled = (bool)($config['faces']['enabled'] ?? false);
        $filters = $config['faces_filters'];

        if (!$enabled || empty($canonicalRoots)) {
            $deterministicToken = $this->overviewToken($uid, $canonicalRoots, $acc, $filters, $enabled);
            if ($providedToken !== '' && !hash_equals($deterministicToken, $providedToken)) {
                throw new FaceConflictException('Snapshot token stale');
            }
            return [
                'items' => [],
                'subjects' => [],
                'next' => null,
                'more' => false,
                'enabled' => false,
                'snapshot_token' => $deterministicToken,
            ];
        }

        if ($providedToken === '') {
            // Initial call: materialize fresh snapshot for offline download
            $snapshot = $this->createFreshSnapshot($uid, $home, $canonicalRoots, $acc, $filters, $enabled);
        } else {
            // Subsequent pages or verification: load existing materialized snapshot
            $snapshot = $this->loadSnapshot($uid, $providedToken, $canonicalRoots, $acc, $filters, $enabled);
        }

        $cachedItems = $snapshot['items'];
        $total = count($cachedItems);
        $slice = array_slice($cachedItems, $cursor, $limit);

        $verifiedItems = [];
        foreach ($slice as $item) {
            $live = $this->authorizedFile($uid, $home, (int)$item['file_id'], (string)$item['etag'], $canonicalRoots);
            if ($live === null) {
                continue;
            }
            $verifiedItems[] = [
                'file_id' => (int)$item['file_id'],
                'etag' => $live['file']->getEtag(),
                'subject_ids' => $item['subject_ids'],
            ];
        }

        $more = ($cursor + count($slice)) < $total;
        $next = $more ? ($cursor + count($slice)) : null;

        return [
            'items' => $verifiedItems,
            'subjects' => $snapshot['subjects'],
            'next' => $next,
            'more' => $more,
            'enabled' => $enabled,
            'snapshot_token' => $snapshot['token'],
        ];
    }

    /**
     * Return map of [fileId => face_etag] belonging to subjectId within roots.
     * Rejects hidden, absent or disabled groups and enforces min_group_photos.
     */
    public function subjectFileIds(string $uid, string $subjectId, array $roots): array {
        if (!preg_match('/^[a-f0-9]{32}$/iD', $subjectId)) {
            return [];
        }
        $subjectId = strtolower($subjectId);
        $config = $this->settings->forUser($uid)->get();
        if (!($config['faces']['enabled'] ?? false)) {
            return [];
        }

        // Verify group exists and is not hidden
        $gq = $this->db->getQueryBuilder();
        $gq->select('hidden')->from('nextgallery_faces_groups')
           ->where($gq->expr()->eq('user_id', $gq->createNamedParameter($uid)))
           ->andWhere($gq->expr()->eq('id', $gq->createNamedParameter($subjectId)));
        $groupRow = $gq->executeQuery()->fetch();
        if (!$groupRow || (int)$groupRow['hidden'] === 1) {
            return [];
        }

        [$home, $folders] = $this->folders($uid, $roots);
        $canonicalRoots = array_keys($folders);
        if (empty($canonicalRoots)) {
            return [];
        }

        $qualityFilters = $config['faces_filters'];
        $minGroupPhotos = (int)$qualityFilters['min_group_photos'];

        $rows = $this->scopedDetections($uid, $canonicalRoots, $subjectId);
        $map = [];
        foreach ($rows as $row) {
            if (Faces::qualityEligible($row, $qualityFilters)) {
                $map[(int)$row['file_id']] = (string)$row['face_etag'];
            }
        }

        if (count($map) < $minGroupPhotos) {
            return [];
        }

        return $map;
    }
}
