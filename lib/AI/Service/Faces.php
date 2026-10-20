<?php
declare(strict_types=1);

namespace OCA\Nextgallery\AI\Service;

use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use OCP\IUserManager;
use OCP\Files\IRootFolder;
use OCP\AppFramework\OCS\{OCSBadRequestException, OCSNotFoundException};
use OCA\Nextgallery\Exception\FaceConflictException;
use OCA\Nextgallery\Service\Library;

class Faces {
    public const MODEL_SPACE = 'faces-yunet-sface-128d-v1';

    public function __construct(
        private IDBConnection $db,
        private Settings $settings,
        private Inventory $inventory,
        private Library $library,
        private ?ILockingProvider $locks = null,
        private ?Files $files = null,
        private ?State $state = null,
        private ?IUserManager $userManager = null,
        private ?IRootFolder $rootFolder = null,
        private ?FaceArtifacts $faceArtifacts = null
    ) {}

    public function forUser(string $uid): self {
        $copy = clone $this;
        $copy->settings = $this->settings->forUser($uid);
        $copy->inventory = $this->inventory->forUser($uid);
        if ($this->state !== null) {
            $copy->state = $this->state->forUser($uid);
        }
        return $copy;
    }

    private function lock(string $uid): void {
        if ($this->locks === null) return;
        $key = hash('sha256', 'nextgallery:faces:' . $uid);
        $accountLocked = false;
        try {
            $this->locks->acquireLock($key, ILockingProvider::LOCK_EXCLUSIVE);
            $accountLocked = true;
            // All cross-account artifact mutations share this lock until the
            // parent transaction commits. Never release it within store/forget.
            $this->locks->acquireLock(hash('sha256', 'nextgallery:faces:artifacts'), ILockingProvider::LOCK_EXCLUSIVE);
        } catch (\Throwable $error) {
            if ($accountLocked) $this->locks->releaseLock($key, ILockingProvider::LOCK_EXCLUSIVE);
            if ($error instanceof LockedException) throw new FaceConflictException('Personendaten werden gerade verarbeitet, bitte erneut versuchen');
            throw $error;
        }
    }

    private function unlock(string $uid): void {
        if ($this->locks === null) return;
        foreach ([hash('sha256', 'nextgallery:faces:artifacts'), hash('sha256', 'nextgallery:faces:' . $uid)] as $key) {
            $this->locks->releaseLock($key, ILockingProvider::LOCK_EXCLUSIVE);
        }
    }

    /**
     * Get or initialize account generation state (epoch UUID32, generation int, rules_revision int).
     * Race-free idempotent insertion and retrieval.
     */
    public function accountState(string $uid): array {
        $q = $this->db->getQueryBuilder();
        $q->select('epoch', 'generation', 'rules_revision')
          ->from('nextgallery_faces_account')
          ->where($q->expr()->eq('user_id', $q->createNamedParameter($uid)));
        $r = $q->executeQuery();
        try {
            $row = $r->fetch();
        } finally {
            $r->closeCursor();
        }
        if ($row) {
            return [
                'epoch' => (string)$row['epoch'],
                'generation' => (int)$row['generation'],
                'rules_revision' => (int)$row['rules_revision'],
            ];
        }

        $epoch = bin2hex(random_bytes(16));
        $generation = 1;
        $rulesRevision = 1;
        $now = time();

        $insertError = null;
        try {
            $this->db->executeStatement(
                'INSERT INTO *PREFIX*nextgallery_faces_account (user_id, epoch, generation, rules_revision, updated_at) VALUES (?, ?, ?, ?, ?)',
                [$uid, $epoch, $generation, $rulesRevision, $now]
            );
        } catch (\Throwable $error) {
            $insertError = $error; // A concurrent initializer may have inserted the row.
        }

        $q = $this->db->getQueryBuilder();
        $q->select('epoch', 'generation', 'rules_revision')
          ->from('nextgallery_faces_account')
          ->where($q->expr()->eq('user_id', $q->createNamedParameter($uid)));
        $r = $q->executeQuery();
        try {
            $row = $r->fetch();
        } finally {
            $r->closeCursor();
        }
        if ($row) {
            return [
                'epoch' => (string)$row['epoch'],
                'generation' => (int)$row['generation'],
                'rules_revision' => (int)$row['rules_revision'],
            ];
        }

        throw $insertError ?? new \RuntimeException('Personenkonto konnte nicht angelegt werden');
    }

    public function accounts(): array {
        $q = $this->db->getQueryBuilder();
        $q->select('user_id')->from('nextgallery_faces_account');
        $r = $q->executeQuery();
        $uids = [];
        try {
            while ($row = $r->fetch()) {
                $uids[] = (string)$row['user_id'];
            }
        } finally {
            $r->closeCursor();
        }
        return array_values(array_unique($uids));
    }

    public static function inRoots(string $path, array $roots): bool {
        foreach ($roots as $r) {
            if (str_starts_with($path, rtrim($r, '/') . '/')) {
                return true;
            }
        }
        return false;
    }

    public function bumpRulesRevision(string $uid): int {
        $state = $this->accountState($uid);
        $newRevision = $state['rules_revision'] + 1;
        $now = time();
        $this->db->executeStatement(
            'UPDATE *PREFIX*nextgallery_faces_account SET rules_revision = ?, updated_at = ? WHERE user_id = ?',
            [$newRevision, $now, $uid]
        );
        return $newRevision;
    }

    public static function normalizeVector(mixed $vec): array {
        if (!is_array($vec) || !array_is_list($vec)) {
            throw new OCSBadRequestException('Ungültiges Vektorformat');
        }
        $values = array_values($vec);
        if (count($values) !== 128) {
            throw new OCSBadRequestException('Vektor muss exakt 128 Dimensionen haben (SFace)');
        }
        $norm = 0.;
        foreach ($values as $v) {
            if ((!is_float($v) && !is_int($v)) || !is_finite((float)$v)) {
                throw new OCSBadRequestException('Ungültiger Vektorwert');
            }
            $val = (float)$v;
            $norm += $val * $val;
        }
        if ($norm < 1e-16 || !is_finite($norm)) {
            throw new OCSBadRequestException('Ungültiger Vektor: Norm zu klein oder nicht endlich');
        }
        $norm = sqrt($norm);
        return array_map(fn($v) => (float)($v / $norm), $values);
    }

    public static function validateBbox(mixed $box): array {
        if (is_array($box)) {
            if (array_is_list($box) && count($box) === 4) {
                [$x, $y, $w, $h] = $box;
            } elseif (isset($box['x'], $box['y'], $box['w'], $box['h'])) {
                [$x, $y, $w, $h] = [$box['x'], $box['y'], $box['w'], $box['h']];
            } else {
                throw new OCSBadRequestException('Ungültiges Bounding-Box-Format');
            }
        } else {
            throw new OCSBadRequestException('Ungültiges Bounding-Box-Format');
        }

        foreach ([$x, $y, $w, $h] as $coord) {
            if ((!is_float($coord) && !is_int($coord)) || !is_finite((float)$coord)) {
                throw new OCSBadRequestException('Ungültige Bounding-Box-Koordinaten');
            }
        }
        $x = (float)$x; $y = (float)$y; $w = (float)$w; $h = (float)$h;
        if ($x < -1e-4 || $y < -1e-4 || $w <= 1e-5 || $h <= 1e-5 || ($x + $w) > 1.0001 || ($y + $h) > 1.0001) {
            throw new OCSBadRequestException('Bounding-Box außerhalb des normalisierten Bereichs (0..1)');
        }
        return [max(0.0, min(1.0, $x)), max(0.0, min(1.0, $y)), max(0.0, min(1.0, $w)), max(0.0, min(1.0, $h))];
    }

    public static function iou(array $boxA, array $boxB): float {
        [$ax, $ay, $aw, $ah] = $boxA;
        [$bx, $by, $bw, $bh] = $boxB;
        $x1 = max($ax, $bx);
        $y1 = max($ay, $by);
        $x2 = min($ax + $aw, $bx + $bw);
        $y2 = min($ay + $ah, $by + $bh);
        $iw = max(0.0, $x2 - $x1);
        $ih = max(0.0, $y2 - $y1);
        $inter = $iw * $ih;
        $areaA = $aw * $ah;
        $areaB = $bw * $bh;
        $union = $areaA + $areaB - $inter;
        return $union > 1e-12 ? ($inter / $union) : 0.0;
    }

    /**
     * Export state for worker. Exports current accessible opted files only, matching fixed model space.
     */
    public function state(string $uid, int $cursor = 0, int $limit = 500): array {
        $limit = max(1, min(1000, $limit));
        $cursor = max(0, $cursor);
        $acc = $this->accountState($uid);

        $q = $this->db->getQueryBuilder();
        $q->select('d.id', 'd.file_id', 'd.etag', 'd.bbox_x', 'd.bbox_y', 'd.bbox_w', 'd.bbox_h',
                   'd.score', 'd.group_id', 'd.model_space', 'd.vector_data', 'd.review')
          ->from('nextgallery_faces_detections', 'd')
          ->innerJoin('d', 'nextgallery_faces_files', 'f', 'f.user_id = d.user_id AND f.file_id = d.file_id AND f.etag = d.etag')
          ->where($q->expr()->eq('d.user_id', $q->createNamedParameter($uid)))
          ->andWhere($q->expr()->eq('d.model_space', $q->createNamedParameter(self::MODEL_SPACE)))
          ->orderBy('d.created_at', 'ASC')
          ->addOrderBy('d.id', 'ASC')
          ->setFirstResult($cursor)
          ->setMaxResults($limit + 1);

        $r = $q->executeQuery();
        $rows = [];
        try {
            while ($row = $r->fetch()) {
                $rows[] = $row;
            }
        } finally {
            $r->closeCursor();
        }

        $more = count($rows) > $limit;
        if ($more) {
            array_pop($rows);
            $next = $cursor + $limit;
        } else {
            $next = null;
        }

        $fileAccessCache = [];
        $items = [];
        foreach ($rows as $row) {
            $fid = (int)$row['file_id'];
            if (!array_key_exists($fid, $fileAccessCache)) {
                try {
                    $node = $this->inventory->forUser($uid)->allowedFile($fid, 'faces');
                    $fileAccessCache[$fid] = ($node->getEtag() === $row['etag']);
                } catch (\Throwable) {
                    $fileAccessCache[$fid] = false;
                }
            }
            if (!$fileAccessCache[$fid]) {
                continue;
            }

            $vector = array_values(unpack('g*', base64_decode((string)$row['vector_data'], true) ?: ''));
            $items[] = [
                'id' => (string)$row['id'],
                'file_id' => $fid,
                'etag' => (string)$row['etag'],
                'bbox' => [(float)$row['bbox_x'], (float)$row['bbox_y'], (float)$row['bbox_w'], (float)$row['bbox_h']],
                'vector' => $vector,
                'score' => (float)$row['score'],
                'group_id' => (string)$row['group_id'],
                'review' => (bool)$row['review'],
            ];
        }

        return [
            'epoch' => $acc['epoch'],
            'generation' => $acc['generation'],
            'rules_revision' => $acc['rules_revision'],
            'model_space' => self::MODEL_SPACE,
            'inference_revision' => FaceArtifacts::revision($this->settings->get()),
            'items' => $items,
            'next' => $next,
            'more' => $more,
        ];
    }

    /**
     * Retrieve existing detections only if the file content (ETag) has NOT changed.
     * Never transfer manual rules across changed file versions.
     */
    private function getExistingDetections(string $uid, int $fileId, string $currentEtag): array {
        $fq = $this->db->getQueryBuilder();
        $fq->select('etag')->from('nextgallery_faces_files')
           ->where($fq->expr()->eq('user_id', $fq->createNamedParameter($uid)))
           ->andWhere($fq->expr()->eq('file_id', $fq->createNamedParameter($fileId)));
        $fr = $fq->executeQuery();
        $existingEtag = null;
        try {
            $existingEtag = $fr->fetchOne();
        } finally {
            $fr->closeCursor();
        }

        // If file content changed, do not carry over detections or manual assignments from previous image
        if ($existingEtag === false || $existingEtag === null || !hash_equals((string)$existingEtag, $currentEtag)) {
            return [];
        }

        $q = $this->db->getQueryBuilder();
        $q->select('id', 'bbox_x', 'bbox_y', 'bbox_w', 'bbox_h', 'score', 'group_id', 'review', 'manual')
          ->from('nextgallery_faces_detections')
          ->where($q->expr()->eq('user_id', $q->createNamedParameter($uid)))
          ->andWhere($q->expr()->eq('file_id', $q->createNamedParameter($fileId)))
          ->andWhere($q->expr()->eq('etag', $q->createNamedParameter($currentEtag)));
        $r = $q->executeQuery();
        $res = [];
        try {
            while ($row = $r->fetch()) {
                $res[] = [
                    'id' => (string)$row['id'],
                    'bbox' => [(float)$row['bbox_x'], (float)$row['bbox_y'], (float)$row['bbox_w'], (float)$row['bbox_h']],
                    'score' => (float)$row['score'],
                    'group_id' => (string)$row['group_id'],
                    'review' => (int)$row['review'],
                    'manual' => (int)$row['manual'],
                ];
            }
        } finally {
            $r->closeCursor();
        }
        return $res;
    }

    private function getCannotLinkMap(string $uid): array {
        $q = $this->db->getQueryBuilder();
        $q->select('detection_id', 'group_id')
          ->from('nextgallery_faces_constraints')
          ->where($q->expr()->eq('user_id', $q->createNamedParameter($uid)))
          ->andWhere($q->expr()->eq('kind', $q->createNamedParameter('cannot_link')));
        $r = $q->executeQuery();
        $map = [];
        try {
            while ($row = $r->fetch()) {
                $map[(string)$row['detection_id']][(string)$row['group_id']] = true;
            }
        } finally {
            $r->closeCursor();
        }
        return $map;
    }

    private function ensureGroup(string $uid, string $groupId): void {
        $q = $this->db->getQueryBuilder();
        $q->select('id')->from('nextgallery_faces_groups')
          ->where($q->expr()->eq('user_id', $q->createNamedParameter($uid)))
          ->andWhere($q->expr()->eq('id', $q->createNamedParameter($groupId)));
        $r = $q->executeQuery();
        $exists = (bool)$r->fetchOne();
        $r->closeCursor();
        if (!$exists) {
            $now = time();
            try {
                $this->db->executeStatement(
                    'INSERT INTO *PREFIX*nextgallery_faces_groups (user_id, id, hidden, created_at, updated_at) VALUES (?, ?, 0, ?, ?)',
                    [$uid, $groupId, $now, $now]
                );
            } catch (\Throwable) {}
        }
    }

    public function publish(
        string $uid,
        int $fileId,
        string $etag,
        string $epoch,
        int $generation,
        int $rulesRevision,
        string $space,
        array $detections,
        string $inferenceRevision = ''
    ): array {
        if ($fileId < 1 || strlen($etag) < 1 || strlen($etag) > 255) {
            throw new OCSBadRequestException('Ungültige Datei-Parameter');
        }
        if ($space !== self::MODEL_SPACE) {
            throw new OCSBadRequestException('Ungültiger oder nicht unterstützter Modellraum: ' . $space);
        }
        if (!array_is_list($detections) || count($detections) > 100) {
            throw new OCSBadRequestException('Zu viele oder ungültige Detections');
        }

        // Global faces enabled check
        $config = $this->settings->forUser($uid)->get();
        if (empty($config['faces']['enabled'])) {
            throw new OCSBadRequestException('Personenerkennung ist global deaktiviert');
        }

        // Per-folder check and access verification via Inventory
        $file = $this->inventory->forUser($uid)->allowedFile($fileId, 'faces');
        if (!hash_equals($file->getEtag(), $etag)) {
            throw new FaceConflictException('Bilddatei geändert (ETag-Konflikt)');
        }

        // Validate payload before locking
        $validated = [];
        foreach ($detections as $d) {
            if (!is_array($d)) throw new OCSBadRequestException('Ungültige Detection-Struktur');
            $bbox = self::validateBbox($d['bbox'] ?? null);
            $vector = self::normalizeVector($d['vector'] ?? ($d['embedding'] ?? []));
            $score = (float)($d['score'] ?? 0.0);
            if (!is_finite($score) || $score < 0.0 || $score > 1.0) {
                throw new OCSBadRequestException('Ungültiger Erkennungsscore');
            }
            $groupId = (string)($d['group_id'] ?? '');
            if (!preg_match('/^[a-f0-9]{32}$/iD', $groupId)) {
                throw new OCSBadRequestException('Ungültige group_id: 32-stelliger Hex-UUID erforderlich');
            }
            $validated[] = [
                'bbox' => $bbox,
                'vector' => $vector,
                'score' => $score,
                'group_id' => strtolower($groupId),
            ];
        }

        $this->lock($uid);
        try {
            // Re-check user existence inside lock to prevent user-deletion race
            if ($this->userManager !== null && !$this->userManager->userExists($uid)) {
                throw new OCSNotFoundException('Benutzer existiert nicht mehr');
            }

            $acc = $this->accountState($uid);
            if ($epoch !== $acc['epoch']) {
                throw new FaceConflictException('Epoch mismatch: veraltetes Worker-Ergebnis');
            }
            if ($generation !== $acc['generation']) {
                throw new FaceConflictException('Generation mismatch: verworfenes Worker-Ergebnis nach Reset');
            }
            if ($rulesRevision !== $acc['rules_revision']) {
                throw new FaceConflictException('Rules revision mismatch: Worker muss aktualisieren und wiederholen');
            }

            if ($inferenceRevision !== '') {
                if ($inferenceRevision !== FaceArtifacts::revision($this->settings->get())) {
                    throw new FaceConflictException('Inference-Revision veraltet');
                }
            }

            $existing = $this->getExistingDetections($uid, $fileId, $etag);
            $cannotLink = $this->getCannotLinkMap($uid);

            // Compute mutual unique IoU matching
            // 1. Matrix of IoU between incoming detections and existing detections
            $iouMatrix = [];
            $nVal = count($validated);
            $mEx = count($existing);

            for ($i = 0; $i < $nVal; $i++) {
                for ($j = 0; $j < $mEx; $j++) {
                    $iouMatrix[$i][$j] = self::iou($validated[$i]['bbox'], $existing[$j]['bbox']);
                }
            }

            // Best and second best for incoming detections
            $valBestEx = [];
            $valBestIou = [];
            $valSecondIou = [];

            for ($i = 0; $i < $nVal; $i++) {
                $bJ = -1; $bIou = 0.0; $sIou = 0.0;
                for ($j = 0; $j < $mEx; $j++) {
                    $sim = $iouMatrix[$i][$j];
                    if ($sim > $bIou) {
                        $sIou = $bIou;
                        $bIou = $sim;
                        $bJ = $j;
                    } elseif ($sim > $sIou) {
                        $sIou = $sim;
                    }
                }
                $valBestEx[$i] = $bJ;
                $valBestIou[$i] = $bIou;
                $valSecondIou[$i] = $sIou;
            }

            // Best incoming for each existing detection
            $exBestVal = [];
            $exBestIou = [];
            for ($j = 0; $j < $mEx; $j++) {
                $bI = -1; $bIou = 0.0;
                for ($i = 0; $i < $nVal; $i++) {
                    $sim = $iouMatrix[$i][$j];
                    if ($sim > $bIou) {
                        $bIou = $sim;
                        $bI = $i;
                    }
                }
                $exBestVal[$j] = $bI;
                $exBestIou[$j] = $bIou;
            }

            $matchedExisting = [];
            $canonical = [];
            $now = time();

            for ($i = 0; $i < $nVal; $i++) {
                $det = $validated[$i];
                $bJ = $valBestEx[$i];
                $bIou = $valBestIou[$i];
                $sIou = $valSecondIou[$i];

                $isMutual = ($bJ >= 0 && $exBestVal[$bJ] === $i);
                $isUnambiguous = $isMutual && ($bIou >= 0.5) && (($bIou - $sIou) >= 0.15);

                $review = 0;
                $manual = 0;
                $assignedGroupId = $det['group_id'];
                $existingMatch = ($bJ >= 0) ? $existing[$bJ] : null;

                if ($isUnambiguous && $existingMatch !== null && !in_array($existingMatch['id'], $matchedExisting, true)) {
                    // Mutual unique match: retain stable detection ID and manual rule
                    $detId = $existingMatch['id'];
                    $matchedExisting[] = $detId;

                    if ($existingMatch['manual'] === 1) {
                        $assignedGroupId = $existingMatch['group_id'];
                        $manual = 1;
                    }
                    if ($existingMatch['review'] === 1) {
                        $review = 1;
                    }
                } elseif ($existingMatch !== null && $bIou >= 0.3 && !in_array($existingMatch['id'], $matchedExisting, true)) {
                    // Ambiguous match: preserve for review without loss
                    $detId = $existingMatch['id'];
                    $matchedExisting[] = $detId;
                    $review = 1;

                    if ($existingMatch['manual'] === 1) {
                        $assignedGroupId = $existingMatch['group_id'];
                        $manual = 1;
                    }
                } else {
                    // New detection: stable random UUID32
                    $detId = bin2hex(random_bytes(16));
                }

                // Check cannot-link constraint: NEVER assign forbidden group and flag review if conflict
                if (isset($cannotLink[$detId][$det['group_id']]) || isset($cannotLink[$detId][$assignedGroupId])) {
                    $review = 1;
                    if ($manual === 1 && $existingMatch !== null && !isset($cannotLink[$detId][$existingMatch['group_id']])) {
                        $assignedGroupId = $existingMatch['group_id'];
                    } else {
                        // Forbidden group rejected: choose new singleton group
                        $assignedGroupId = bin2hex(random_bytes(16));
                        $manual = 0;
                    }
                }

                $canonical[] = [
                    'id' => $detId,
                    'file_id' => $fileId,
                    'etag' => $etag,
                    'bbox_x' => $det['bbox'][0],
                    'bbox_y' => $det['bbox'][1],
                    'bbox_w' => $det['bbox'][2],
                    'bbox_h' => $det['bbox'][3],
                    'bbox' => $det['bbox'],
                    'score' => $det['score'],
                    'group_id' => $assignedGroupId,
                    'model_space' => $space,
                    'vector' => $det['vector'],
                    'vector_data' => base64_encode(pack('g*', ...$det['vector'])),
                    'review' => $review,
                    'manual' => $manual,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            $liveConfig = $this->settings->forUser($uid)->get();
            if (empty($liveConfig['faces']['enabled'])) throw new FaceConflictException('Personenerkennung wurde deaktiviert');
            $liveFile = $this->inventory->forUser($uid)->allowedFile($fileId, 'faces');
            if (!hash_equals($liveFile->getEtag(), $etag)) throw new FaceConflictException('Bilddatei geändert (ETag-Konflikt)');
            if ($inferenceRevision !== '' && $inferenceRevision !== FaceArtifacts::revision($liveConfig)) throw new FaceConflictException('Inference-Revision veraltet');

            // Atomic database write
            $this->db->beginTransaction();
            try {
                // Upsert faces_files record
                $this->db->executeStatement(
                    'DELETE FROM *PREFIX*nextgallery_faces_files WHERE user_id = ? AND file_id = ?',
                    [$uid, $fileId]
                );
                $this->db->executeStatement(
                    'INSERT INTO *PREFIX*nextgallery_faces_files (user_id, file_id, etag, face_count, updated_at, inference_revision) VALUES (?, ?, ?, ?, ?, ?)',
                    [$uid, $fileId, $etag, count($canonical), $now, $inferenceRevision !== '' ? $inferenceRevision : 'unknown']
                );

                // Ensure groups exist
                foreach ($canonical as $c) {
                    $this->ensureGroup($uid, $c['group_id']);
                }

                // Replace file detections
                $this->db->executeStatement(
                    'DELETE FROM *PREFIX*nextgallery_faces_detections WHERE user_id = ? AND file_id = ?',
                    [$uid, $fileId]
                );

                foreach ($canonical as $c) {
                    $this->db->executeStatement(
                        'INSERT INTO *PREFIX*nextgallery_faces_detections (user_id, id, file_id, etag, bbox_x, bbox_y, bbox_w, bbox_h, score, group_id, model_space, vector_data, review, manual, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [
                            $uid, $c['id'], $fileId, $etag, $c['bbox_x'], $c['bbox_y'], $c['bbox_w'], $c['bbox_h'],
                            $c['score'], $c['group_id'], $c['model_space'], $c['vector_data'], $c['review'], $c['manual'],
                            $c['created_at'], $c['updated_at']
                        ]
                    );
                }

                // Atomically store shared face inference artifact and user ref
                $faceArtifacts = $this->faceArtifacts;
                $rawDetections = array_map(fn($d) => [
                    'bbox' => $d['bbox'],
                    'vector' => $d['vector'],
                    'score' => $d['score'],
                ], $validated);
                if ($inferenceRevision !== '') $faceArtifacts?->store($uid, $fileId, $etag, $inferenceRevision, $rawDetections);

                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }

            return array_map(fn($c) => [
                'id' => $c['id'],
                'file_id' => $fileId,
                'etag' => $etag,
                'bbox' => $c['bbox'],
                'vector' => $c['vector'],
                'score' => $c['score'],
                'group_id' => $c['group_id'],
                'review' => (bool)$c['review'],
            ], $canonical);
        } finally {
            $this->unlock($uid);
        }
    }

    public function statuses(string $uid, array $ids): array {
        if (!$ids) return [];
        $res = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $q = $this->db->getQueryBuilder();
            $q->select('file_id', 'etag')
              ->from('nextgallery_faces_files')
              ->where($q->expr()->eq('user_id', $q->createNamedParameter($uid)))
              ->andWhere($q->expr()->in('file_id', $q->createNamedParameter($chunk, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
            $r = $q->executeQuery();
            try {
                while ($row = $r->fetch()) {
                    $res[(int)$row['file_id']] = (string)$row['etag'];
                }
            } finally {
                $r->closeCursor();
            }
        }
        return $res;
    }

    public function count(string $uid): int {
        $q = $this->db->getQueryBuilder();
        $q->select($q->func()->count('*', 'total'))
          ->from('nextgallery_faces_files')
          ->where($q->expr()->eq('user_id', $q->createNamedParameter($uid)));
        $r = $q->executeQuery();
        try {
            return (int)$r->fetchOne();
        } finally {
            $r->closeCursor();
        }
    }

    /** The account's derived vectors must also disappear when its data is erased. */
    private function clearWorkingCache(string $uid): void {
        if ($this->state === null) return;
        $directory = $this->state->forUser($uid)->directory();
        foreach (['faces.sqlite3','faces.sqlite3-wal','faces.sqlite3-shm'] as $name) {
            $path = $directory . '/' . $name;
            if (is_link($path)) throw new \RuntimeException('Gesichtscache darf kein Symlink sein');
            if (is_file($path) && !unlink($path)) throw new \RuntimeException('Gesichtscache konnte nicht gelöscht werden');
        }
    }

    public function reset(string $uid): array {
        $this->lock($uid);
        try {
            // Pause faces processing pipeline if state service is available
            try {
                $this->state?->forUser($uid)->forPipeline('faces')->setControl('pause', false, '', 'faces');
            } catch (\Throwable) {}

            $acc = $this->accountState($uid);
            $newGeneration = $acc['generation'] + 1;
            $now = time();

            $this->db->beginTransaction();
            try {
                $this->db->executeStatement(
                    'UPDATE *PREFIX*nextgallery_faces_account SET generation = ?, updated_at = ? WHERE user_id = ?',
                    [$newGeneration, $now, $uid]
                );
                $this->db->executeStatement('DELETE FROM *PREFIX*nextgallery_faces_detections WHERE user_id = ?', [$uid]);
                $this->db->executeStatement('DELETE FROM *PREFIX*nextgallery_faces_groups WHERE user_id = ?', [$uid]);
                $this->db->executeStatement('DELETE FROM *PREFIX*nextgallery_faces_files WHERE user_id = ?', [$uid]);
                $this->db->executeStatement('DELETE FROM *PREFIX*nextgallery_faces_constraints WHERE user_id = ?', [$uid]);
                $faceArtifacts = $this->faceArtifacts;
                $faceArtifacts?->forget($uid);
                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }

            $this->clearWorkingCache($uid);
            return ['reset' => true, 'generation' => $newGeneration];
        } finally {
            $this->unlock($uid);
        }
    }

    public function purge(string $uid): void {
        // Account deletion waits briefly for an in-flight file publication.
        for ($attempt = 0; ; $attempt++) {
            try { $this->lock($uid); break; }
            catch (FaceConflictException $error) {
                if ($attempt >= 99) throw $error;
                usleep(100000);
            }
        }
        try {
            $this->db->beginTransaction();
            try {
                $this->db->executeStatement('DELETE FROM *PREFIX*nextgallery_faces_detections WHERE user_id = ?', [$uid]);
                $this->db->executeStatement('DELETE FROM *PREFIX*nextgallery_faces_groups WHERE user_id = ?', [$uid]);
                $this->db->executeStatement('DELETE FROM *PREFIX*nextgallery_faces_files WHERE user_id = ?', [$uid]);
                $this->db->executeStatement('DELETE FROM *PREFIX*nextgallery_faces_constraints WHERE user_id = ?', [$uid]);
                $this->db->executeStatement('DELETE FROM *PREFIX*nextgallery_faces_account WHERE user_id = ?', [$uid]);
                $this->faceArtifacts?->forget($uid);
                $this->db->commit();
            } catch (\Throwable $error) {
                $this->db->rollBack();
                throw $error;
            }
            $this->clearWorkingCache($uid);
        } finally {
            $this->unlock($uid);
        }
    }

    public function cleanup(string $uid): void {
        $this->lock($uid);
        try {
            $this->db->beginTransaction();
            try {
                // Prune files and detections with changed ETag in filecache (stale images)
                $this->db->executeStatement(
                    'DELETE FROM *PREFIX*nextgallery_faces_detections WHERE user_id = ? AND file_id IN (
                        SELECT f.file_id FROM *PREFIX*nextgallery_faces_files f
                        INNER JOIN *PREFIX*filecache fc ON fc.fileid = f.file_id
                        WHERE f.user_id = ? AND fc.etag != f.etag
                    )',
                    [$uid, $uid]
                );
                $this->db->executeStatement(
                    'DELETE FROM *PREFIX*nextgallery_faces_files WHERE user_id = ? AND file_id IN (
                        SELECT fc.fileid FROM *PREFIX*filecache fc
                        WHERE fc.fileid = *PREFIX*nextgallery_faces_files.file_id AND fc.etag != *PREFIX*nextgallery_faces_files.etag
                    )',
                    [$uid]
                );

                // Prune detections and files missing from filecache or revoked from nextgallery_media
                $this->db->executeStatement(
                    'DELETE FROM *PREFIX*nextgallery_faces_detections WHERE user_id = ? AND file_id NOT IN (SELECT fileid FROM *PREFIX*filecache)',
                    [$uid]
                );
                $this->db->executeStatement(
                    'DELETE FROM *PREFIX*nextgallery_faces_files WHERE user_id = ? AND file_id NOT IN (SELECT fileid FROM *PREFIX*filecache)',
                    [$uid]
                );

                // Prune empty groups
                $this->db->executeStatement(
                    'DELETE FROM *PREFIX*nextgallery_faces_groups WHERE user_id = ? AND id NOT IN (SELECT DISTINCT group_id FROM *PREFIX*nextgallery_faces_detections WHERE user_id = ?)',
                    [$uid, $uid]
                );

                // Prune orphaned constraints (missing detection or missing group)
                $this->db->executeStatement(
                    'DELETE FROM *PREFIX*nextgallery_faces_constraints WHERE user_id = ? AND (
                        detection_id NOT IN (SELECT id FROM *PREFIX*nextgallery_faces_detections WHERE user_id = ?)
                        OR group_id NOT IN (SELECT id FROM *PREFIX*nextgallery_faces_groups WHERE user_id = ?)
                    )',
                    [$uid, $uid, $uid]
                );

                $this->faceArtifacts?->cleanupUser($uid, function(int $fid, string $etag) use ($uid): bool {
                    try { return hash_equals($etag, $this->inventory->forUser($uid)->allowedFile($fid, 'faces')->getEtag()); }
                    catch (\OCP\AppFramework\OCS\OCSNotFoundException|\OCP\Files\NotFoundException|\OCP\Files\NotPermittedException) { return false; }
                });
                $this->faceArtifacts?->globalCleanup();
                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }
        } finally {
            $this->unlock($uid);
        }
    }

    public function action(
        string $uid,
        string $id,
        string $action,
        ?string $targetId = null,
        ?array $detectionIds = null,
        int $rulesRevision = 0
    ): array {
        if (!preg_match('/^[a-f0-9]{32}$/iD', $id)) {
            throw new OCSBadRequestException('Ungültige Subjekt-ID');
        }
        $id = strtolower($id);

        $this->lock($uid);
        try {
            // Validate source group exists and belongs to user
            $gq = $this->db->getQueryBuilder();
            $gq->select('id')->from('nextgallery_faces_groups')
               ->where($gq->expr()->eq('user_id', $gq->createNamedParameter($uid)))
               ->andWhere($gq->expr()->eq('id', $gq->createNamedParameter($id)));
            $gr = $gq->executeQuery();
            $groupRow = $gr->fetch();
            $gr->closeCursor();
            if (!$groupRow) {
                throw new OCSNotFoundException('Gruppe nicht gefunden');
            }

            $acc = $this->accountState($uid);
            if ($rulesRevision !== $acc['rules_revision']) {
                throw new FaceConflictException('Regelstand veraltet, bitte Ansicht aktualisieren');
            }

            // Deduplicate and validate detection IDs if provided
            $cleanDets = null;
            if ($detectionIds !== null) {
                if (!is_array($detectionIds) || empty($detectionIds)) {
                    throw new OCSBadRequestException('Detections dürfen nicht leer sein');
                }
                $dedup = array_values(array_unique($detectionIds));
                if (count($dedup) > 500) {
                    throw new OCSBadRequestException('Zu viele Detections (maximal 500)');
                }
                $cleanDets = [];
                foreach ($dedup as $did) {
                    if (!is_string($did) || !preg_match('/^[a-f0-9]{32}$/iD', $did)) {
                        throw new OCSBadRequestException('Ungültiges Detection-ID-Format');
                    }
                    $cleanDets[] = strtolower($did);
                }

                // Verify each detection exists and strictly belongs to source group and user
                if (in_array($action, ['split', 'remove'], true)) {
                    $checkQ = $this->db->getQueryBuilder();
                    $checkQ->select('id')->from('nextgallery_faces_detections')
                           ->where($checkQ->expr()->eq('user_id', $checkQ->createNamedParameter($uid)))
                           ->andWhere($checkQ->expr()->eq('group_id', $checkQ->createNamedParameter($id)))
                           ->andWhere($checkQ->expr()->in('id', $checkQ->createNamedParameter($cleanDets, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)));
                    $foundDets = $checkQ->executeQuery()->fetchAll(\PDO::FETCH_COLUMN);
                    if (count($foundDets) !== count($cleanDets)) {
                        throw new OCSBadRequestException('Detection gehört nicht zur Quellgruppe');
                    }
                }
            }

            $this->db->beginTransaction();
            try {
                $now = time();
                switch ($action) {
                    case 'hide':
                        $this->db->executeStatement(
                            'UPDATE *PREFIX*nextgallery_faces_groups SET hidden = 1, updated_at = ? WHERE user_id = ? AND id = ?',
                            [$now, $uid, $id]
                        );
                        break;

                    case 'show':
                        $this->db->executeStatement(
                            'UPDATE *PREFIX*nextgallery_faces_groups SET hidden = 0, updated_at = ? WHERE user_id = ? AND id = ?',
                            [$now, $uid, $id]
                        );
                        break;

                    case 'merge':
                        if (!$targetId || !preg_match('/^[a-f0-9]{32}$/iD', $targetId)) {
                            throw new OCSBadRequestException('Ziel-ID für Zusammenführung erforderlich');
                        }
                        $targetId = strtolower($targetId);
                        if ($targetId === $id) {
                            throw new OCSBadRequestException('Zielgruppe muss sich von Quellgruppe unterscheiden');
                        }
                        $this->ensureGroup($uid, $targetId);

                        // Move detections from source group to target group
                        $this->db->executeStatement(
                            'UPDATE *PREFIX*nextgallery_faces_detections SET group_id = ?, manual = 1, updated_at = ? WHERE user_id = ? AND group_id = ?',
                            [$targetId, $now, $uid, $id]
                        );

                        // Remap cannot-link constraints from old group to target group
                        $cq = $this->db->getQueryBuilder();
                        $cq->select('id', 'detection_id', 'kind')->from('nextgallery_faces_constraints')
                           ->where($cq->expr()->eq('user_id', $cq->createNamedParameter($uid)))
                           ->andWhere($cq->expr()->eq('group_id', $cq->createNamedParameter($id)));
                        $crows = $cq->executeQuery()->fetchAll();
                        foreach ($crows as $crow) {
                            // Idempotent remapping: check if target constraint already exists
                            $chk = $this->db->getQueryBuilder();
                            $chk->select('id')->from('nextgallery_faces_constraints')
                                ->where($chk->expr()->eq('user_id', $chk->createNamedParameter($uid)))
                                ->andWhere($chk->expr()->eq('detection_id', $chk->createNamedParameter($crow['detection_id'])))
                                ->andWhere($chk->expr()->eq('group_id', $chk->createNamedParameter($targetId)))
                                ->andWhere($chk->expr()->eq('kind', $chk->createNamedParameter($crow['kind'])));
                            if ($chk->executeQuery()->fetchOne()) {
                                $this->db->executeStatement('DELETE FROM *PREFIX*nextgallery_faces_constraints WHERE id = ?', [$crow['id']]);
                            } else {
                                $this->db->executeStatement(
                                    'UPDATE *PREFIX*nextgallery_faces_constraints SET group_id = ? WHERE id = ?',
                                    [$targetId, $crow['id']]
                                );
                            }
                        }

                        // Remove explicit merge contradictions (cannot-link to own new group)
                        $this->db->executeStatement(
                            'DELETE FROM *PREFIX*nextgallery_faces_constraints WHERE user_id = ? AND group_id = ? AND detection_id IN (
                                SELECT id FROM *PREFIX*nextgallery_faces_detections WHERE user_id = ? AND group_id = ?
                            )',
                            [$uid, $targetId, $uid, $targetId]
                        );

                        // Delete old source group
                        $this->db->executeStatement(
                            'DELETE FROM *PREFIX*nextgallery_faces_groups WHERE user_id = ? AND id = ?',
                            [$uid, $id]
                        );
                        break;

                    case 'split':
                        if ($cleanDets === null || empty($cleanDets)) {
                            throw new OCSBadRequestException('Detections für Aufteilung erforderlich');
                        }
                        $destGroup = ($targetId && preg_match('/^[a-f0-9]{32}$/iD', $targetId)) ? strtolower($targetId) : bin2hex(random_bytes(16));
                        $this->ensureGroup($uid, $destGroup);

                        foreach ($cleanDets as $did) {
                            $this->db->executeStatement(
                                'UPDATE *PREFIX*nextgallery_faces_detections SET group_id = ?, manual = 1, updated_at = ? WHERE user_id = ? AND id = ? AND group_id = ?',
                                [$destGroup, $now, $uid, $did, $id]
                            );
                            // Idempotent constraint insertion
                            $chk = $this->db->getQueryBuilder();
                            $chk->select('id')->from('nextgallery_faces_constraints')
                                ->where($chk->expr()->eq('user_id', $chk->createNamedParameter($uid)))
                                ->andWhere($chk->expr()->eq('detection_id', $chk->createNamedParameter($did)))
                                ->andWhere($chk->expr()->eq('group_id', $chk->createNamedParameter($id)))
                                ->andWhere($chk->expr()->eq('kind', $chk->createNamedParameter('cannot_link')));
                            if (!$chk->executeQuery()->fetchOne()) {
                                $this->db->executeStatement(
                                    'INSERT INTO *PREFIX*nextgallery_faces_constraints (user_id, kind, detection_id, group_id, created_at) VALUES (?, ?, ?, ?, ?)',
                                    [$uid, 'cannot_link', $did, $id, $now]
                                );
                            }
                        }
                        $rem = $this->db->executeQuery('SELECT COUNT(*) FROM *PREFIX*nextgallery_faces_detections WHERE user_id = ? AND group_id = ?', [$uid, $id]);
                        if ((int)$rem->fetchOne() === 0) {
                            $this->db->executeStatement('DELETE FROM *PREFIX*nextgallery_faces_groups WHERE user_id = ? AND id = ?', [$uid, $id]);
                        }
                        break;

                    case 'remove':
                        if ($cleanDets === null || empty($cleanDets)) {
                            throw new OCSBadRequestException('Detections für Entfernen erforderlich');
                        }
                        foreach ($cleanDets as $did) {
                            $singleton = bin2hex(random_bytes(16));
                            $this->ensureGroup($uid, $singleton);
                            $this->db->executeStatement(
                                'UPDATE *PREFIX*nextgallery_faces_detections SET group_id = ?, manual = 1, updated_at = ? WHERE user_id = ? AND id = ? AND group_id = ?',
                                [$singleton, $now, $uid, $did, $id]
                            );
                            // Idempotent constraint insertion
                            $chk = $this->db->getQueryBuilder();
                            $chk->select('id')->from('nextgallery_faces_constraints')
                                ->where($chk->expr()->eq('user_id', $chk->createNamedParameter($uid)))
                                ->andWhere($chk->expr()->eq('detection_id', $chk->createNamedParameter($did)))
                                ->andWhere($chk->expr()->eq('group_id', $chk->createNamedParameter($id)))
                                ->andWhere($chk->expr()->eq('kind', $chk->createNamedParameter('cannot_link')));
                            if (!$chk->executeQuery()->fetchOne()) {
                                $this->db->executeStatement(
                                    'INSERT INTO *PREFIX*nextgallery_faces_constraints (user_id, kind, detection_id, group_id, created_at) VALUES (?, ?, ?, ?, ?)',
                                    [$uid, 'cannot_link', $did, $id, $now]
                                );
                            }
                        }
                        $rem = $this->db->executeQuery('SELECT COUNT(*) FROM *PREFIX*nextgallery_faces_detections WHERE user_id = ? AND group_id = ?', [$uid, $id]);
                        if ((int)$rem->fetchOne() === 0) {
                            $this->db->executeStatement('DELETE FROM *PREFIX*nextgallery_faces_groups WHERE user_id = ? AND id = ?', [$uid, $id]);
                        }
                        break;

                    case 'resolve':
                        if ($cleanDets !== null && !empty($cleanDets)) {
                            foreach ($cleanDets as $did) {
                                $this->db->executeStatement(
                                    'UPDATE *PREFIX*nextgallery_faces_detections SET review = 0, updated_at = ? WHERE user_id = ? AND id = ?',
                                    [$now, $uid, $did]
                                );
                            }
                        } else {
                            $this->db->executeStatement(
                                'UPDATE *PREFIX*nextgallery_faces_detections SET review = 0, updated_at = ? WHERE user_id = ? AND group_id = ?',
                                [$now, $uid, $id]
                            );
                        }
                        break;

                    default:
                        throw new OCSBadRequestException('Unbekannte Aktion: ' . $action);
                }

                $newRevision = $acc['rules_revision'] + 1;
                $this->db->executeStatement(
                    'UPDATE *PREFIX*nextgallery_faces_account SET rules_revision = ?, updated_at = ? WHERE user_id = ?',
                    [$newRevision, $now, $uid]
                );
                $this->db->commit();
                return ['success' => true, 'rules_revision' => $newRevision];
            } catch (\Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }
        } finally {
            $this->unlock($uid);
        }
    }

    public function subjects(
        string $uid,
        string $gallery = '',
        int $cursor = 0,
        int $limit = 60,
        string $filter = 'all'
    ): array {
        $limit = max(1, min(100, $limit));
        $cursor = max(0, $cursor);
        $acc = $this->accountState($uid);
        $config = $this->settings->forUser($uid)->get();
        $minGroupSize = (int)($config['faces']['min_group_size'] ?? 2);

        // Retrieve requested gallery roots
        $gallerySettings = $this->library->settings($uid, $gallery);
        $galleryRoots = $gallerySettings['roots'] ?? [];

        // Fetch candidate groups
        $gq = $this->db->getQueryBuilder();
        $gq->select('id', 'hidden', 'updated_at')
           ->from('nextgallery_faces_groups')
           ->where($gq->expr()->eq('user_id', $gq->createNamedParameter($uid)))
           ->orderBy('updated_at', 'DESC')
           ->addOrderBy('id', 'ASC');
        $groups = $gq->executeQuery()->fetchAll();

        if (empty($groups)) {
            return [
                'items' => [],
                'next' => null,
                'more' => false,
                'epoch' => $acc['epoch'],
                'generation' => $acc['generation'],
                'rules_revision' => $acc['rules_revision'],
                'enabled' => (bool)($config['faces']['enabled'] ?? false),
            ];
        }

        // Fetch all detections with files and media etag matching
        $dq = $this->db->getQueryBuilder();
        $dq->select('d.id', 'd.group_id', 'd.file_id', 'd.score', 'd.review', 'ff.etag AS face_etag')
           ->from('nextgallery_faces_detections', 'd')
           ->innerJoin('d', 'nextgallery_faces_files', 'ff', 'ff.user_id = d.user_id AND ff.file_id = d.file_id')
           ->innerJoin('d', 'nextgallery_media', 'm', 'm.user_id = d.user_id AND m.file_id = d.file_id AND m.etag = ff.etag')
           ->where($dq->expr()->eq('d.user_id', $dq->createNamedParameter($uid)))
           ->orderBy('d.score', 'DESC');
        $allDets = $dq->executeQuery()->fetchAll();

        // Validate each file live against Library and requested gallery roots
        $fileAccessCache = [];
        $validDetsByGroup = [];
        $validFilesByGroup = [];

        foreach ($allDets as $d) {
            $fid = (int)$d['file_id'];
            if (!array_key_exists($fid, $fileAccessCache)) {
                try {
                    $node = $this->library->file($uid, $fid);
                    $home = $this->rootFolder?->getUserFolder($uid);
                    $relPath = $home ? ('/' . ltrim($home->getRelativePath($node->getPath()), '/')) : '';
                    if ($node->getEtag() === $d['face_etag'] && ($relPath === '' || self::inRoots($relPath, $galleryRoots))) {
                        $fileAccessCache[$fid] = true;
                    } else {
                        $fileAccessCache[$fid] = false;
                    }
                } catch (\Throwable) {
                    $fileAccessCache[$fid] = false;
                }
            }

            if ($fileAccessCache[$fid]) {
                $gid = (string)$d['group_id'];
                $validDetsByGroup[$gid][] = $d;
                $validFilesByGroup[$gid][$fid] = true;
            }
        }

        // Filter and compute counts/cover
        $filtered = [];
        foreach ($groups as $g) {
            $gid = (string)$g['id'];
            $photoCount = count($validFilesByGroup[$gid] ?? []);
            if ($photoCount <= 0) {
                continue;
            }

            $dets = $validDetsByGroup[$gid] ?? [];
            $reviewCount = 0;
            $coverId = null;
            $coverCandidate = (string)($g['cover_detection_id'] ?? '');

            foreach ($dets as $det) {
                if ((int)$det['review'] === 1) {
                    $reviewCount++;
                }
                if ($coverCandidate !== '' && (string)$det['id'] === $coverCandidate) {
                    $coverId = $coverCandidate;
                }
            }
            if ($coverId === null && !empty($dets)) {
                $coverId = (string)$dets[0]['id'];
            }

            $hidden = (int)$g['hidden'] === 1;
            $match = match ($filter) {
                'hidden' => $hidden,
                'review' => !$hidden && $reviewCount > 0,
                'single' => !$hidden && $photoCount < $minGroupSize,
                default => !$hidden && $photoCount >= $minGroupSize,
            };

            if ($match) {
                $filtered[] = [
                    'id' => $gid,
                    'label' => !empty($g['label']) ? (string)$g['label'] : null,
                    'photo_count' => $photoCount,
                    'cover_id' => $coverId,
                    'hidden' => $hidden,
                    'review_count' => $reviewCount,
                ];
            }
        }

        // Paginate after authorized live filtering to avoid skipped empty authorized pages
        $total = count($filtered);
        $slice = array_slice($filtered, $cursor, $limit);
        $more = ($cursor + count($slice)) < $total;
        $next = $more ? ($cursor + count($slice)) : null;

        return [
            'items' => $slice,
            'next' => $next,
            'more' => $more,
            'epoch' => $acc['epoch'],
            'generation' => $acc['generation'],
            'rules_revision' => $acc['rules_revision'],
            'enabled' => (bool)($config['faces']['enabled'] ?? false),
        ];
    }

    public function media(
        string $uid,
        string $groupId,
        string $gallery = '',
        int $cursor = 0,
        int $limit = 60
    ): array {
        if (!preg_match('/^[a-f0-9]{32}$/iD', $groupId)) {
            throw new OCSBadRequestException('Ungültige Subjekt-ID');
        }
        $groupId = strtolower($groupId);
        $limit = max(1, min(100, $limit));
        $cursor = max(0, $cursor);
        $acc = $this->accountState($uid);

        // Requested gallery roots
        $gallerySettings = $this->library->settings($uid, $gallery);
        $galleryRoots = $gallerySettings['roots'] ?? [];

        // Query media and detections matching detection etag with face_files and media etag
        $q = $this->db->getQueryBuilder();
        $q->select('d.id AS detection_id', 'd.file_id', 'd.bbox_x', 'd.bbox_y', 'd.bbox_w', 'd.bbox_h',
                   'd.score', 'd.review', 'ff.etag AS face_etag', 'm.payload', 'm.sort_key')
          ->from('nextgallery_faces_detections', 'd')
          ->innerJoin('d', 'nextgallery_faces_files', 'ff', 'ff.user_id = d.user_id AND ff.file_id = d.file_id')
          ->innerJoin('d', 'nextgallery_media', 'm', 'm.user_id = d.user_id AND m.file_id = d.file_id AND m.etag = ff.etag')
          ->where($q->expr()->eq('d.user_id', $q->createNamedParameter($uid)))
          ->andWhere($q->expr()->eq('d.group_id', $q->createNamedParameter($groupId)))
          ->orderBy('m.sort_key', 'DESC')
          ->addOrderBy('d.file_id', 'DESC');

        $rows = $q->executeQuery()->fetchAll();

        // Validate each file live with Library::file and requested gallery roots
        $filesMap = [];
        foreach ($rows as $row) {
            $fid = (int)$row['file_id'];
            if (!isset($filesMap[$fid])) {
                try {
                    $node = $this->library->file($uid, $fid);
                    $home = $this->rootFolder?->getUserFolder($uid);
                    $relPath = $home ? ('/' . ltrim($home->getRelativePath($node->getPath()), '/')) : '';
                    if ($node->getEtag() !== $row['face_etag'] || ($relPath !== '' && !self::inRoots($relPath, $galleryRoots))) {
                        continue;
                    }
                    $payload = json_decode((string)$row['payload'], true) ?: [];
                    if (($payload['path'] ?? '') !== $relPath && $relPath !== '') {
                        continue;
                    }
                    $payload['mtime'] = $node->getMTime();
                    $payload['size'] = $node->getSize();
                    $payload['faces'] = [];
                    $payload['face_detection_ids'] = [];
                    $filesMap[$fid] = $payload;
                } catch (\Throwable) {
                    continue;
                }
            }

            // Attach face detection (NO vectors exposed)
            $detIdStr = (string)$row['detection_id'];
            $filesMap[$fid]['face_detection_ids'][] = $detIdStr;
            $filesMap[$fid]['faces'][] = [
                'id' => $detIdStr,
                'box' => [(float)$row['bbox_x'], (float)$row['bbox_y'], (float)$row['bbox_w'], (float)$row['bbox_h']],
                'score' => (float)$row['score'],
                'review' => (bool)$row['review'],
            ];
        }

        $allMedia = array_values($filesMap);
        $total = count($allMedia);
        $slice = array_slice($allMedia, $cursor, $limit);
        $more = ($cursor + count($slice)) < $total;
        $next = $more ? ($cursor + count($slice)) : null;

        return [
            'items' => $slice,
            'next' => $next,
            'more' => $more,
            'rules_revision' => $acc['rules_revision'],
            'subject' => ['id' => $groupId],
        ];
    }

    public function detection(string $uid, string $detectionId): ?array {
        return $this->getDetection($uid, $detectionId);
    }

    public function getDetection(string $uid, string $detectionId): ?array {
        if (!preg_match('/^[a-f0-9]{32}$/iD', $detectionId)) {
            return null;
        }
        $q = $this->db->getQueryBuilder();
        $q->select('id', 'file_id', 'etag', 'bbox_x', 'bbox_y', 'bbox_w', 'bbox_h', 'score', 'group_id', 'review', 'manual')
          ->from('nextgallery_faces_detections')
          ->where($q->expr()->eq('user_id', $q->createNamedParameter($uid)))
          ->andWhere($q->expr()->eq('id', $q->createNamedParameter(strtolower($detectionId))));
        $r = $q->executeQuery();
        try {
            $row = $r->fetch();
            return $row ?: null;
        } finally {
            $r->closeCursor();
        }
    }
}
