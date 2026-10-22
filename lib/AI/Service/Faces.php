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
    /** Upper bound for one worker suggestion upload per account. */
    public const SUGGESTION_LIMIT = 5000;
    /** A group with only filtered faces is only pointed at groups with this many good faces. */
    public const STRONG_GROUP_FACES = 2;
    private bool $schemaChecked = false;

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

    private function ensureSchema(): void {
        if ($this->schemaChecked) return;
        $this->schemaChecked = true;
        try {
            $r = $this->db->executeQuery('SELECT label, cover_id, stranger FROM *PREFIX*nextgallery_faces_groups WHERE 1=0');
            $r->closeCursor();
        } catch (\Throwable) {
            foreach ([
                'ALTER TABLE *PREFIX*nextgallery_faces_groups ADD COLUMN label VARCHAR(120) DEFAULT NULL',
                'ALTER TABLE *PREFIX*nextgallery_faces_groups ADD COLUMN cover_id VARCHAR(32) DEFAULT NULL',
                'ALTER TABLE *PREFIX*nextgallery_faces_groups ADD COLUMN stranger SMALLINT NOT NULL DEFAULT 0',
            ] as $sql) {
                try { $this->db->executeStatement($sql); } catch (\Throwable) {}
            }
        }
        try {
            $r = $this->db->executeQuery('SELECT share_sorting FROM *PREFIX*nextgallery_faces_account WHERE 1=0');
            $r->closeCursor();
        } catch (\Throwable) {
            try { $this->db->executeStatement('ALTER TABLE *PREFIX*nextgallery_faces_account ADD COLUMN share_sorting SMALLINT NOT NULL DEFAULT 0'); } catch (\Throwable) {}
        }
    }

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

    public static function qualityEligible(array $d,array $filters):bool {
        if(!empty($d['manual']))return true;
        $box=$d['bbox']??[(float)($d['bbox_x']??0),(float)($d['bbox_y']??0),(float)($d['bbox_w']??0),(float)($d['bbox_h']??0)];
        if($box[2]*$box[3]<(float)($filters['min_face_area']??0.0025))return false;
        $sharpness=$d['sharpness']??null;
        // Older unsigned projections did not record quality; don't invent it.
        return $sharpness===null||(float)$sharpness>=(float)($filters['min_sharpness']??45.0);
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
                   'd.score', 'd.sharpness', 'd.manual', 'd.group_id', 'd.model_space', 'd.vector_data', 'd.review')
          ->from('nextgallery_face_detections', 'd')
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
        $filters=$this->settings->forUser($uid)->get()['faces_filters'];
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
                'sharpness' => isset($row['sharpness'])?(float)$row['sharpness']:null,
                'eligible' => self::qualityEligible($row,$filters),
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
          ->from('nextgallery_face_detections')
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
          ->from('nextgallery_face_rules')
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
            $sharpness=FaceArtifacts::sharpness($d['sharpness']??null);
            $groupId = (string)($d['group_id'] ?? '');
            if (!preg_match('/^[a-f0-9]{32}$/iD', $groupId)) {
                throw new OCSBadRequestException('Ungültige group_id: 32-stelliger Hex-UUID erforderlich');
            }
            $validated[] = [
                'bbox' => $bbox,
                'vector' => $vector,
                'score' => $score,
                'group_id' => strtolower($groupId),
                'sharpness' => $sharpness,
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
                    'sharpness' => $det['sharpness'],
                    'eligible' => self::qualityEligible(['bbox'=>$det['bbox'],'sharpness'=>$det['sharpness'],'manual'=>$manual],$config['faces_filters']),
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
                    'DELETE FROM *PREFIX*nextgallery_face_detections WHERE user_id = ? AND file_id = ?',
                    [$uid, $fileId]
                );

                foreach ($canonical as $c) {
                    $this->db->executeStatement(
                        'INSERT INTO *PREFIX*nextgallery_face_detections (user_id, id, file_id, etag, bbox_x, bbox_y, bbox_w, bbox_h, score, group_id, model_space, vector_data, review, manual, created_at, updated_at, sharpness) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [
                            $uid, $c['id'], $fileId, $etag, $c['bbox_x'], $c['bbox_y'], $c['bbox_w'], $c['bbox_h'],
                            $c['score'], $c['group_id'], $c['model_space'], $c['vector_data'], $c['review'], $c['manual'],
                            $c['created_at'], $c['updated_at'], $c['sharpness']
                        ]
                    );
                }

                // Atomically store shared face inference artifact and user ref
                $faceArtifacts = $this->faceArtifacts;
                $rawDetections = array_map(fn($d) => [
                    'bbox' => $d['bbox'],
                    'vector' => $d['vector'],
                    'score' => $d['score'],
                    'sharpness' => $d['sharpness'],
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
                'sharpness' => $c['sharpness'],
                'eligible' => $c['eligible'],
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

    /** Remove expired derived native downloads, or every snapshot of an erased account. */
    public function cleanupNativeSnapshots(?string $uid = null): void {
        if ($this->state !== null) \OCA\Nextgallery\Service\NativePeopleSnapshots::cleanup($this->state->rootDirectory(), $uid);
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
                $this->db->executeStatement('DELETE FROM *PREFIX*nextgallery_face_detections WHERE user_id = ?', [$uid]);
                $this->db->executeStatement('DELETE FROM *PREFIX*nextgallery_faces_groups WHERE user_id = ?', [$uid]);
                $this->db->executeStatement('DELETE FROM *PREFIX*nextgallery_faces_files WHERE user_id = ?', [$uid]);
                $this->db->executeStatement('DELETE FROM *PREFIX*nextgallery_face_rules WHERE user_id = ?', [$uid]);
                $this->db->executeStatement('DELETE FROM *PREFIX*nextgallery_face_suggest WHERE user_id = ?', [$uid]);
                $this->db->executeStatement('DELETE FROM *PREFIX*nextgallery_faces_dismissed WHERE user_id = ?', [$uid]);
                $faceArtifacts = $this->faceArtifacts;
                $faceArtifacts?->forget($uid);
                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }

            $this->clearWorkingCache($uid);
            $this->cleanupNativeSnapshots($uid);
            return ['reset' => true, 'generation' => $newGeneration];
        } finally {
            $this->unlock($uid);
        }
    }

    public function resetFiles(string $uid, array $fileIds): int {
        $ids = array_values(array_unique(array_filter(array_map('intval', $fileIds), fn($id) => $id > 0)));
        if (!$ids) return 0;
        $this->lock($uid);
        try {
            $affected = 0;
            $this->db->beginTransaction();
            try {
                foreach (array_chunk($ids, 500) as $chunk) {
                    $marks = implode(',', array_fill(0, count($chunk), '?'));
                    $params = array_merge([$uid], $chunk);
                    $this->db->executeStatement('DELETE FROM *PREFIX*nextgallery_face_detections WHERE user_id = ? AND file_id IN ('.$marks.')', $params);
                    $affected += (int)$this->db->executeStatement('DELETE FROM *PREFIX*nextgallery_faces_files WHERE user_id = ? AND file_id IN ('.$marks.')', $params);
                }
                $this->db->executeStatement(
                    'DELETE FROM *PREFIX*nextgallery_faces_groups WHERE user_id = ? AND id NOT IN (SELECT DISTINCT group_id FROM *PREFIX*nextgallery_face_detections WHERE user_id = ?)',
                    [$uid, $uid]
                );
                $this->db->executeStatement(
                    'DELETE FROM *PREFIX*nextgallery_face_rules WHERE user_id = ? AND (
                        detection_id NOT IN (SELECT id FROM *PREFIX*nextgallery_face_detections WHERE user_id = ?)
                        OR group_id NOT IN (SELECT id FROM *PREFIX*nextgallery_faces_groups WHERE user_id = ?)
                    )',
                    [$uid, $uid, $uid]
                );
                foreach (['nextgallery_face_suggest', 'nextgallery_faces_dismissed'] as $table) {
                    $this->db->executeStatement(
                        'DELETE FROM *PREFIX*' . $table . ' WHERE user_id = ? AND (
                            group_a NOT IN (SELECT id FROM *PREFIX*nextgallery_faces_groups WHERE user_id = ?)
                            OR group_b NOT IN (SELECT id FROM *PREFIX*nextgallery_faces_groups WHERE user_id = ?)
                        )',
                        [$uid, $uid, $uid]
                    );
                }
                $this->faceArtifacts?->forgetFiles($uid, $ids);
                if ($affected > 0) $this->bumpRulesRevision($uid);
                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }
            $this->cleanupNativeSnapshots($uid);
            return $affected;
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
                $this->db->executeStatement('DELETE FROM *PREFIX*nextgallery_face_detections WHERE user_id = ?', [$uid]);
                $this->db->executeStatement('DELETE FROM *PREFIX*nextgallery_faces_groups WHERE user_id = ?', [$uid]);
                $this->db->executeStatement('DELETE FROM *PREFIX*nextgallery_faces_files WHERE user_id = ?', [$uid]);
                $this->db->executeStatement('DELETE FROM *PREFIX*nextgallery_face_rules WHERE user_id = ?', [$uid]);
                $this->db->executeStatement('DELETE FROM *PREFIX*nextgallery_face_suggest WHERE user_id = ?', [$uid]);
                $this->db->executeStatement('DELETE FROM *PREFIX*nextgallery_faces_dismissed WHERE user_id = ?', [$uid]);
                $this->db->executeStatement('DELETE FROM *PREFIX*nextgallery_faces_account WHERE user_id = ?', [$uid]);
                $this->faceArtifacts?->forget($uid);
                $this->db->commit();
            } catch (\Throwable $error) {
                $this->db->rollBack();
                throw $error;
            }
            $this->clearWorkingCache($uid);
            $this->cleanupNativeSnapshots($uid);
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
                    'DELETE FROM *PREFIX*nextgallery_face_detections WHERE user_id = ? AND file_id IN (
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
                    'DELETE FROM *PREFIX*nextgallery_face_detections WHERE user_id = ? AND file_id NOT IN (SELECT fileid FROM *PREFIX*filecache)',
                    [$uid]
                );
                $this->db->executeStatement(
                    'DELETE FROM *PREFIX*nextgallery_faces_files WHERE user_id = ? AND file_id NOT IN (SELECT fileid FROM *PREFIX*filecache)',
                    [$uid]
                );

                // Prune empty groups
                $this->db->executeStatement(
                    'DELETE FROM *PREFIX*nextgallery_faces_groups WHERE user_id = ? AND id NOT IN (SELECT DISTINCT group_id FROM *PREFIX*nextgallery_face_detections WHERE user_id = ?)',
                    [$uid, $uid]
                );

                // Prune orphaned constraints (missing detection or missing group)
                $this->db->executeStatement(
                    'DELETE FROM *PREFIX*nextgallery_face_rules WHERE user_id = ? AND (
                        detection_id NOT IN (SELECT id FROM *PREFIX*nextgallery_face_detections WHERE user_id = ?)
                        OR group_id NOT IN (SELECT id FROM *PREFIX*nextgallery_faces_groups WHERE user_id = ?)
                    )',
                    [$uid, $uid, $uid]
                );

                foreach (['nextgallery_face_suggest', 'nextgallery_faces_dismissed'] as $table) {
                    $this->db->executeStatement(
                        'DELETE FROM *PREFIX*' . $table . ' WHERE user_id = ? AND (
                            group_a NOT IN (SELECT id FROM *PREFIX*nextgallery_faces_groups WHERE user_id = ?)
                            OR group_b NOT IN (SELECT id FROM *PREFIX*nextgallery_faces_groups WHERE user_id = ?)
                        )',
                        [$uid, $uid, $uid]
                    );
                }

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
        int $rulesRevision = 0,
        ?string $label = null
    ): array {
        $this->ensureSchema();
        if (!preg_match('/^[a-f0-9]{32}$/iD', $id)) {
            throw new OCSBadRequestException('Ungültige Subjekt-ID');
        }
        $id = strtolower($id);

        $this->lock($uid);
        try {
            // Validate source group exists and belongs to user
            $gq = $this->db->getQueryBuilder();
            $gq->select('id', 'label', 'cover_id', 'stranger', 'hidden')->from('nextgallery_faces_groups')
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
                if (in_array($action, ['split', 'remove', 'set_cover'], true)) {
                    $checkQ = $this->db->getQueryBuilder();
                    $checkQ->select('id')->from('nextgallery_face_detections')
                           ->where($checkQ->expr()->eq('user_id', $checkQ->createNamedParameter($uid)))
                           ->andWhere($checkQ->expr()->eq('group_id', $checkQ->createNamedParameter($id)))
                           ->andWhere($checkQ->expr()->in('id', $checkQ->createNamedParameter($cleanDets, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)));
                    $foundDets = $checkQ->executeQuery()->fetchAll(\PDO::FETCH_COLUMN);
                    if (count($foundDets) !== count($cleanDets)) {
                        throw new OCSBadRequestException('Detection gehört nicht zur Quellgruppe');
                    }
                }
            }

            $extraResponse = [];
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

                    case 'stranger':
                        $this->db->executeStatement(
                            'UPDATE *PREFIX*nextgallery_faces_groups SET stranger = 1, hidden = 0, updated_at = ? WHERE user_id = ? AND id = ?',
                            [$now, $uid, $id]
                        );
                        $extraResponse['stranger'] = true;
                        break;

                    case 'unstranger':
                        $this->db->executeStatement(
                            'UPDATE *PREFIX*nextgallery_faces_groups SET stranger = 0, updated_at = ? WHERE user_id = ? AND id = ?',
                            [$now, $uid, $id]
                        );
                        $extraResponse['stranger'] = false;
                        break;

                    case 'rename':
                        $rawLabel = is_string($label) ? trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $label)) : '';
                        if (mb_strlen($rawLabel) > 120) {
                            $rawLabel = trim(mb_substr($rawLabel, 0, 120));
                        }
                        $cleanLabel = $rawLabel !== '' ? $rawLabel : null;
                        $this->db->executeStatement(
                            'UPDATE *PREFIX*nextgallery_faces_groups SET label = ?, updated_at = ? WHERE user_id = ? AND id = ?',
                            [$cleanLabel, $now, $uid, $id]
                        );
                        $extraResponse['label'] = $cleanLabel;
                        break;

                    case 'set_cover':
                        $coverDetId = $targetId !== null && $targetId !== '' ? strtolower($targetId) : ($cleanDets[0] ?? null);
                        if ($coverDetId !== null) {
                            if (!preg_match('/^[a-f0-9]{32}$/iD', $coverDetId)) {
                                throw new OCSBadRequestException('Ungültige Detection-ID für Vorschaubild');
                            }
                            $chkDet = $this->db->executeQuery(
                                'SELECT id FROM *PREFIX*nextgallery_face_detections WHERE user_id = ? AND group_id = ? AND id = ?',
                                [$uid, $id, $coverDetId]
                            );
                            $existsDet = (bool)$chkDet->fetchOne();
                            $chkDet->closeCursor();
                            if (!$existsDet) {
                                throw new OCSBadRequestException('Detection gehört nicht zur Quellgruppe');
                            }
                        }
                        $this->db->executeStatement(
                            'UPDATE *PREFIX*nextgallery_faces_groups SET cover_id = ?, updated_at = ? WHERE user_id = ? AND id = ?',
                            [$coverDetId, $now, $uid, $id]
                        );
                        $extraResponse['cover_id'] = $coverDetId;
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

                        $mergeResult = $this->executeMerge($uid, [$id], $targetId, $now, [$id => $groupRow]);
                        $extraResponse['target_id'] = $targetId;
                        $extraResponse['merged_detection_ids'] = $mergeResult['merged_detection_ids'];
                        $extraResponse['label'] = $mergeResult['label'];
                        break;

                    case 'dismiss':
                        // "Not the same person": the pair is no longer suggested; nothing is moved.
                        if (!$targetId || !preg_match('/^[a-f0-9]{32}$/iD', $targetId) || strtolower($targetId) === $id) {
                            throw new OCSBadRequestException('Andere Gruppe für „Nicht dieselbe Person“ erforderlich');
                        }
                        $this->dismissPair($uid, $id, strtolower($targetId), $now);
                        break;

                    case 'split':
                        if ($cleanDets === null || empty($cleanDets)) {
                            throw new OCSBadRequestException('Detections für Aufteilung erforderlich');
                        }
                        $destGroup = ($targetId && preg_match('/^[a-f0-9]{32}$/iD', $targetId)) ? strtolower($targetId) : bin2hex(random_bytes(16));
                        if ($destGroup === $id) {
                            throw new OCSBadRequestException('Zielgruppe muss sich von Quellgruppe unterscheiden');
                        }
                        $this->ensureGroup($uid, $destGroup);

                        foreach ($cleanDets as $did) {
                            $this->db->executeStatement(
                                'UPDATE *PREFIX*nextgallery_face_detections SET group_id = ?, manual = 1, updated_at = ? WHERE user_id = ? AND id = ? AND group_id = ?',
                                [$destGroup, $now, $uid, $did, $id]
                            );
                            // Idempotent constraint insertion
                            $chk = $this->db->getQueryBuilder();
                            $chk->select('id')->from('nextgallery_face_rules')
                                ->where($chk->expr()->eq('user_id', $chk->createNamedParameter($uid)))
                                ->andWhere($chk->expr()->eq('detection_id', $chk->createNamedParameter($did)))
                                ->andWhere($chk->expr()->eq('group_id', $chk->createNamedParameter($id)))
                                ->andWhere($chk->expr()->eq('kind', $chk->createNamedParameter('cannot_link')));
                            if (!$chk->executeQuery()->fetchOne()) {
                                $this->db->executeStatement(
                                    'INSERT INTO *PREFIX*nextgallery_face_rules (user_id, kind, detection_id, group_id, created_at) VALUES (?, ?, ?, ?, ?)',
                                    [$uid, 'cannot_link', $did, $id, $now]
                                );
                            }
                        }
                        if (!empty($groupRow['cover_id']) && in_array((string)$groupRow['cover_id'], $cleanDets, true)) {
                            $this->db->executeStatement('UPDATE *PREFIX*nextgallery_faces_groups SET cover_id = NULL WHERE user_id = ? AND id = ?', [$uid, $id]);
                        }
                        $rem = $this->db->executeQuery('SELECT COUNT(*) FROM *PREFIX*nextgallery_face_detections WHERE user_id = ? AND group_id = ?', [$uid, $id]);
                        if ((int)$rem->fetchOne() === 0) {
                            $this->db->executeStatement('DELETE FROM *PREFIX*nextgallery_faces_groups WHERE user_id = ? AND id = ?', [$uid, $id]);
                        }
                        $extraResponse['target_id'] = $destGroup;
                        $extraResponse['detection_ids'] = $cleanDets;
                        break;

                    case 'remove':
                        if ($cleanDets === null || empty($cleanDets)) {
                            throw new OCSBadRequestException('Detections für Entfernen erforderlich');
                        }
                        foreach ($cleanDets as $did) {
                            $singleton = bin2hex(random_bytes(16));
                            $this->ensureGroup($uid, $singleton);
                            $this->db->executeStatement(
                                'UPDATE *PREFIX*nextgallery_face_detections SET group_id = ?, manual = 1, updated_at = ? WHERE user_id = ? AND id = ? AND group_id = ?',
                                [$singleton, $now, $uid, $did, $id]
                            );
                            // Idempotent constraint insertion
                            $chk = $this->db->getQueryBuilder();
                            $chk->select('id')->from('nextgallery_face_rules')
                                ->where($chk->expr()->eq('user_id', $chk->createNamedParameter($uid)))
                                ->andWhere($chk->expr()->eq('detection_id', $chk->createNamedParameter($did)))
                                ->andWhere($chk->expr()->eq('group_id', $chk->createNamedParameter($id)))
                                ->andWhere($chk->expr()->eq('kind', $chk->createNamedParameter('cannot_link')));
                            if (!$chk->executeQuery()->fetchOne()) {
                                $this->db->executeStatement(
                                    'INSERT INTO *PREFIX*nextgallery_face_rules (user_id, kind, detection_id, group_id, created_at) VALUES (?, ?, ?, ?, ?)',
                                    [$uid, 'cannot_link', $did, $id, $now]
                                );
                            }
                        }
                        if (!empty($groupRow['cover_id']) && in_array((string)$groupRow['cover_id'], $cleanDets, true)) {
                            $this->db->executeStatement('UPDATE *PREFIX*nextgallery_faces_groups SET cover_id = NULL WHERE user_id = ? AND id = ?', [$uid, $id]);
                        }
                        $rem = $this->db->executeQuery('SELECT COUNT(*) FROM *PREFIX*nextgallery_face_detections WHERE user_id = ? AND group_id = ?', [$uid, $id]);
                        if ((int)$rem->fetchOne() === 0) {
                            $this->db->executeStatement('DELETE FROM *PREFIX*nextgallery_faces_groups WHERE user_id = ? AND id = ?', [$uid, $id]);
                        }
                        break;

                    case 'resolve':
                        if ($cleanDets !== null && !empty($cleanDets)) {
                            foreach ($cleanDets as $did) {
                                $this->db->executeStatement(
                                    'UPDATE *PREFIX*nextgallery_face_detections SET review = 0, manual = 1, updated_at = ? WHERE user_id = ? AND id = ?',
                                    [$now, $uid, $did]
                                );
                            }
                        } else {
                            $this->db->executeStatement(
                                'UPDATE *PREFIX*nextgallery_face_detections SET review = 0, manual = 1, updated_at = ? WHERE user_id = ? AND group_id = ?',
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
            } catch (\Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }
            $this->cleanupNativeSnapshots($uid);
            return ['success' => true, 'rules_revision' => $newRevision] + $extraResponse;
        } finally {
            $this->unlock($uid);
        }
    }

    /**
     * Merge one or more source groups into a target group within an active transaction.
     * Moves detections, remaps cannot-link constraints, updates suggestions/dismissals,
     * preserves target label/cover with source fallback, removes contradiction constraints,
     * and deletes source groups.
     *
     * @param string $uid
     * @param string[] $sourceIds Clean lowercased source group IDs
     * @param string $targetId Clean lowercased target group ID
     * @param int $now Current timestamp
     * @param array<string, array{label: ?string, cover_id: ?string}> $preloadedSources Optional cached rows by group ID
     * @return array{merged_detection_ids: string[], merged_groups: array<int, array{source_id: string, detection_ids: string[]}>, label: ?string, cover_id: ?string}
     */
    private function executeMerge(
        string $uid,
        array $sourceIds,
        string $targetId,
        int $now,
        array $preloadedSources = []
    ): array {
        // Read current target group metadata
        $tq = $this->db->executeQuery(
            'SELECT label, cover_id FROM *PREFIX*nextgallery_faces_groups WHERE user_id = ? AND id = ?',
            [$uid, $targetId]
        );
        $targetRow = $tq->fetch() ?: [];
        $tq->closeCursor();

        $mergedLabel = isset($targetRow['label']) && trim((string)$targetRow['label']) !== '' ? (string)$targetRow['label'] : null;
        $mergedCover = isset($targetRow['cover_id']) && trim((string)$targetRow['cover_id']) !== '' ? (string)$targetRow['cover_id'] : null;

        $allMergedDetectionIds = [];
        $mergedGroups = [];

        foreach ($sourceIds as $sourceId) {
            $srcRow = $preloadedSources[$sourceId] ?? null;
            if ($srcRow === null) {
                $sq = $this->db->executeQuery(
                    'SELECT label, cover_id FROM *PREFIX*nextgallery_faces_groups WHERE user_id = ? AND id = ?',
                    [$uid, $sourceId]
                );
                $srcRow = $sq->fetch() ?: [];
                $sq->closeCursor();
            }

            // Target label/cover preferred, source fallback (first non-empty source used)
            $srcLabel = isset($srcRow['label']) && trim((string)$srcRow['label']) !== '' ? (string)$srcRow['label'] : null;
            $srcCover = isset($srcRow['cover_id']) && trim((string)$srcRow['cover_id']) !== '' ? (string)$srcRow['cover_id'] : null;
            if ($mergedLabel === null && $srcLabel !== null) {
                $mergedLabel = $srcLabel;
            }
            if ($mergedCover === null && $srcCover !== null) {
                $mergedCover = $srcCover;
            }

            // Record source detection IDs before moving so caller can offer 1-click undo / group-wise split
            $srcDetRows = $this->db->executeQuery(
                'SELECT id FROM *PREFIX*nextgallery_face_detections WHERE user_id = ? AND group_id = ?',
                [$uid, $sourceId]
            );
            $srcDets = [];
            try {
                while ($dr = $srcDetRows->fetch()) {
                    $srcDets[] = (string)$dr['id'];
                }
            } finally {
                $srcDetRows->closeCursor();
            }

            $mergedGroups[] = [
                'source_id' => $sourceId,
                'detection_ids' => $srcDets,
            ];
            foreach ($srcDets as $did) {
                $allMergedDetectionIds[] = $did;
            }

            // Move detections from source group to target group
            $this->db->executeStatement(
                'UPDATE *PREFIX*nextgallery_face_detections SET group_id = ?, manual = 1, updated_at = ? WHERE user_id = ? AND group_id = ?',
                [$targetId, $now, $uid, $sourceId]
            );

            // Remap cannot-link constraints from old group to target group
            $cq = $this->db->getQueryBuilder();
            $cq->select('id', 'detection_id', 'kind')->from('nextgallery_face_rules')
               ->where($cq->expr()->eq('user_id', $cq->createNamedParameter($uid)))
               ->andWhere($cq->expr()->eq('group_id', $cq->createNamedParameter($sourceId)));
            $crows = $cq->executeQuery()->fetchAll();
            foreach ($crows as $crow) {
                // Idempotent remapping: check if target constraint already exists
                $chk = $this->db->getQueryBuilder();
                $chk->select('id')->from('nextgallery_face_rules')
                    ->where($chk->expr()->eq('user_id', $chk->createNamedParameter($uid)))
                    ->andWhere($chk->expr()->eq('detection_id', $chk->createNamedParameter($crow['detection_id'])))
                    ->andWhere($chk->expr()->eq('group_id', $chk->createNamedParameter($targetId)))
                    ->andWhere($chk->expr()->eq('kind', $chk->createNamedParameter($crow['kind'])));
                if ($chk->executeQuery()->fetchOne()) {
                    $this->db->executeStatement('DELETE FROM *PREFIX*nextgallery_face_rules WHERE id = ?', [$crow['id']]);
                } else {
                    $this->db->executeStatement(
                        'UPDATE *PREFIX*nextgallery_face_rules SET group_id = ? WHERE id = ?',
                        [$targetId, $crow['id']]
                    );
                }
            }

            // Remap suggestions and dismissed pairs
            $this->remapPairs($uid, $sourceId, $targetId, $now);

            // Delete old source group
            $this->db->executeStatement(
                'DELETE FROM *PREFIX*nextgallery_faces_groups WHERE user_id = ? AND id = ?',
                [$uid, $sourceId]
            );
        }

        // Apply merged label & cover to target group
        $this->db->executeStatement(
            'UPDATE *PREFIX*nextgallery_faces_groups SET label = ?, cover_id = ?, updated_at = ? WHERE user_id = ? AND id = ?',
            [$mergedLabel, $mergedCover, $now, $uid, $targetId]
        );

        // Remove explicit merge contradictions (cannot-link to own new group)
        $this->db->executeStatement(
            'DELETE FROM *PREFIX*nextgallery_face_rules WHERE user_id = ? AND group_id = ? AND detection_id IN (
                SELECT id FROM *PREFIX*nextgallery_face_detections WHERE user_id = ? AND group_id = ?
            )',
            [$uid, $targetId, $uid, $targetId]
        );

        return [
            'merged_detection_ids' => $allMergedDetectionIds,
            'merged_groups' => $mergedGroups,
            'label' => $mergedLabel,
            'cover_id' => $mergedCover,
        ];
    }

    /**
     * Apply one group-level flag action to many groups in a single transaction and rules revision,
     * or atomically merge multiple source groups into a target group.
     */
    public function bulkAction(string $uid, array $ids, string $action, int $rulesRevision = 0, ?string $targetId = null): array {
        $this->ensureSchema();
        $updates = [
            'stranger' => ['stranger' => 1, 'hidden' => 0],
            'unstranger' => ['stranger' => 0],
            'hide' => ['hidden' => 1],
            'show' => ['hidden' => 0],
        ];
        if ($action !== 'merge' && !isset($updates[$action])) {
            throw new OCSBadRequestException('Unbekannte Sammelaktion: ' . $action);
        }
        $clean = [];
        foreach ($ids as $gid) {
            if (!is_string($gid) || !preg_match('/^[a-f0-9]{32}$/iD', $gid)) {
                throw new OCSBadRequestException('Ungültige Subjekt-ID');
            }
            $clean[strtolower($gid)] = true;
        }
        $clean = array_keys($clean);
        if (!$clean) {
            throw new OCSBadRequestException('Keine Personen ausgewählt');
        }
        if (count($clean) > 500) {
            throw new OCSBadRequestException('Zu viele Personen (maximal 500)');
        }

        if ($action === 'merge') {
            if (!$targetId || !is_string($targetId) || !preg_match('/^[a-f0-9]{32}$/iD', $targetId)) {
                throw new OCSBadRequestException('Ziel-ID für Zusammenführung erforderlich');
            }
            $targetId = strtolower($targetId);
            if (in_array($targetId, $clean, true)) {
                throw new OCSBadRequestException('Zielgruppe darf nicht in den Quellgruppen enthalten sein');
            }

            $this->lock($uid);
            try {
                $acc = $this->accountState($uid);
                if ($rulesRevision !== $acc['rules_revision']) {
                    throw new FaceConflictException('Regelstand veraltet, bitte Ansicht aktualisieren');
                }

                // Target group must exist and belong to user
                $tq = $this->db->getQueryBuilder();
                $tq->select('id', 'label', 'cover_id')->from('nextgallery_faces_groups')
                   ->where($tq->expr()->eq('user_id', $tq->createNamedParameter($uid)))
                   ->andWhere($tq->expr()->eq('id', $tq->createNamedParameter($targetId)));
                $targetRow = $tq->executeQuery()->fetch();
                if (!$targetRow) {
                    throw new OCSNotFoundException('Gruppe nicht gefunden');
                }

                // All source groups must exist and belong to user
                $sq = $this->db->getQueryBuilder();
                $sq->select('id', 'label', 'cover_id')->from('nextgallery_faces_groups')
                   ->where($sq->expr()->eq('user_id', $sq->createNamedParameter($uid)))
                   ->andWhere($sq->expr()->in('id', $sq->createNamedParameter($clean, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)));
                $srcRows = $sq->executeQuery()->fetchAll();
                if (count($srcRows) !== count($clean)) {
                    throw new OCSNotFoundException('Gruppe nicht gefunden');
                }
                $preloaded = [];
                foreach ($srcRows as $sr) {
                    $preloaded[(string)$sr['id']] = $sr;
                }

                $this->db->beginTransaction();
                try {
                    $now = time();
                    $mergeResult = $this->executeMerge($uid, $clean, $targetId, $now, $preloaded);

                    $newRevision = $acc['rules_revision'] + 1;
                    $this->db->executeStatement(
                        'UPDATE *PREFIX*nextgallery_faces_account SET rules_revision = ?, updated_at = ? WHERE user_id = ?',
                        [$newRevision, $now, $uid]
                    );
                    $this->db->commit();
                } catch (\Throwable $e) {
                    $this->db->rollBack();
                    throw $e;
                }
                $this->cleanupNativeSnapshots($uid);
                return [
                    'success' => true,
                    'rules_revision' => $newRevision,
                    'target_id' => $targetId,
                    'ids' => $clean,
                    'merged_detection_ids' => $mergeResult['merged_detection_ids'],
                    'merged_groups' => $mergeResult['merged_groups'],
                ];
            } finally {
                $this->unlock($uid);
            }
        }

        // Flag actions (stranger, unstranger, hide, show)
        $this->lock($uid);
        try {
            $acc = $this->accountState($uid);
            if ($rulesRevision !== $acc['rules_revision']) {
                throw new FaceConflictException('Regelstand veraltet, bitte Ansicht aktualisieren');
            }
            $q = $this->db->getQueryBuilder();
            $q->select('id')->from('nextgallery_faces_groups')
              ->where($q->expr()->eq('user_id', $q->createNamedParameter($uid)))
              ->andWhere($q->expr()->in('id', $q->createNamedParameter($clean, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)));
            $found = $q->executeQuery()->fetchAll(\PDO::FETCH_COLUMN);
            if (count($found) !== count($clean)) {
                throw new OCSNotFoundException('Gruppe nicht gefunden');
            }

            $this->db->beginTransaction();
            try {
                $now = time();
                $u = $this->db->getQueryBuilder();
                $u->update('nextgallery_faces_groups');
                foreach ($updates[$action] as $column => $value) {
                    $u->set($column, $u->createNamedParameter($value, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT));
                }
                $u->set('updated_at', $u->createNamedParameter($now, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                  ->where($u->expr()->eq('user_id', $u->createNamedParameter($uid)))
                  ->andWhere($u->expr()->in('id', $u->createNamedParameter($clean, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)));
                $u->executeStatement();

                $newRevision = $acc['rules_revision'] + 1;
                $this->db->executeStatement(
                    'UPDATE *PREFIX*nextgallery_faces_account SET rules_revision = ?, updated_at = ? WHERE user_id = ?',
                    [$newRevision, $now, $uid]
                );
                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }
            $this->cleanupNativeSnapshots($uid);
            return ['success' => true, 'rules_revision' => $newRevision, 'action' => $action, 'ids' => $clean];
        } finally {
            $this->unlock($uid);
        }
    }

    /**
     * Live access, current path and file version within the requested gallery roots.
     * Without a home folder the scope cannot be proven, so nothing is returned.
     */
    public function authorizedFile(string $uid, int $fileId, string $etag, array $roots): ?array {
        if ($this->rootFolder === null) {
            return null;
        }
        try {
            $node = $this->library->file($uid, $fileId);
            $path = '/' . ltrim($this->rootFolder->getUserFolder($uid)->getRelativePath($node->getPath()), '/');
            if (!hash_equals($etag, $node->getEtag()) || !self::inRoots($path, $roots)) {
                return null;
            }
            return ['file' => $node, 'path' => $path];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Detections of the account within the gallery index scope (no vectors, no live checks).
     * Counts follow the gallery index like the timeline; returned media and covers are
     * revalidated live, so a stale index row can never expose a file or face crop.
     */
    public function scopeRoots(string $uid, string $gallery = '', string $scope = 'gallery'): array {
        if (!in_array($scope, ['gallery', 'account'], true)) throw new OCSBadRequestException('Ungültiger Personenbereich');
        $settings = $this->library->settings($uid, $gallery);
        if ($scope === 'gallery') return $settings['roots'] ?? [];
        $roots = [];
        foreach ($settings['galleries'] as $entry) {
            foreach ($entry['roots'] ?? [] as $path) $roots[] = $path;
        }
        return array_values(array_unique($roots));
    }

    private function scopedDetections(string $uid, string $gallery, ?string $groupId = null, string $scope = 'gallery'): array {
        $q = $this->db->getQueryBuilder();
        $q->select('d.id', 'd.group_id', 'd.file_id', 'd.bbox_x', 'd.bbox_y', 'd.bbox_w', 'd.bbox_h',
                   'd.score', 'd.review', 'd.manual', 'd.sharpness', 'ff.etag AS face_etag', 'm.sort_key')
          ->from('nextgallery_face_detections', 'd')
          ->innerJoin('d', 'nextgallery_faces_files', 'ff', 'ff.user_id = d.user_id AND ff.file_id = d.file_id')
          ->innerJoin('d', 'nextgallery_media', 'm', 'm.user_id = d.user_id AND m.file_id = d.file_id AND m.etag = ff.etag')
          ->where($q->expr()->eq('d.user_id', $q->createNamedParameter($uid)));
        if ($groupId !== null) {
            $q->andWhere($q->expr()->eq('d.group_id', $q->createNamedParameter($groupId)));
        }
        $paths = [];
        foreach ($this->scopeRoots($uid, $gallery, $scope) as $path) {
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(rtrim($path, '/') . '/'));
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

    /** Per-group detections for one overview filter, from scoped index rows. */
    private function groupStats(array $detections, array $qualityFilters): array {
        $stats = [];
        foreach ($detections as $d) {
            $gid = (string)$d['group_id'];
            $stats[$gid] ??= ['good' => [], 'weak' => []];
            $stats[$gid][self::qualityEligible($d, $qualityFilters) ? 'good' : 'weak'][] = $d;
        }
        return $stats;
    }

    private static function photoCount(array $detections): int {
        $files = [];
        foreach ($detections as $d) $files[(int)$d['file_id']] = true;
        return count($files);
    }

    public function subjects(
        string $uid,
        string $gallery = '',
        int $cursor = 0,
        int $limit = 60,
        string $filter = 'all',
        string $scope = 'gallery',
        string $query = ''
    ): array {
        $this->ensureSchema();
        $limit = max(1, min(100, $limit));
        $cursor = max(0, $cursor);
        $acc = $this->accountState($uid);
        $config = $this->settings->forUser($uid)->get();
        $minGroupSize = (int)$config['faces_filters']['min_group_photos'];
        $qualityFilters = $config['faces_filters'];
        $galleryRoots = $this->scopeRoots($uid, $gallery, $scope);

        $gq = $this->db->getQueryBuilder();
        $gq->select('id', 'hidden', 'stranger', 'label', 'cover_id', 'updated_at')
           ->from('nextgallery_faces_groups')
           ->where($gq->expr()->eq('user_id', $gq->createNamedParameter($uid)))
           ->orderBy('updated_at', 'DESC')
           ->addOrderBy('id', 'ASC');
        $groups = $gq->executeQuery()->fetchAll();

        $stats = $groups ? $this->groupStats($this->scopedDetections($uid, $gallery, null, $scope), $qualityFilters) : [];
        $open = $groups ? $this->openSuggestions($uid) : [];
        $suggestionRoots = $this->scopeRoots($uid, $gallery, 'account');
        $suggestionStats = $open ? ($scope === 'account' ? $stats : $this->groupStats($this->scopedDetections($uid, $gallery, null, 'account'), $qualityFilters)) : [];
        $suggestionAccess = [];
        $targetReadable = [];
        $targetAvailable = function ($other, int $minGood = 1) use ($uid, $suggestionStats, $suggestionRoots, &$suggestionAccess, &$targetReadable): bool {
            $good = $suggestionStats[$other]['good'] ?? [];
            if (count($good) < $minGood) return false;
            if (!array_key_exists($other, $targetReadable)) $targetReadable[$other] = $this->readableDetectionDetail($uid, $good, $suggestionRoots, $suggestionAccess) !== null;
            return $targetReadable[$other];
        };
        $hiddenGroups = [];
        $strangerGroups = [];
        foreach ($groups as $g) {
            if ((int)$g['hidden'] === 1) $hiddenGroups[(string)$g['id']] = true;
            if ((int)($g['stranger'] ?? 0) === 1) $strangerGroups[(string)$g['id']] = true;
        }

        $filterCounts = [
            'all' => 0,
            'suggested' => 0,
            'strangers' => 0,
            'single' => 0,
            'review' => 0,
            'filtered' => 0,
            'hidden' => 0,
        ];

        // Quality and rarity are presentation choices, never data deletion.
        $filtered = [];
        foreach ($groups as $g) {
            $gid = (string)$g['id'];
            $good = $stats[$gid]['good'] ?? [];
            $weak = $stats[$gid]['weak'] ?? [];
            $allDets = array_merge($good, $weak);
            $goodCount = self::photoCount($good);
            $weakCount = self::photoCount($weak);
            $allCount = self::photoCount($allDets);
            $hidden = isset($hiddenGroups[$gid]);
            $stranger = isset($strangerGroups[$gid]);
            $rawLabel = isset($g['label']) && trim((string)$g['label']) !== '' ? trim((string)$g['label']) : null;
            $preferredCover = isset($g['cover_id']) && trim((string)$g['cover_id']) !== '' ? strtolower(trim((string)$g['cover_id'])) : null;

            $hasOpenSuggestion = !$hidden && !$stranger && $goodCount > 0 && !empty(array_filter(
                array_keys($open[$gid] ?? []),
                fn($other) => !isset($hiddenGroups[$other]) && !isset($strangerGroups[$other]) && $targetAvailable($other)
            ));

            // Mirrors similar(): what the detail view would offer for this group in this list.
            $weakSource = !$good && $weak;
            $offersSuggestions = !$hidden && !$stranger && match ($filter) {
                'filtered' => $weakSource && $rawLabel === null,
                'all', 'single', 'suggested' => true,
                default => false,
            };
            $suggestionCount = 0;
            $bestSimilarity = 0.0;
            if ($offersSuggestions) {
                $minGood = $weakSource ? self::STRONG_GROUP_FACES : 1;
                foreach ($open[$gid] ?? [] as $other => $similarity) {
                    $other = (string)$other;
                    if (isset($hiddenGroups[$other]) || isset($strangerGroups[$other]) || !$targetAvailable($other, $minGood)) continue;
                    $suggestionCount++;
                    $bestSimilarity = max($bestSimilarity, (float)$similarity);
                }
            }

            if ($hidden && $allCount > 0) $filterCounts['hidden']++;
            if (!$hidden && !$stranger && $weakCount > 0) $filterCounts['filtered']++;
            if (!$hidden && !$stranger && $allCount > 0 && count(array_filter($allDets, fn($d) => (int)$d['review'] === 1)) > 0) $filterCounts['review']++;
            if (!$hidden && $stranger && $allCount > 0) $filterCounts['strangers']++;
            if (!$hidden && !$stranger && $goodCount > 0 && $goodCount < $minGroupSize && $rawLabel === null) $filterCounts['single']++;
            if ($hasOpenSuggestion) $filterCounts['suggested']++;
            if (!$hidden && !$stranger && $goodCount > 0 && ($goodCount >= $minGroupSize || $rawLabel !== null)) $filterCounts['all']++;

            $dets = $filter === 'filtered' ? $weak : (in_array($filter, ['review', 'hidden', 'strangers'], true) ? $allDets : $good);
            $photoCount = self::photoCount($dets);
            if (!$photoCount) continue;
            $reviewCount = count(array_filter($dets, fn($d) => (int)$d['review'] === 1));
            $match = match ($filter) {
                'filtered' => !$hidden && !$stranger,
                'hidden' => $hidden,
                'strangers' => !$hidden && $stranger,
                'review' => !$hidden && !$stranger && $reviewCount > 0,
                'single' => !$hidden && !$stranger && $photoCount < $minGroupSize && $rawLabel === null,
                'suggested' => $hasOpenSuggestion,
                default => !$hidden && !$stranger && ($photoCount >= $minGroupSize || $rawLabel !== null),
            };
            if (!$match) continue;
            if (trim($query) !== '' && !str_contains(mb_strtolower($rawLabel ?? ''), mb_strtolower(trim($query)))) continue;
            usort($dets, fn($a, $b) => (float)$b['score'] <=> (float)$a['score']);
            $filtered[] = [
                'id' => $gid,
                'label' => $rawLabel,
                'photo_count' => $photoCount,
                'dets' => $dets,
                'preferred_cover' => $preferredCover,
                'hidden' => $hidden,
                'stranger' => $stranger,
                'review_count' => $reviewCount,
                'filtered' => $filter === 'filtered',
                'filtered_count' => count($weak),
                'good_count' => $goodCount,
                'suggestion_count' => $suggestionCount,
                'best_similarity' => round($bestSimilarity, 3),
            ];
        }

        // Lists that are worked through put people with open suggestions first, most similar first.
        if (in_array($filter, ['filtered', 'single', 'suggested'], true)) {
            $position = array_flip(array_column($filtered, 'id'));
            usort($filtered, fn($a, $b) => [$b['suggestion_count'] > 0, $b['best_similarity'], $position[$a['id']]]
                <=> [$a['suggestion_count'] > 0, $a['best_similarity'], $position[$b['id']]]);
        }

        $total = count($filtered);
        $slice = array_slice($filtered, $cursor, $limit);
        $items = [];
        $access = [];
        foreach ($slice as $entry) {
            // Only the returned page is checked live; the cover must be readable now.
            $coverInfo = $this->readableDetectionDetail($uid, $entry['dets'], $galleryRoots, $access, $entry['preferred_cover'] ?? null);
            if ($coverInfo === null) continue;
            unset($entry['dets'], $entry['preferred_cover']);
            $items[] = [
                'scope' => $scope,
                'cover_id' => $coverInfo['id'],
                'cover_file_id' => $coverInfo['file_id'],
                'cover_box' => $coverInfo['box'],
            ] + $entry;
        }
        $more = ($cursor + count($slice)) < $total;

        return [
            'items' => $items,
            'next' => $more ? $cursor + count($slice) : null,
            'more' => $more,
            'filter_counts' => $filterCounts,
            'epoch' => $acc['epoch'],
            'generation' => $acc['generation'],
            'rules_revision' => $acc['rules_revision'],
            'enabled' => (bool)($config['faces']['enabled'] ?? false),
        ];
    }

    /** Best-scoring (or preferred) detection whose file is readable now; bounded number of live checks. */
    private function readableDetectionDetail(
        string $uid,
        array $detections,
        array $roots,
        array &$access,
        ?string $preferredCoverId = null,
        int $attempts = 12
    ): ?array {
        if ($preferredCoverId !== null && $preferredCoverId !== '') {
            $preferred = [];
            $rest = [];
            foreach ($detections as $d) {
                if ((string)$d['id'] === $preferredCoverId) {
                    $preferred[] = $d;
                } else {
                    $rest[] = $d;
                }
            }
            if ($preferred) {
                $detections = array_merge($preferred, $rest);
            }
        }
        foreach (array_slice($detections, 0, $attempts) as $d) {
            $key = (int)$d['file_id'] . '|' . $d['face_etag'];
            $access[$key] ??= $this->authorizedFile($uid, (int)$d['file_id'], (string)$d['face_etag'], $roots) !== null;
            if ($access[$key]) {
                return [
                    'id' => (string)$d['id'],
                    'file_id' => (int)$d['file_id'],
                    'box' => [(float)$d['bbox_x'], (float)$d['bbox_y'], (float)$d['bbox_w'], (float)$d['bbox_h']],
                ];
            }
        }
        return null;
    }

    /** Best-scoring detection whose file is readable now; bounded number of live checks. */
    private function readableDetection(string $uid, array $detections, array $roots, array &$access, int $attempts = 12): ?string {
        $info = $this->readableDetectionDetail($uid, $detections, $roots, $access, null, $attempts);
        return $info['id'] ?? null;
    }

    public function media(
        string $uid,
        string $groupId,
        string $gallery = '',
        int $cursor = 0,
        int $limit = 60,
        string $filter = 'all',
        string $scope = 'gallery'
    ): array {
        $this->ensureSchema();
        if (!preg_match('/^[a-f0-9]{32}$/iD', $groupId)) {
            throw new OCSBadRequestException('Ungültige Subjekt-ID');
        }
        $groupId = strtolower($groupId);
        $limit = max(1, min(100, $limit));
        $cursor = max(0, $cursor);
        $acc = $this->accountState($uid);
        $galleryRoots = $this->scopeRoots($uid, $gallery, $scope);
        $qualityFilters = $this->settings->forUser($uid)->get()['faces_filters'];

        $gq = $this->db->executeQuery(
            'SELECT label, cover_id, stranger, hidden FROM *PREFIX*nextgallery_faces_groups WHERE user_id = ? AND id = ?',
            [$uid, $groupId]
        );
        $gRow = $gq->fetch() ?: [];
        $gq->closeCursor();
        $groupLabel = isset($gRow['label']) && trim((string)$gRow['label']) !== '' ? trim((string)$gRow['label']) : null;
        $groupCoverId = isset($gRow['cover_id']) && trim((string)$gRow['cover_id']) !== '' ? strtolower(trim((string)$gRow['cover_id'])) : null;
        $groupStranger = (int)($gRow['stranger'] ?? 0) === 1;
        $groupHidden = (int)($gRow['hidden'] ?? 0) === 1;

        // Order and paginate files from the index; only the returned page is checked live.
        $rows = $this->scopedDetections($uid, $gallery, $groupId, $scope);
        usort($rows, fn($a, $b) => [(string)$b['sort_key'], (int)$b['file_id']] <=> [(string)$a['sort_key'], (int)$a['file_id']]);
        $files = [];
        foreach ($rows as $row) {
            $eligible = self::qualityEligible($row, $qualityFilters);
            if ($filter === 'filtered' ? $eligible : (!in_array($filter, ['review', 'hidden', 'strangers'], true) && !$eligible)) continue;
            $files[(int)$row['file_id']][] = $row;
        }
        $total = count($files);
        $slice = array_slice($files, $cursor, $limit, true);

        $items = [];
        if ($slice) {
            $q = $this->db->getQueryBuilder();
            $q->select('file_id', 'payload')->from('nextgallery_media')
              ->where($q->expr()->eq('user_id', $q->createNamedParameter($uid)))
              ->andWhere($q->expr()->in('file_id', $q->createNamedParameter(array_keys($slice), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
            $payloads = [];
            foreach ($q->executeQuery()->fetchAll() as $row) $payloads[(int)$row['file_id']] = (string)$row['payload'];
            foreach ($slice as $fid => $dets) {
                $live = $this->authorizedFile($uid, $fid, (string)$dets[0]['face_etag'], $galleryRoots);
                $payload = json_decode($payloads[$fid] ?? '', true);
                if ($live === null || !is_array($payload) || ($payload['path'] ?? '') !== $live['path']) continue;
                $payload['mtime'] = $live['file']->getMTime();
                $payload['size'] = $live['file']->getSize();
                $payload['faces'] = [];
                $payload['face_detection_ids'] = [];
                foreach ($dets as $d) {
                    // Attach face detection (NO vectors exposed)
                    $payload['face_detection_ids'][] = (string)$d['id'];
                    $payload['faces'][] = [
                        'id' => (string)$d['id'],
                        'box' => [(float)$d['bbox_x'], (float)$d['bbox_y'], (float)$d['bbox_w'], (float)$d['bbox_h']],
                        'score' => (float)$d['score'],
                        'review' => (bool)$d['review'],
                        'is_cover' => $groupCoverId !== null && (string)$d['id'] === $groupCoverId,
                    ];
                }
                $items[] = $payload;
            }
        }
        $more = ($cursor + count($slice)) < $total;

        return [
            'items' => $items,
            'next' => $more ? $cursor + count($slice) : null,
            'more' => $more,
            'rules_revision' => $acc['rules_revision'],
            'subject' => [
                'id' => $groupId,
                'label' => $groupLabel,
                'cover_id' => $groupCoverId,
                'stranger' => $groupStranger,
                'hidden' => $groupHidden,
            ],
        ];
    }

    /** Pairs are stored in a canonical order so a decision covers both directions. */
    private static function pair(string $a, string $b): array {
        return strcmp($a, $b) < 0 ? [$a, $b] : [$b, $a];
    }

    /**
     * Replace the account's merge suggestions with a worker result. Only the epoch and
     * generation must match: suggestions for groups merged since are filtered on read.
     */
    public function publishSuggestions(string $uid, string $epoch, int $generation, array $pairs): int {
        if (!array_is_list($pairs) || count($pairs) > self::SUGGESTION_LIMIT) {
            throw new OCSBadRequestException('Ungültige Vorschlagsliste');
        }
        $clean = [];
        foreach ($pairs as $p) {
            $a = is_array($p) ? strtolower((string)($p['a'] ?? '')) : '';
            $b = is_array($p) ? strtolower((string)($p['b'] ?? '')) : '';
            $similarity = is_array($p) ? ($p['similarity'] ?? null) : null;
            if (!preg_match('/^[a-f0-9]{32}$/D', $a) || !preg_match('/^[a-f0-9]{32}$/D', $b) || $a === $b
                || (!is_int($similarity) && !is_float($similarity)) || !is_finite((float)$similarity) || $similarity < -1 || $similarity > 1) {
                throw new OCSBadRequestException('Ungültiger Vorschlag');
            }
            $key = implode('|', self::pair($a, $b));
            $clean[$key] = max((float)$similarity, $clean[$key] ?? -1.0);
        }

        $this->lock($uid);
        try {
            $acc = $this->accountState($uid);
            if ($epoch !== $acc['epoch'] || $generation !== $acc['generation']) {
                throw new FaceConflictException('Vorschläge gehören zu einem zurückgesetzten Stand');
            }
            $groups = [];
            $r = $this->db->executeQuery('SELECT id FROM *PREFIX*nextgallery_faces_groups WHERE user_id = ?', [$uid]);
            try {
                while ($row = $r->fetch()) $groups[(string)$row['id']] = true;
            } finally {
                $r->closeCursor();
            }
            $now = time();
            $stored = 0;
            $this->db->beginTransaction();
            try {
                $this->db->executeStatement('DELETE FROM *PREFIX*nextgallery_face_suggest WHERE user_id = ?', [$uid]);
                foreach ($clean as $key => $similarity) {
                    [$a, $b] = explode('|', $key);
                    if (!isset($groups[$a], $groups[$b])) continue;
                    $this->db->executeStatement(
                        'INSERT INTO *PREFIX*nextgallery_face_suggest (user_id, group_a, group_b, similarity, created_at) VALUES (?, ?, ?, ?, ?)',
                        [$uid, $a, $b, $similarity, $now]
                    );
                    $stored++;
                }
                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }
            return $stored;
        } finally {
            $this->unlock($uid);
        }
    }

    /** [groupId => [otherId => similarity]] without pairs the account marked as different people. */
    private function openSuggestions(string $uid): array {
        $dismissed = [];
        $r = $this->db->executeQuery('SELECT group_a, group_b FROM *PREFIX*nextgallery_faces_dismissed WHERE user_id = ?', [$uid]);
        try {
            while ($row = $r->fetch()) $dismissed[implode('|', self::pair((string)$row['group_a'], (string)$row['group_b']))] = true;
        } finally {
            $r->closeCursor();
        }
        // A split or removal already says these photos are not that person.
        $r = $this->db->executeQuery(
            'SELECT d.group_id AS group_a, c.group_id AS group_b FROM *PREFIX*nextgallery_face_rules c
             INNER JOIN *PREFIX*nextgallery_face_detections d ON d.user_id = c.user_id AND d.id = c.detection_id
             WHERE c.user_id = ? AND c.kind = ?',
            [$uid, 'cannot_link']
        );
        try {
            while ($row = $r->fetch()) $dismissed[implode('|', self::pair((string)$row['group_a'], (string)$row['group_b']))] = true;
        } finally {
            $r->closeCursor();
        }
        $open = [];
        $r = $this->db->executeQuery('SELECT group_a, group_b, similarity FROM *PREFIX*nextgallery_face_suggest WHERE user_id = ?', [$uid]);
        try {
            while ($row = $r->fetch()) {
                [$a, $b] = self::pair((string)$row['group_a'], (string)$row['group_b']);
                if ($a === $b || isset($dismissed[$a . '|' . $b])) continue;
                $similarity = (float)$row['similarity'];
                $open[$a][$b] = max($similarity, $open[$a][$b] ?? -1.0);
                $open[$b][$a] = $open[$a][$b];
            }
        } finally {
            $r->closeCursor();
        }
        return $open;
    }

    /**
     * Groups that may show the same person, most similar first; same scope rules as subjects().
     * A group with only filtered faces never anchors: it is only offered strong groups.
     */
    public function similar(string $uid, string $groupId, string $gallery = '', int $limit = 8, string $scope = 'gallery'): array {
        $this->ensureSchema();
        if (!preg_match('/^[a-f0-9]{32}$/iD', $groupId)) {
            throw new OCSBadRequestException('Ungültige Subjekt-ID');
        }
        $groupId = strtolower($groupId);
        $acc = $this->accountState($uid);
        $config = $this->settings->forUser($uid)->get();
        $galleryRoots = $this->scopeRoots($uid, $gallery, $scope);
        $sourceRoots = $this->scopeRoots($uid, $gallery);
        $stats = $this->groupStats($this->scopedDetections($uid, $gallery), $config['faces_filters']);
        $targetStats = $scope === 'account' ? $this->groupStats($this->scopedDetections($uid, $gallery, null, $scope), $config['faces_filters']) : $stats;
        $sourceAccess = [];
        $sourceDetections = array_merge($stats[$groupId]['good'] ?? [], $stats[$groupId]['weak'] ?? []);
        $sourceReadable = $scope !== 'account' || $this->readableDetectionDetail($uid, $sourceDetections, $sourceRoots, $sourceAccess) !== null;
        $candidates = $this->openSuggestions($uid)[$groupId] ?? [];
        $items = [];
        $groupMeta = [];
        $r = $this->db->executeQuery('SELECT id, hidden, stranger, label, cover_id FROM *PREFIX*nextgallery_faces_groups WHERE user_id = ?', [$uid]);
        try {
            while ($row = $r->fetch()) {
                $groupMeta[(string)$row['id']] = [
                    'hidden' => (int)$row['hidden'] === 1,
                    'stranger' => (int)($row['stranger'] ?? 0) === 1,
                    'label' => isset($row['label']) && trim((string)$row['label']) !== '' ? trim((string)$row['label']) : null,
                    'cover_id' => isset($row['cover_id']) && trim((string)$row['cover_id']) !== '' ? strtolower(trim((string)$row['cover_id'])) : null,
                ];
            }
        } finally {
            $r->closeCursor();
        }
        $weakSource = !($stats[$groupId]['good'] ?? []) && ($stats[$groupId]['weak'] ?? []);
        $minGood = $weakSource ? self::STRONG_GROUP_FACES : 1;
        if ($candidates && $sourceReadable) {
            arsort($candidates);
            $access = [];
            foreach ($candidates as $other => $similarity) {
                if (count($items) >= $limit) break;
                $other = (string)$other;
                $meta = $groupMeta[$other] ?? ['hidden' => true, 'stranger' => false, 'label' => null, 'cover_id' => null];
                $good = $targetStats[$other]['good'] ?? [];
                if ($meta['hidden'] || $meta['stranger'] || count($good) < $minGood) continue;
                $coverInfo = $this->readableDetectionDetail($uid, $good, $galleryRoots, $access, $meta['cover_id']);
                if ($coverInfo === null) continue;
                $items[] = [
                    'scope' => $scope,
                    'id' => $other,
                    'label' => $meta['label'],
                    'photo_count' => self::photoCount($good),
                    'cover_id' => $coverInfo['id'],
                    'cover_file_id' => $coverInfo['file_id'],
                    'cover_box' => $coverInfo['box'],
                    'similarity' => round($similarity, 3),
                ];
            }
        }
        $selfMeta = $groupMeta[$groupId] ?? ['label' => null, 'cover_id' => null, 'stranger' => false, 'hidden' => false];
        return [
            'items' => $items,
            'rules_revision' => $acc['rules_revision'],
            // Current main-view count, so a client can refresh it after merging a suggestion.
            'subject' => [
                'id' => $groupId,
                'label' => $selfMeta['label'],
                'photo_count' => self::photoCount($stats[$groupId]['good'] ?? []),
                'filtered_count' => self::photoCount($stats[$groupId]['weak'] ?? []),
            ],
        ];
    }

    /**
     * Check whether this account shares its person sorting and discover co-users
     * who share sorting on overlapping files in the current gallery scope.
     */
    public function sharedSortings(string $uid, string $gallery = ''): array {
        $this->ensureSchema();
        $acc = $this->accountState($uid);
        $r = $this->db->executeQuery('SELECT share_sorting FROM *PREFIX*nextgallery_faces_account WHERE user_id = ?', [$uid]);
        $sharingEnabled = (int)($r->fetchOne() ?: 0) === 1;
        $r->closeCursor();

        $galleryRoots = $this->library->settings($uid, $gallery)['roots'] ?? [];

        // Scoped files in the caller's gallery index
        $mq = $this->db->getQueryBuilder();
        $mq->select('m.file_id', 'm.etag')
           ->from('nextgallery_media', 'm')
           ->where($mq->expr()->eq('m.user_id', $mq->createNamedParameter($uid)));
        $this->library->scopeQuery($mq, $uid, 'm.path_text', $gallery);
        $mr = $mq->executeQuery();
        $myFiles = [];
        try {
            while ($row = $mr->fetch()) {
                $myFiles[(int)$row['file_id']] = (string)$row['etag'];
            }
        } finally {
            $mr->closeCursor();
        }

        // Find other accounts that opted into sharing sorting
        $aq = $this->db->executeQuery(
            'SELECT user_id, updated_at FROM *PREFIX*nextgallery_faces_account WHERE share_sorting = 1 AND user_id != ?',
            [$uid]
        );
        $sharingUsers = [];
        try {
            while ($row = $aq->fetch()) {
                $sharingUsers[(string)$row['user_id']] = (int)$row['updated_at'];
            }
        } finally {
            $aq->closeCursor();
        }

        $available = [];
        if ($myFiles && $sharingUsers) {
            $accessCache = [];
            foreach ($sharingUsers as $otherUid => $updatedAt) {
                $dq = $this->db->executeQuery(
                    'SELECT d.file_id, d.etag, d.group_id, g.label, g.hidden, g.stranger, g.updated_at AS group_updated
                     FROM *PREFIX*nextgallery_face_detections d
                     INNER JOIN *PREFIX*nextgallery_faces_groups g ON g.user_id = d.user_id AND g.id = d.group_id
                     WHERE d.user_id = ?',
                    [$otherUid]
                );
                $sharedFiles = [];
                $groups = [];
                $namedGroups = [];
                $strangerGroups = [];
                $latestUpdate = $updatedAt;
                try {
                    while ($d = $dq->fetch()) {
                        $fid = (int)$d['file_id'];
                        $etag = (string)$d['etag'];
                        if (!isset($myFiles[$fid]) || !hash_equals($myFiles[$fid], $etag)) continue;
                        $aKey = $fid . '|' . $etag;
                        $accessCache[$aKey] ??= $this->authorizedFile($uid, $fid, $etag, $galleryRoots) !== null;
                        if (!$accessCache[$aKey]) continue;

                        $sharedFiles[$fid] = true;
                        $gid = (string)$d['group_id'];
                        if ((int)$d['hidden'] === 0) {
                            $groups[$gid] = true;
                        }
                        if ((int)($d['stranger'] ?? 0) === 1) {
                            $strangerGroups[$gid] = true;
                        }
                        $lbl = isset($d['label']) && trim((string)$d['label']) !== '' ? trim((string)$d['label']) : null;
                        if ($lbl !== null) {
                            $namedGroups[$gid] = $lbl;
                        }
                        $gu = (int)($d['group_updated'] ?? 0);
                        if ($gu > $latestUpdate) $latestUpdate = $gu;
                    }
                } finally {
                    $dq->closeCursor();
                }

                if (!$sharedFiles) continue;
                $displayName = $otherUid;
                if ($this->userManager !== null) {
                    try {
                        $uObj = $this->userManager->get($otherUid);
                        if ($uObj !== null && trim($uObj->getDisplayName()) !== '') {
                            $displayName = $uObj->getDisplayName();
                        }
                    } catch (\Throwable) {}
                }
                $available[] = [
                    'user_id' => $otherUid,
                    'display_name' => $displayName,
                    'shared_files' => count($sharedFiles),
                    'people_count' => count($groups),
                    'named_count' => count($namedGroups),
                    'stranger_count' => count($strangerGroups),
                    'sample_labels' => array_slice(array_values($namedGroups), 0, 6),
                    'updated_at' => $latestUpdate,
                ];
            }
        }

        return [
            'sharing_enabled' => $sharingEnabled,
            'available' => $available,
            'rules_revision' => $acc['rules_revision'],
        ];
    }

    /**
     * Enable or disable sharing this account's person sorting with co-users who have access to the same files.
     */
    public function setSharedSorting(string $uid, bool $enabled): array {
        $this->ensureSchema();
        $acc = $this->accountState($uid);
        $now = time();
        $this->db->executeStatement(
            'UPDATE *PREFIX*nextgallery_faces_account SET share_sorting = ?, updated_at = ? WHERE user_id = ?',
            [$enabled ? 1 : 0, $now, $uid]
        );
        return [
            'sharing_enabled' => $enabled,
            'rules_revision' => $acc['rules_revision'],
        ];
    }

    /**
     * Adopt person grouping, merges, names, covers, and unknown-person classifications
     * from a co-user who opted into sharing sorting, restricted strictly to files in the
     * caller's gallery scope that the caller has live read access to.
     */
    public function adoptSharedSorting(string $uid, string $sourceUid, string $gallery = ''): array {
        $this->ensureSchema();
        $sourceUid = trim($sourceUid);
        if ($sourceUid === '' || $sourceUid === $uid) {
            throw new OCSBadRequestException('Ungültiger Quellbenutzer für Sortierungsübernahme');
        }

        $r = $this->db->executeQuery('SELECT share_sorting FROM *PREFIX*nextgallery_faces_account WHERE user_id = ?', [$sourceUid]);
        $isShared = (int)($r->fetchOne() ?: 0) === 1;
        $r->closeCursor();
        if (!$isShared) {
            throw new OCSNotFoundException('Dieser Benutzer hat keine Sortierung freigegeben');
        }

        $galleryRoots = $this->library->settings($uid, $gallery)['roots'] ?? [];
        $mq = $this->db->getQueryBuilder();
        $mq->select('m.file_id', 'm.etag')
           ->from('nextgallery_media', 'm')
           ->where($mq->expr()->eq('m.user_id', $mq->createNamedParameter($uid)));
        $this->library->scopeQuery($mq, $uid, 'm.path_text', $gallery);
        $mr = $mq->executeQuery();
        $myFiles = [];
        try {
            while ($row = $mr->fetch()) {
                $myFiles[(int)$row['file_id']] = (string)$row['etag'];
            }
        } finally {
            $mr->closeCursor();
        }
        if (!$myFiles) {
            throw new OCSBadRequestException('Keine gemeinsamen Dateien in dieser Galerie gefunden');
        }

        // Load source user's groups and detections
        $sgRows = $this->db->executeQuery(
            'SELECT id, hidden, stranger, label, cover_id FROM *PREFIX*nextgallery_faces_groups WHERE user_id = ?',
            [$sourceUid]
        );
        $sourceGroups = [];
        try {
            while ($g = $sgRows->fetch()) {
                $sourceGroups[(string)$g['id']] = $g;
            }
        } finally {
            $sgRows->closeCursor();
        }

        $sdRows = $this->db->executeQuery(
            'SELECT d.id, d.file_id, d.etag, d.bbox_x, d.bbox_y, d.bbox_w, d.bbox_h, d.score, d.sharpness,
                    d.group_id, d.model_space, d.vector_data, d.review, ff.inference_revision
             FROM *PREFIX*nextgallery_face_detections d
             INNER JOIN *PREFIX*nextgallery_faces_files ff ON ff.user_id = d.user_id AND ff.file_id = d.file_id
             WHERE d.user_id = ?',
            [$sourceUid]
        );
        $sourceByFile = [];
        try {
            while ($d = $sdRows->fetch()) {
                $fid = (int)$d['file_id'];
                $etag = (string)$d['etag'];
                if (!isset($myFiles[$fid]) || !hash_equals($myFiles[$fid], $etag)) continue;
                $sourceByFile[$fid][] = $d;
            }
        } finally {
            $sdRows->closeCursor();
        }

        // Filter to live-authorized files
        $authorizedByFile = [];
        foreach ($sourceByFile as $fid => $dets) {
            $etag = (string)$dets[0]['etag'];
            if ($this->authorizedFile($uid, $fid, $etag, $galleryRoots) !== null) {
                $authorizedByFile[$fid] = $dets;
            }
        }
        if (!$authorizedByFile) {
            throw new OCSBadRequestException('Keine freigegebenen Gesichtsdaten für gemeinsame Dateien gefunden');
        }

        $this->lock($uid);
        try {
            $acc = $this->accountState($uid);
            $now = time();
            $filesSynced = 0;
            $detsSynced = 0;
            $mappedGroups = [];
            $sourceDetToMyDet = [];

            $this->db->beginTransaction();
            try {
                foreach ($authorizedByFile as $fid => $srcDets) {
                    $etag = (string)$srcDets[0]['etag'];
                    $infRev = (string)($srcDets[0]['inference_revision'] ?? 'unknown');
                    $myDets = $this->getExistingDetections($uid, $fid, $etag);
                    $usedMyDetIds = [];
                    $filesSynced++;

                    $hadFileRecord = (bool)$this->db->executeQuery(
                        'SELECT 1 FROM *PREFIX*nextgallery_faces_files WHERE user_id = ? AND file_id = ? AND etag = ?',
                        [$uid, $fid, $etag]
                    )->fetchOne();
                    if (!$hadFileRecord) {
                        $this->db->executeStatement(
                            'DELETE FROM *PREFIX*nextgallery_faces_files WHERE user_id = ? AND file_id = ?',
                            [$uid, $fid]
                        );
                        $this->db->executeStatement(
                            'DELETE FROM *PREFIX*nextgallery_face_detections WHERE user_id = ? AND file_id = ?',
                            [$uid, $fid]
                        );
                        $this->db->executeStatement(
                            'INSERT INTO *PREFIX*nextgallery_faces_files (user_id, file_id, etag, face_count, updated_at, inference_revision) VALUES (?, ?, ?, ?, ?, ?)',
                            [$uid, $fid, $etag, count($srcDets), $now, $infRev]
                        );
                        $myDets = [];
                    }

                    foreach ($srcDets as $sDet) {
                        $srcGroupId = (string)$sDet['group_id'];
                        $targetGroupId = substr(hash('sha256', $uid . ':shared-group:' . $sourceUid . ':' . $srcGroupId), 0, 32);
                        $this->ensureGroup($uid, $targetGroupId);
                        $mappedGroups[$srcGroupId] = $targetGroupId;

                        $sBox = [(float)$sDet['bbox_x'], (float)$sDet['bbox_y'], (float)$sDet['bbox_w'], (float)$sDet['bbox_h']];
                        $bestMatch = null;
                        $bestIou = 0.0;
                        foreach ($myDets as $mDet) {
                            if (isset($usedMyDetIds[$mDet['id']])) continue;
                            $iou = self::iou($sBox, $mDet['bbox']);
                            if ($iou > $bestIou) {
                                $bestIou = $iou;
                                $bestMatch = $mDet;
                            }
                        }

                        if ($bestMatch !== null && $bestIou >= 0.5) {
                            $myDetId = (string)$bestMatch['id'];
                            $usedMyDetIds[$myDetId] = true;
                            $this->db->executeStatement(
                                'UPDATE *PREFIX*nextgallery_face_detections SET group_id = ?, manual = 1, review = ?, updated_at = ? WHERE user_id = ? AND id = ?',
                                [$targetGroupId, (int)$sDet['review'], $now, $uid, $myDetId]
                            );
                        } else {
                            $myDetId = bin2hex(random_bytes(16));
                            $this->db->executeStatement(
                                'INSERT INTO *PREFIX*nextgallery_face_detections (user_id, id, file_id, etag, bbox_x, bbox_y, bbox_w, bbox_h, score, group_id, model_space, vector_data, review, manual, created_at, updated_at, sharpness) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)',
                                [
                                    $uid, $myDetId, $fid, $etag,
                                    (float)$sDet['bbox_x'], (float)$sDet['bbox_y'], (float)$sDet['bbox_w'], (float)$sDet['bbox_h'],
                                    (float)$sDet['score'], $targetGroupId, (string)$sDet['model_space'], (string)$sDet['vector_data'],
                                    (int)$sDet['review'], $now, $now, isset($sDet['sharpness']) ? (float)$sDet['sharpness'] : null
                                ]
                            );
                        }
                        $sourceDetToMyDet[(string)$sDet['id']] = $myDetId;
                        $detsSynced++;
                    }
                }

                // Sync group metadata (labels, stranger, hidden, custom cover)
                foreach ($mappedGroups as $srcGroupId => $targetGroupId) {
                    $sg = $sourceGroups[$srcGroupId] ?? null;
                    if (!$sg) continue;
                    $lbl = isset($sg['label']) && trim((string)$sg['label']) !== '' ? trim((string)$sg['label']) : null;
                    $stranger = (int)($sg['stranger'] ?? 0) === 1 ? 1 : 0;
                    $hidden = (int)($sg['hidden'] ?? 0) === 1 ? 1 : 0;
                    $srcCover = isset($sg['cover_id']) && trim((string)$sg['cover_id']) !== '' ? (string)$sg['cover_id'] : null;
                    $mappedCover = ($srcCover !== null && isset($sourceDetToMyDet[$srcCover])) ? $sourceDetToMyDet[$srcCover] : null;

                    $this->db->executeStatement(
                        'UPDATE *PREFIX*nextgallery_faces_groups SET label = COALESCE(?, label), stranger = ?, hidden = ?, cover_id = COALESCE(?, cover_id), updated_at = ? WHERE user_id = ? AND id = ?',
                        [$lbl, $stranger, $hidden, $mappedCover, $now, $uid, $targetGroupId]
                    );
                }

                // Prune any empty groups
                $this->db->executeStatement(
                    'DELETE FROM *PREFIX*nextgallery_faces_groups WHERE user_id = ? AND id NOT IN (SELECT DISTINCT group_id FROM *PREFIX*nextgallery_face_detections WHERE user_id = ?)',
                    [$uid, $uid]
                );

                $newRevision = $acc['rules_revision'] + 1;
                $this->db->executeStatement(
                    'UPDATE *PREFIX*nextgallery_faces_account SET rules_revision = ?, updated_at = ? WHERE user_id = ?',
                    [$newRevision, $now, $uid]
                );
                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }
            $this->cleanupNativeSnapshots($uid);
            return [
                'adopted' => true,
                'source_user' => $sourceUid,
                'files_synced' => $filesSynced,
                'detections_synced' => $detsSynced,
                'groups_synced' => count($mappedGroups),
                'rules_revision' => $newRevision,
            ];
        } finally {
            $this->unlock($uid);
        }
    }

    /** Suggestions and decisions follow a merged group to its target. */
    private function remapPairs(string $uid, string $source, string $target, int $now): void {
        $this->db->executeStatement('UPDATE *PREFIX*nextgallery_face_suggest SET group_a = ? WHERE user_id = ? AND group_a = ?', [$target, $uid, $source]);
        $this->db->executeStatement('UPDATE *PREFIX*nextgallery_face_suggest SET group_b = ? WHERE user_id = ? AND group_b = ?', [$target, $uid, $source]);
        $this->db->executeStatement('DELETE FROM *PREFIX*nextgallery_face_suggest WHERE user_id = ? AND group_a = group_b', [$uid]);
        $r = $this->db->executeQuery('SELECT id, group_a, group_b FROM *PREFIX*nextgallery_faces_dismissed WHERE user_id = ? AND (group_a = ? OR group_b = ?)', [$uid, $source, $source]);
        try {
            $rows = $r->fetchAll();
        } finally {
            $r->closeCursor();
        }
        foreach ($rows as $row) {
            $this->db->executeStatement('DELETE FROM *PREFIX*nextgallery_faces_dismissed WHERE id = ?', [(int)$row['id']]);
            $other = (string)$row['group_a'] === $source ? (string)$row['group_b'] : (string)$row['group_a'];
            if ($other !== $target) $this->dismissPair($uid, $target, $other, $now);
        }
    }

    private function dismissPair(string $uid, string $a, string $b, int $now): void {
        [$a, $b] = self::pair($a, $b);
        $r = $this->db->executeQuery('SELECT 1 FROM *PREFIX*nextgallery_faces_dismissed WHERE user_id = ? AND group_a = ? AND group_b = ?', [$uid, $a, $b]);
        try {
            $exists = (bool)$r->fetchOne();
        } finally {
            $r->closeCursor();
        }
        if (!$exists) {
            $this->db->executeStatement('INSERT INTO *PREFIX*nextgallery_faces_dismissed (user_id, group_a, group_b, created_at) VALUES (?, ?, ?, ?)', [$uid, $a, $b, $now]);
        }
        $this->db->executeStatement('DELETE FROM *PREFIX*nextgallery_face_suggest WHERE user_id = ? AND ((group_a = ? AND group_b = ?) OR (group_a = ? AND group_b = ?))', [$uid, $a, $b, $b, $a]);
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
          ->from('nextgallery_face_detections')
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
