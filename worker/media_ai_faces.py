"""Face detection, recognition and conservative clustering for NextGallery.

Uses OpenCV YuNet for face detection and 5-point facial landmark alignment,
and SFace for 128-dimensional L2-normalized feature embeddings.
Maintains a dedicated local faces.sqlite3 cache and synchronizes with
authoritative Nextcloud backend generation and rules_revision.
"""
from __future__ import annotations

import io
import json
import math
import os
from pathlib import Path
import sqlite3
import struct
import sys
import time
import uuid

from PIL import Image, ImageOps

try:
    import pillow_heif
    pillow_heif.register_heif_opener()
except ImportError:
    pass

# This lane shares CPU inference with the other pipelines. Bound BLAS threads too.
for _thread_variable in ('OPENBLAS_NUM_THREADS','OMP_NUM_THREADS','MKL_NUM_THREADS'):
    os.environ.setdefault(_thread_variable,'1')

# OpenCV and numpy are guarded so base worker can import media_ai_faces without cv2.
try:
    import cv2
    if hasattr(cv2, 'setNumThreads'):
        cv2.setNumThreads(1)
except ImportError:
    cv2 = None

try:
    import numpy as np
except ImportError:
    np = None

import faces_setup
from inference_gate import hold, InferenceCancelled

MODEL_SPACE = 'faces-yunet-sface-128d-v1'
DEFAULT_COSINE_THRESHOLD = 0.55
DEFAULT_DETECTION_THRESHOLD = 0.9
DEFAULT_MIN_FACE_SIZE = 32
DEFAULT_MAX_EDGE = 2048
MAX_IMAGE_PIXELS = 80_000_000


class PipelineError(Exception):
    """Recoverable pipeline error."""


def compute_iou(box_a: list[float], box_b: list[float]) -> float:
    """Compute Intersection-over-Union between two normalized bboxes [x, y, w, h]."""
    xa, ya, wa, ha = box_a
    xb, yb, wb, hb = box_b

    x1 = max(xa, xb)
    y1 = max(ya, yb)
    x2 = min(xa + wa, xb + wb)
    y2 = min(ya + ha, yb + hb)

    inter_w = max(0.0, x2 - x1)
    inter_h = max(0.0, y2 - y1)
    inter_area = inter_w * inter_h

    area_a = wa * ha
    area_b = wb * hb
    union_area = area_a + area_b - inter_area
    if union_area <= 0.0:
        return 0.0
    return inter_area / union_area


class FaceStore:
    """Dedicated rebuildable SQLite cache for face detections and group assignments."""

    def __init__(self, db_path: Path):
        self.db_path = Path(db_path)
        self.db_path.parent.mkdir(parents=True, exist_ok=True)
        self.conn = sqlite3.connect(str(self.db_path), timeout=60.0)
        self.conn.execute("PRAGMA journal_mode = WAL;")
        self.conn.execute("PRAGMA synchronous = NORMAL;")
        self._init_schema()

    def _init_schema(self):
        with self.conn:
            self.conn.execute("""
            CREATE TABLE IF NOT EXISTS meta (
                key TEXT PRIMARY KEY,
                value TEXT
            );
            """)
            self.conn.execute("""
            CREATE TABLE IF NOT EXISTS files (
                file_id INTEGER PRIMARY KEY,
                etag TEXT NOT NULL,
                status TEXT NOT NULL,
                face_count INTEGER NOT NULL DEFAULT 0,
                updated_at REAL NOT NULL,
                error TEXT
            );
            """)
            self.conn.execute("""
            CREATE TABLE IF NOT EXISTS detections (
                id TEXT PRIMARY KEY,
                file_id INTEGER NOT NULL,
                etag TEXT NOT NULL,
                bbox TEXT NOT NULL,
                vector BLOB NOT NULL,
                score REAL NOT NULL,
                group_id TEXT NOT NULL,
                review INTEGER NOT NULL DEFAULT 0,
                updated_at REAL NOT NULL
            );
            """)
            self.conn.execute("CREATE INDEX IF NOT EXISTS idx_det_file ON detections(file_id, etag);")
            self.conn.execute("CREATE INDEX IF NOT EXISTS idx_det_group ON detections(group_id);")

    def get_meta(self, key: str, fallback=None):
        cur = self.conn.execute("SELECT value FROM meta WHERE key = ?", (key,))
        row = cur.fetchone()
        return row[0] if row else fallback

    def set_meta(self, key: str, value: str):
        with self.conn:
            self.conn.execute("INSERT OR REPLACE INTO meta(key, value) VALUES(?, ?)", (key, str(value)))

    def clear_all(self):
        with self.conn:
            self.conn.execute("DELETE FROM files")
            self.conn.execute("DELETE FROM detections")
            self.conn.execute("DELETE FROM meta")

    def is_file_done(self, file_id: int, etag: str) -> bool:
        cur = self.conn.execute("SELECT 1 FROM files WHERE file_id = ? AND etag = ? AND status = 'done'", (file_id, etag))
        return cur.fetchone() is not None

    def is_file_error(self, file_id: int, etag: str) -> bool:
        cur = self.conn.execute("SELECT 1 FROM files WHERE file_id = ? AND etag = ? AND status = 'error'", (file_id, etag))
        return cur.fetchone() is not None

    def record_file(self, file_id: int, etag: str, status: str, face_count: int = 0, error: str = ''):
        with self.conn:
            self.conn.execute(
                "INSERT OR REPLACE INTO files(file_id, etag, status, face_count, updated_at, error) VALUES(?, ?, ?, ?, ?, ?)",
                (file_id, etag, status, face_count, time.time(), error)
            )

    def save_detections(self, detections: list[dict]):
        with self.conn:
            for d in detections:
                vec = d['vector']
                vec_blob = struct.pack(f"{len(vec)}f", *vec) if isinstance(vec, (list, tuple)) else vec
                bbox_str = json.dumps(d['bbox']) if isinstance(d['bbox'], (list, tuple)) else str(d['bbox'])
                self.conn.execute("""
                INSERT OR REPLACE INTO detections(id, file_id, etag, bbox, vector, score, group_id, review, updated_at)
                VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?)
                """, (
                    d['id'],
                    int(d['file_id']),
                    d['etag'],
                    bbox_str,
                    vec_blob,
                    float(d.get('score', 1.0)),
                    d['group_id'],
                    1 if d.get('review') else 0,
                    time.time(),
                ))

    def sync_snapshot(self, detections: list[dict]):
        """Replace local detections with authoritative backend snapshot."""
        with self.conn:
            current_ids = set()
            for d in detections:
                current_ids.add(d['id'])
                vec = d['vector']
                vec_blob = struct.pack(f"{len(vec)}f", *vec) if isinstance(vec, (list, tuple)) else vec
                bbox_str = json.dumps(d['bbox']) if isinstance(d['bbox'], (list, tuple)) else str(d['bbox'])
                self.conn.execute("""
                INSERT OR REPLACE INTO detections(id, file_id, etag, bbox, vector, score, group_id, review, updated_at)
                VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?)
                """, (
                    d['id'],
                    int(d['file_id']),
                    d['etag'],
                    bbox_str,
                    vec_blob,
                    float(d.get('score', 1.0)),
                    d['group_id'],
                    1 if d.get('review') else 0,
                    time.time(),
                ))
            if current_ids:
                # Remove stale local records not present in authoritative backend state
                cur = self.conn.execute("SELECT id FROM detections")
                to_delete = [r[0] for r in cur.fetchall() if r[0] not in current_ids]
                for chunk in [to_delete[i:i + 500] for i in range(0, len(to_delete), 500)]:
                    placeholders = ','.join('?' for _ in chunk)
                    self.conn.execute(f"DELETE FROM detections WHERE id IN ({placeholders})", chunk)
            elif not detections:
                self.conn.execute("DELETE FROM detections")

    def load_active_vectors(self) -> list[tuple[str, list[float]]]:
        """Load (group_id, vector_128) pairs for active cluster matching."""
        cur = self.conn.execute("SELECT group_id, vector FROM detections WHERE review = 0")
        results = []
        for group_id, blob in cur.fetchall():
            dim = len(blob) // 4
            vec = list(struct.unpack(f"{dim}f", blob))
            results.append((group_id, vec))
        return results

    def close(self):
        self.conn.close()


class FaceClusterIndex:
    """Exact one-to-many cosine matching with a bounded tail (never an NxN matrix).

    Maintains a contiguous matrix plus a bounded active tail with growing
    rebuild intervals. Uses transaction checkpointing with separate tentative buffers
    to guarantee no ghost groups leak into the index on failed publish.
    """

    def __init__(self, cosine_threshold: float = DEFAULT_COSINE_THRESHOLD):
        self.cosine_threshold = cosine_threshold
        self.indexed_vectors = None
        self.indexed_groups: list[str] = []
        self.tail_vectors: list[list[float]] = []
        self.tail_groups: list[str] = []
        self.tentative_vectors: list[list[float]] = []
        self.tentative_groups: list[str] = []
        self._checkpoint_active: bool = False

    def checkpoint(self):
        """Save index state and begin transaction for a file."""
        self.tentative_vectors.clear()
        self.tentative_groups.clear()
        self._checkpoint_active = True

    def rollback(self):
        """Revert newly proposed tentative vectors if file publish fails."""
        self.tentative_vectors.clear()
        self.tentative_groups.clear()
        self._checkpoint_active = False

    def commit(self):
        """Commit newly proposed tentative vectors on successful publish."""
        if self._checkpoint_active:
            self.tail_vectors.extend(self.tentative_vectors)
            self.tail_groups.extend(self.tentative_groups)
            self.tentative_vectors.clear()
            self.tentative_groups.clear()
            self._checkpoint_active = False
            k = len(self.indexed_groups)
            thresh = min(500, max(64, k // 2))
            if len(self.tail_vectors) >= thresh:
                self._rebuild()

    def apply_canonical(self, canonical_detections: list[dict]):
        """Discard tentative proposals and record authoritative canonical backend assignments."""
        self.tentative_vectors.clear()
        self.tentative_groups.clear()
        self._checkpoint_active = False

        for det in canonical_detections:
            gid = det.get('group_id')
            vec = det.get('vector')
            if gid and vec:
                self.tail_vectors.append(vec)
                self.tail_groups.append(gid)

        k = len(self.indexed_groups)
        thresh = min(500, max(64, k // 2))
        if len(self.tail_vectors) >= thresh:
            self._rebuild()

    def populate(self, items: list[tuple[str, list[float]]]):
        """Populate index with known (group_id, normalized_vector) pairs."""
        self.indexed_groups.clear()
        self.tail_groups.clear()
        self.tail_vectors.clear()
        self.tentative_groups.clear()
        self.tentative_vectors.clear()
        self._checkpoint_active = False
        self.indexed_vectors = None

        if not items:
            return

        for group_id, vec in items:
            self.tail_groups.append(group_id)
            self.tail_vectors.append(vec)
        self._rebuild()

    def _rebuild(self):
        """Consolidate committed vectors; proposals never enter the search matrix."""
        if self._checkpoint_active or not self.tail_vectors or np is None:
            return
        tail = np.asarray(self.tail_vectors,dtype=np.float32)
        self.indexed_vectors = tail if self.indexed_vectors is None else np.concatenate((self.indexed_vectors,tail),axis=0)
        self.indexed_groups.extend(self.tail_groups)
        self.tail_vectors.clear()
        self.tail_groups.clear()

    def match_or_create(self, query_vector: list[float]) -> str:
        """Find best matching group_id or generate a new stable UUID32."""
        best_score = -1.0
        best_group = None

        # Exact cosine scan avoids false splits from approximate KD-tree misses in
        # 128 dimensions. Only one N-element score vector exists for each query.
        if self.indexed_vectors is not None and self.indexed_groups:
            q = np.asarray(query_vector,dtype=np.float32)
            dots = self.indexed_vectors @ q
            max_idx = int(np.argmax(dots))
            best_score = float(dots[max_idx])
            best_group = self.indexed_groups[max_idx]

        # 2. Search unindexed tail (exact matching over recent committed additions)
        if self.tail_vectors:
            if np is not None and hasattr(np, 'ndarray'):
                try:
                    q = np.array(query_vector, dtype=np.float32)
                    tail_arr = np.array(self.tail_vectors, dtype=np.float32)
                    dots = np.dot(tail_arr, q)
                    max_idx = int(np.argmax(dots))
                    s = float(dots[max_idx])
                    if s > best_score:
                        best_score = s
                        best_group = self.tail_groups[max_idx]
                except Exception:
                    pass
            if best_group is None or best_score < self.cosine_threshold:
                for gid, vec in zip(self.tail_groups, self.tail_vectors):
                    sim = sum(a * b for a, b in zip(query_vector, vec))
                    if sim > best_score:
                        best_score = sim
                        best_group = gid

        # 3. Search uncommitted tentative vectors from active transaction (if checkpoint active)
        if self._checkpoint_active and self.tentative_vectors:
            if np is not None and hasattr(np, 'ndarray'):
                try:
                    q = np.array(query_vector, dtype=np.float32)
                    tent_arr = np.array(self.tentative_vectors, dtype=np.float32)
                    dots = np.dot(tent_arr, q)
                    max_idx = int(np.argmax(dots))
                    s = float(dots[max_idx])
                    if s > best_score:
                        best_score = s
                        best_group = self.tentative_groups[max_idx]
                except Exception:
                    pass
            if best_group is None or best_score < self.cosine_threshold:
                for gid, vec in zip(self.tentative_groups, self.tentative_vectors):
                    sim = sum(a * b for a, b in zip(query_vector, vec))
                    if sim > best_score:
                        best_score = sim
                        best_group = gid

        # 4. Decision: match existing or create new group
        if best_score >= self.cosine_threshold and best_group is not None:
            chosen_group = best_group
        else:
            chosen_group = uuid.uuid4().hex

        if self._checkpoint_active:
            self.tentative_vectors.append(query_vector)
            self.tentative_groups.append(chosen_group)
        else:
            self.tail_vectors.append(query_vector)
            self.tail_groups.append(chosen_group)
            k = len(self.indexed_groups)
            thresh = min(500, max(64, k // 2))
            if len(self.tail_vectors) >= thresh:
                self._rebuild()

        return chosen_group


class FaceDetector:
    """Wrapper around OpenCV YuNet and SFace models with multiscale detection."""

    def __init__(
        self,
        detector_path: Path,
        recognizer_path: Path,
        detection_threshold: float = DEFAULT_DETECTION_THRESHOLD,
        min_face_size: int = DEFAULT_MIN_FACE_SIZE,
    ):
        if cv2 is None or np is None:
            raise PipelineError('OpenCV and numpy are required for face inference')

        self.detector_path = Path(detector_path)
        self.recognizer_path = Path(recognizer_path)
        self.detection_threshold = detection_threshold
        self.min_face_size = min_face_size

        if not self.detector_path.is_file():
            raise PipelineError(f'Face detector model missing at {self.detector_path}')
        if not self.recognizer_path.is_file():
            raise PipelineError(f'Face recognizer model missing at {self.recognizer_path}')

        self.detector = cv2.FaceDetectorYN.create(
            model=str(self.detector_path),
            config='',
            input_size=(320, 320),
            score_threshold=float(self.detection_threshold),
            nms_threshold=0.3,
            top_k=5000,
        )
        self.recognizer = cv2.FaceRecognizerSF.create(
            model=str(self.recognizer_path),
            config='',
        )

    def process_image(self, image_bytes: bytes, max_edge: int = DEFAULT_MAX_EDGE) -> list[dict]:
        """Detect faces with multiscale (2048+640) and conservative IoU suppression."""
        raw_im = None
        trans_im = None
        try:
            try:
                raw_im = Image.open(io.BytesIO(image_bytes))
            except Exception as error:
                raise PipelineError(f'Failed to decode image: {error}') from error

            if raw_im.width * raw_im.height > MAX_IMAGE_PIXELS:
                raise PipelineError('Image exceeds max pixel guard')

            trans_im = ImageOps.exif_transpose(raw_im)
            im = trans_im if trans_im is not None else raw_im
            if im.mode not in ('RGB', 'L'):
                im = im.convert('RGB')
            elif im.mode == 'L':
                im = im.convert('RGB')

            orig_w, orig_h = im.size
            if orig_w <= 0 or orig_h <= 0:
                return []

            # Multiscale detection: scale 1 (max_edge up to 4096, default 2048) and scale 2 (640)
            target_max = min(max(int(max_edge), 640), 4096)
            scales = [target_max]
            if max(orig_w, orig_h) > 640 and scales[0] > 640:
                scales.append(640)

            candidates = []
            for target_edge in scales:
                scale = min(1.0, target_edge / float(max(orig_w, orig_h)))
                nw = max(1, int(round(orig_w * scale)))
                nh = max(1, int(round(orig_h * scale)))

                scaled_im = im if (nw, nh) == (orig_w, orig_h) else im.resize((nw, nh), Image.Resampling.LANCZOS)
                bgr = cv2.cvtColor(np.array(scaled_im), cv2.COLOR_RGB2BGR)

                self.detector.setInputSize((nw, nh))
                _, faces = self.detector.detect(bgr)

                if faces is not None:
                    for face in faces:
                        score = float(face[14])
                        fx, fy, fw, fh = [float(v) for v in face[0:4]]

                        # Filter tiny faces on scaled image
                        if fw < self.min_face_size or fh < self.min_face_size:
                            continue

                        # Project to normalized coordinates [0..1] on oriented original image
                        orig_fx = fx / scale
                        orig_fy = fy / scale
                        orig_fw = fw / scale
                        orig_fh = fh / scale

                        nx = max(0.0, min(1.0, orig_fx / orig_w))
                        ny = max(0.0, min(1.0, orig_fy / orig_h))
                        nw_norm = max(0.0, min(1.0 - nx, orig_fw / orig_w))
                        nh_norm = max(0.0, min(1.0 - ny, orig_fh / orig_h))

                        if nw_norm <= 0.0 or nh_norm <= 0.0:
                            continue

                        norm_box = [round(nx, 6), round(ny, 6), round(nw_norm, 6), round(nh_norm, 6)]
                        candidates.append({
                            'bgr': bgr,
                            'face_raw': face,
                            'score': score,
                            'norm_box': norm_box,
                            'scale': scale,
                        })

            if not candidates:
                return []

            # Conservative IoU duplicate suppression across scales (threshold 0.40)
            candidates.sort(key=lambda c: c['score'], reverse=True)
            unique_detections = []
            for cand in candidates:
                duplicate = False
                for existing in unique_detections:
                    if compute_iou(cand['norm_box'], existing['norm_box']) >= 0.40:
                        duplicate = True
                        break
                if not duplicate:
                    unique_detections.append(cand)

            # Align and extract SFace feature embeddings for unique detections
            results = []
            for det in unique_detections:
                aligned = self.recognizer.alignCrop(det['bgr'], det['face_raw'])
                feature = self.recognizer.feature(aligned)

                feat_vec = feature.flatten().astype(np.float32)
                norm = float(np.linalg.norm(feat_vec))
                if norm > 1e-6:
                    feat_vec = feat_vec / norm
                vec_list = [float(v) for v in feat_vec]

                if len(vec_list) != 128 or any(not math.isfinite(v) for v in vec_list):
                    continue

                results.append({
                    'bbox': det['norm_box'],
                    'vector': vec_list,
                    'score': round(det['score'], 4),
                })

            return results
        finally:
            if trans_im is not None and trans_im is not raw_im:
                try:
                    trans_im.close()
                except Exception:
                    pass
            if raw_im is not None:
                try:
                    raw_im.close()
                except Exception:
                    pass


def load_media_bytes(source, item, max_bytes: int = 64 * 1024 * 1024) -> bytes:
    if hasattr(source, 'download'):
        res = source.download(item)
        if isinstance(res, (bytes, bytearray)):
            return bytes(res)
        if isinstance(res, (str, Path)):
            return Path(res).read_bytes()
    if hasattr(source, 'read'):
        return source.read(item, max_bytes)
    if hasattr(source, 'download_to'):
        import tempfile
        with tempfile.NamedTemporaryFile() as tmp:
            tmp_path = Path(tmp.name)
            source.download_to(item, tmp_path, max_bytes)
            return tmp_path.read_bytes()
    raise AttributeError('Source object has no recognized download/read method')


def sync_backend_state(api, store: FaceStore, cluster_index: FaceClusterIndex, observe) -> tuple[str, int, int, str]:
    """Synchronize authoritative account state from backend across pages with consistency check."""
    cursor = 0
    first_epoch = None
    first_gen = None
    first_rules_rev = None
    all_detections = []
    space = MODEL_SPACE

    while True:
        endpoint = 'faces-state' + (f'?cursor={cursor}' if cursor else '')
        response = api.call(endpoint)
        ep = response.get('epoch', '')
        gen = int(response.get('generation', 0))
        rr = int(response.get('rules_revision', 0))
        space = response.get('model_space', MODEL_SPACE)

        if first_epoch is None:
            first_epoch, first_gen, first_rules_rev = ep, gen, rr
        elif ep != first_epoch or gen != first_gen or rr != first_rules_rev:
            raise PipelineError('Backend state became inconsistent across pages during synchronization')

        items = response.get('items', [])
        all_detections.extend(items)

        cursor = response.get('next')
        if not response.get('more') or cursor is None:
            break

    # If backend epoch or generation changed: full cache reset
    cached_epoch = store.get_meta('epoch')
    cached_gen = int(store.get_meta('generation', -1))
    cached_space = store.get_meta('model_space')

    if cached_epoch != first_epoch or cached_gen != first_gen or cached_space != space:
        store.clear_all()
        store.set_meta('epoch', first_epoch)
        store.set_meta('generation', str(first_gen))
        store.set_meta('rules_revision', str(first_rules_rev))
        store.set_meta('model_space', space)

    # Authoritative sync: replace local detections with backend snapshot
    store.sync_snapshot(all_detections)
    store.set_meta('rules_revision', str(first_rules_rev))

    # Populate nearest neighbor cluster index
    active = store.load_active_vectors()
    cluster_index.populate(active)

    return first_epoch, first_gen, first_rules_rev, space


def run(
    config: dict,
    source,
    api,
    directory: Path | str,
    observe,
    should_stop,
    retry_errors: bool = False,
) -> dict:
    """Execute face processing pipeline.

    Returns dict of state/stats/done/pending/error/model_space/seconds.
    """
    started = time.monotonic()
    directory = Path(directory)
    faces_config = config.get('faces', {})
    if not faces_config.get('enabled', False):
        return {
            'state': 'idle',
            'stats': {'done': 0, 'pending': 0, 'error': 0},
            'done': 0,
            'pending': 0,
            'error': 0,
            'model_space': MODEL_SPACE,
            'seconds': 0.0,
        }

    if cv2 is None:
        raise PipelineError('OpenCV (cv2) is not installed in the faces worker runtime')

    # Resolve model paths
    models = faces_setup.model_paths(config.get('_model_directory', directory.parent))
    detector_path = models['detector']
    recognizer_path = models['recognizer']

    if not detector_path.is_file() or not recognizer_path.is_file():
        raise PipelineError('Face models are missing. Please complete face setup first.')

    cosine_thresh = float(faces_config.get('cosine_threshold', DEFAULT_COSINE_THRESHOLD))
    det_thresh = float(faces_config.get('detection_threshold', DEFAULT_DETECTION_THRESHOLD))
    min_size = int(faces_config.get('min_face_size', DEFAULT_MIN_FACE_SIZE))
    max_edge = int(faces_config.get('max_edge', DEFAULT_MAX_EDGE))

    store = FaceStore(directory / 'faces.sqlite3')
    cluster_index = FaceClusterIndex(cosine_threshold=cosine_thresh)
    detector = FaceDetector(detector_path, recognizer_path, detection_threshold=det_thresh, min_face_size=min_size)

    done_count = 0
    error_count = 0
    pending_count = 0

    try:
        # Step 1: Synchronize backend authoritative state
        epoch, generation, rules_rev, space = sync_backend_state(api, store, cluster_index, observe)

        # Step 2: Fetch inventory and filter
        items = source.inventory()
        to_process = []
        for item in items:
            if not getattr(item, 'mime', '').startswith('image/'):
                continue
            file_id = int(item.locator)

            # Skip if analyzed_etag is current in backend (survives cache deletion, including 0-face images)
            analyzed_etag = getattr(item, 'analyzed_etag', '')
            if analyzed_etag and analyzed_etag == item.etag:
                done_count += 1
                continue

            # Respect retry_errors: skip persistent errors unless explicitly requested
            if not retry_errors and store.is_file_error(file_id, item.etag):
                error_count += 1
                continue

            to_process.append(item)

        pending_count = len(to_process)
        observe('faces_inventory', total=len(items), pending=pending_count, done=done_count)

        for item in to_process:
            if should_stop():
                return {
                    'state': 'paused',
                    'stats': {'done': done_count, 'pending': pending_count, 'error': error_count},
                    'done': done_count,
                    'pending': pending_count,
                    'error': error_count,
                    'model_space': space,
                    'seconds': round(time.monotonic() - started, 3),
                }

            file_id = int(item.locator)
            observe('file_start', file_id=file_id, path=item.path, stage='faces')

            try:
                # Step 3: Download media bytes
                raw_bytes = load_media_bytes(source, item, max_bytes=config.get('max_download_bytes', 64 * 1024 * 1024))

                # Step 4: Inference with inference_gate
                with hold(config.get('_inference_directory'), 'http://127.0.0.1:9379/v1', should_stop):
                    detected_faces = detector.process_image(raw_bytes, max_edge=max_edge)

                # Step 5: Assign stable groups with transaction checkpoint
                cluster_index.checkpoint()
                payload_detections = []
                for face in detected_faces:
                    group_id = cluster_index.match_or_create(face['vector'])
                    payload_detections.append({
                        'bbox': face['bbox'],
                        'vector': face['vector'],
                        'score': face['score'],
                        'group_id': group_id,
                    })

                # Step 6: Verify source unchanged before publishing
                if hasattr(source, 'unchanged') and not source.unchanged(item):
                    raise PipelineError('Media changed during face processing')

                # Step 7: Publish to backend with 409 retry handling
                publish_body = {
                    'file_id': file_id,
                    'etag': item.etag,
                    'epoch': epoch,
                    'generation': generation,
                    'rules_revision': rules_rev,
                    'space': space,
                    'detections': payload_detections,
                }

                try:
                    pub_response = api.call('publish-faces', publish_body)
                except Exception as pub_error:
                    status_code = getattr(pub_error, 'code', None) or getattr(pub_error, 'status', None) or getattr(pub_error, 'status_code', None)
                    if status_code is None:
                        resp = getattr(pub_error, 'response', None)
                        if resp is not None:
                            status_code = getattr(resp, 'status_code', None) or getattr(resp, 'status', None)
                    err_str = str(pub_error).lower()
                    is_409 = (status_code == 409) or ('409' in err_str) or ('conflict' in err_str)

                    if is_409:
                        cluster_index.rollback()
                        try:
                            st = api.call('faces-state')
                            fresh_epoch = st.get('epoch', '')
                            fresh_gen = int(st.get('generation', 0))
                            fresh_rules_rev = int(st.get('rules_revision', 0))
                            fresh_space = st.get('model_space', space)
                        except Exception as fetch_err:
                            raise pub_error from fetch_err

                        if (fresh_epoch != epoch) or (fresh_gen != generation):
                            # Reset / generation change: abort cycle, clear cache, pause. Never retry old inference across reset!
                            store.clear_all()
                            return {
                                'state': 'paused',
                                'stats': {'done': done_count, 'pending': pending_count, 'error': error_count},
                                'done': done_count,
                                'pending': pending_count,
                                'error': error_count,
                                'model_space': space,
                                'seconds': round(time.monotonic() - started, 3),
                            }
                        elif fresh_rules_rev != rules_rev:
                            # Rules revision conflict (e.g. user merged/split groups): re-sync and retry without re-inference
                            epoch, generation, rules_rev, space = sync_backend_state(api, store, cluster_index, observe)
                            cluster_index.checkpoint()
                            payload_detections = []
                            for face in detected_faces:
                                group_id = cluster_index.match_or_create(face['vector'])
                                payload_detections.append({
                                    'bbox': face['bbox'],
                                    'vector': face['vector'],
                                    'score': face['score'],
                                    'group_id': group_id,
                                })
                            publish_body['epoch'] = epoch
                            publish_body['generation'] = generation
                            publish_body['rules_revision'] = rules_rev
                            publish_body['space'] = space
                            publish_body['detections'] = payload_detections
                            pub_response = api.call('publish-faces', publish_body)
                        else:
                            # ETag mismatch or other file conflict: do not retry, raise to let outer handler mark file as error
                            raise pub_error
                    else:
                        raise pub_error

                # Publish succeeded: apply canonical detections from backend
                canonical = pub_response.get('detections', [])
                cluster_index.apply_canonical(canonical)

                store.save_detections(canonical)
                store.record_file(file_id, item.etag, 'done', face_count=len(canonical))

                done_count += 1
                pending_count -= 1
                observe('faces_processed', file_id=file_id, path=item.path, faces_count=len(canonical))

            except InferenceCancelled:
                cluster_index.rollback()
                return {
                    'state': 'paused',
                    'stats': {'done': done_count, 'pending': pending_count, 'error': error_count},
                    'done': done_count,
                    'pending': pending_count,
                    'error': error_count,
                    'model_space': space,
                    'seconds': round(time.monotonic() - started, 3),
                }
            except Exception as error:
                cluster_index.rollback()
                error_count += 1
                pending_count -= 1
                err_msg = str(error)[:300]
                store.record_file(file_id, item.etag, 'error', error=err_msg)
                observe('faces_error', file_id=file_id, path=item.path, error=err_msg)

        return {
            'state': 'finished',
            'stats': {'done': done_count, 'pending': pending_count, 'error': error_count},
            'done': done_count,
            'pending': pending_count,
            'error': error_count,
            'model_space': space,
            'seconds': round(time.monotonic() - started, 3),
        }
    finally:
        store.close()
