<?php
declare(strict_types=1);

namespace OCA\Nextgallery\Controller;

use OCP\IRequest;
use OCP\IUserSession;
use OCP\IPreview;
use OCP\Files\IRootFolder;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\OCS\{OCSBadRequestException, OCSForbiddenException, OCSNotFoundException};
use OCA\Nextgallery\Http\Bytes;
use OCA\Nextgallery\Exception\FaceConflictException;
use OCA\Nextgallery\AI\Service\Faces;
use OCA\Nextgallery\Service\Library;
use Psr\Log\LoggerInterface;

/**
 * Controller for Web UI face recognition endpoints (returning plain JSON DataResponse).
 */
class FacesController extends Controller {
    public function __construct(
        IRequest $request,
        private IUserSession $session,
        private Faces $faces,
        private Library $library,
        private ?IPreview $previews = null,
        private ?IRootFolder $rootFolder = null,
        private ?LoggerInterface $logger = null
    ) {
        parent::__construct('nextgallery', $request);
    }

    private function uid(): string {
        $user = $this->session->getUser();
        if ($user === null) {
            throw new OCSForbiddenException('Anmeldung erforderlich');
        }
        return $user->getUID();
    }

    #[NoAdminRequired]
    public function subjects(
        string $gallery = '',
        int $cursor = 0,
        int $limit = 60,
        string $filter = 'all'
    ): DataResponse {
        $uid = $this->uid();
        $gallery = $gallery !== '' ? $gallery : (string)$this->request->getParam('gallery', '');
        $cursor = $cursor !== 0 ? $cursor : (int)$this->request->getParam('cursor', 0);
        $limit = $limit !== 60 ? $limit : (int)$this->request->getParam('limit', 60);
        $filter = $filter !== 'all' ? $filter : (string)$this->request->getParam('filter', 'all');

        $data = $this->faces->subjects($uid, $gallery, $cursor, $limit, $filter);
        $response = new DataResponse($data);
        $response->addHeader('Cache-Control', 'private, no-store');
        return $response;
    }

    #[NoAdminRequired]
    public function media(
        string $id,
        string $gallery = '',
        int $cursor = 0,
        int $limit = 60,
        string $filter = 'all'
    ): DataResponse {
        $uid = $this->uid();
        $gallery = $gallery !== '' ? $gallery : (string)$this->request->getParam('gallery', '');
        $cursor = $cursor !== 0 ? $cursor : (int)$this->request->getParam('cursor', 0);
        $limit = $limit !== 60 ? $limit : (int)$this->request->getParam('limit', 60);

        $filter=$filter!=='all'?$filter:(string)$this->request->getParam('filter','all');
        try {
            $data = $this->faces->media($uid, $id, $gallery, $cursor, $limit, $filter);
            $response = new DataResponse($data);
            $response->addHeader('Cache-Control', 'private, no-store');
            return $response;
        } catch (OCSBadRequestException $e) {
            return new DataResponse(['error' => $e->getMessage()], 400);
        } catch (FaceConflictException $e) {
            return new DataResponse(['error' => $e->getMessage()], 409);
        } catch (OCSNotFoundException $e) {
            return new DataResponse(['error' => $e->getMessage()], 404);
        } catch (\Throwable $e) {
            $this->logger?->error('Faces media error: ' . $e->getMessage(), ['exception' => $e]);
            return new DataResponse(['error' => 'Interner Serverfehler'], 500);
        }
    }

    #[NoAdminRequired]
    public function similar(string $id, string $gallery = ''): DataResponse {
        $uid = $this->uid();
        $gallery = $gallery !== '' ? $gallery : (string)$this->request->getParam('gallery', '');
        try {
            $response = new DataResponse($this->faces->similar($uid, $id, $gallery));
            $response->addHeader('Cache-Control', 'private, no-store');
            return $response;
        } catch (OCSBadRequestException $e) {
            return new DataResponse(['error' => $e->getMessage()], 400);
        } catch (OCSNotFoundException $e) {
            return new DataResponse(['error' => $e->getMessage()], 404);
        } catch (\Throwable $e) {
            $this->logger?->error('Faces similar error: ' . $e->getMessage(), ['exception' => $e]);
            return new DataResponse(['error' => 'Interner Serverfehler'], 500);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function previewPeople(string $detectionId, array $roots = []): Bytes {
        $roots = $roots !== [] ? $roots : (array)$this->request->getParam('roots', []);
        if (empty($roots)) {
            return new Bytes('', 'text/plain', 404, ['Cache-Control' => 'no-store']);
        }
        return $this->preview($detectionId, '', $roots);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function preview(string $detectionId, string $gallery = '', array $roots = []): Bytes {
        $uid = $this->uid();
        $gallery = $gallery !== '' ? $gallery : (string)$this->request->getParam('gallery', '');
        $roots = $roots !== [] ? $roots : (array)$this->request->getParam('roots', []);

        $det = $this->faces->getDetection($uid, $detectionId);
        if (!$det) {
            return new Bytes('', 'text/plain', 404, ['Cache-Control' => 'no-store']);
        }

        if ($roots !== []) {
            try {
                [$home, $selected] = \OCA\Nextgallery\Service\NativePeople::validateFolders($this->rootFolder, $uid, $roots);
                $effectiveRoots = array_keys($selected);
            } catch (\InvalidArgumentException) {
                return new Bytes('', 'text/plain', 404, ['Cache-Control' => 'no-store']);
            }
        } else {
            $effectiveRoots = $this->library->settings($uid, $gallery)['roots'] ?? [];
        }

        // Current read access, gallery scope and file version; fails closed without a home folder.
        $live = $this->faces->authorizedFile($uid, (int)$det['file_id'], (string)$det['etag'], $effectiveRoots);
        if ($live === null) {
            return new Bytes('', 'text/plain', 404, ['Cache-Control' => 'no-store']);
        }
        $file = $live['file'];

        $img = null;
        if ($this->previews !== null) {
            try {
                $preview = $this->previews->getPreview($file, 1024, 1024, false, IPreview::MODE_FILL);
                $content = $preview->getContent();
                $dimensions = @getimagesizefromstring($content);
                if (strlen($content) <= 8 * 1024 * 1024 && $dimensions && $dimensions[0] * $dimensions[1] <= 16000000) {
                    $img = @imagecreatefromstring($content);
                }
            } catch (\Throwable) {}
        }

        if (!$img) {
            return new Bytes('', 'text/plain', 415, ['Cache-Control' => 'no-store']);
        }

        $w = imagesx($img);
        $h = imagesy($img);
        $bx = (float)$det['bbox_x'];
        $by = (float)$det['bbox_y'];
        $bw = (float)$det['bbox_w'];
        $bh = (float)$det['bbox_h'];
        $contextMode = (string)$this->request->getParam('context', '') === '1'
            || (string)$this->request->getParam('mode', '') === 'context';

        if ($contextMode) {
            $targetSize = 768;
            $ratio = min(1.0, min($targetSize / max(1, $w), $targetSize / max(1, $h)));
            $targetWidth = max(1, (int)round($w * $ratio));
            $targetHeight = max(1, (int)round($h * $ratio));
            $thumb = imagecreatetruecolor($targetWidth, $targetHeight);
            imagecopyresampled($thumb, $img, 0, 0, 0, 0, $targetWidth, $targetHeight, $w, $h);

            $rx0 = max(0, min((int)round($bx * $targetWidth), $targetWidth - 1));
            $ry0 = max(0, min((int)round($by * $targetHeight), $targetHeight - 1));
            $rx1 = max($rx0 + 1, min((int)round(($bx + $bw) * $targetWidth), $targetWidth - 1));
            $ry1 = max($ry0 + 1, min((int)round(($by + $bh) * $targetHeight), $targetHeight - 1));

            $white = imagecolorallocate($thumb, 255, 255, 255);
            $accent = imagecolorallocate($thumb, 0, 130, 201);
            imagesetthickness($thumb, 4);
            imagerectangle($thumb, max(0, $rx0 - 2), max(0, $ry0 - 2), min($targetWidth - 1, $rx1 + 2), min($targetHeight - 1, $ry1 + 2), $white);
            imagesetthickness($thumb, 2);
            imagerectangle($thumb, $rx0, $ry0, $rx1, $ry1, $accent);
        } else {
            $padX = $bw * 0.15;
            $padY = $bh * 0.15;
            $x0 = max(0.0, $bx - $padX);
            $y0 = max(0.0, $by - $padY);
            $x1 = min(1.0, $bx + $bw + $padX);
            $y1 = min(1.0, $by + $bh + $padY);

            $cropX = max(0, min((int)round($x0 * $w), $w - 1));
            $cropY = max(0, min((int)round($y0 * $h), $h - 1));
            $cropW = max(1, min((int)round(($x1 - $x0) * $w), $w - $cropX));
            $cropH = max(1, min((int)round(($y1 - $y0) * $h), $h - $cropY));

            $cropped = imagecrop($img, ['x' => $cropX, 'y' => $cropY, 'width' => $cropW, 'height' => $cropH]);
            $targetSize = 256;
            $ratio = min($targetSize / $cropW, $targetSize / $cropH);
            $targetWidth = max(1, (int)round($cropW * $ratio));
            $targetHeight = max(1, (int)round($cropH * $ratio));
            $thumb = imagecreatetruecolor($targetWidth, $targetHeight);
            imagecopyresampled(
                $thumb,
                $cropped ?: $img,
                0, 0, 0, 0,
                $targetWidth, $targetHeight,
                $cropped ? $cropW : $w,
                $cropped ? $cropH : $h
            );
        }

        ob_start();
        imagejpeg($thumb, null, 85);
        $jpeg = ob_get_clean();

        return new Bytes(
            $jpeg,
            'image/jpeg',
            200,
            ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']
        );
    }

    #[NoAdminRequired]
    public function action(
        string $id,
        string $action = '',
        ?string $target_id = null,
        ?array $detection_ids = null,
        int $rules_revision = 0,
        ?string $label = null
    ): DataResponse {
        $uid = $this->uid();
        $actionName = $action !== '' ? $action : (string)$this->request->getParam('action', '');
        $target = $target_id !== null ? $target_id : (string)$this->request->getParam('target_id', '');
        $dets = $detection_ids !== null ? $detection_ids : $this->request->getParam('detection_ids', null);
        $rulesRev = $rules_revision !== 0 ? $rules_revision : (int)$this->request->getParam('rules_revision', 0);
        $lbl = $label !== null ? $label : $this->request->getParam('label', null);

        try {
            $result = $this->faces->action(
                $uid,
                $id,
                $actionName,
                $target !== '' ? $target : null,
                !empty($dets) ? $dets : null,
                $rulesRev,
                is_string($lbl) ? $lbl : null
            );
            $response = new DataResponse($result);
            $response->addHeader('Cache-Control', 'private, no-store');
            return $response;
        } catch (FaceConflictException $e) {
            return new DataResponse(['error' => $e->getMessage()], 409);
        } catch (OCSBadRequestException $e) {
            return new DataResponse(['error' => $e->getMessage()], 400);
        } catch (OCSNotFoundException $e) {
            return new DataResponse(['error' => $e->getMessage()], 404);
        } catch (\Throwable $e) {
            $this->logger?->error('Faces action error: ' . $e->getMessage(), ['exception' => $e]);
            return new DataResponse(['error' => 'Interner Serverfehler'], 500);
        }
    }

    #[NoAdminRequired]
    public function bulkAction(string $action = '', ?array $ids = null, int $rules_revision = 0): DataResponse {
        $uid = $this->uid();
        $actionName = $action !== '' ? $action : (string)$this->request->getParam('action', '');
        $groupIds = $ids !== null ? $ids : $this->request->getParam('ids', []);
        $rulesRev = $rules_revision !== 0 ? $rules_revision : (int)$this->request->getParam('rules_revision', 0);

        try {
            $response = new DataResponse($this->faces->bulkAction($uid, is_array($groupIds) ? $groupIds : [], $actionName, $rulesRev));
            $response->addHeader('Cache-Control', 'private, no-store');
            return $response;
        } catch (FaceConflictException $e) {
            return new DataResponse(['error' => $e->getMessage()], 409);
        } catch (OCSBadRequestException $e) {
            return new DataResponse(['error' => $e->getMessage()], 400);
        } catch (OCSNotFoundException $e) {
            return new DataResponse(['error' => $e->getMessage()], 404);
        } catch (\Throwable $e) {
            $this->logger?->error('Faces bulk action error: ' . $e->getMessage(), ['exception' => $e]);
            return new DataResponse(['error' => 'Interner Serverfehler'], 500);
        }
    }

    #[NoAdminRequired]
    public function sharedSortings(string $gallery = ''): DataResponse {
        $uid = $this->uid();
        $gallery = $gallery !== '' ? $gallery : (string)$this->request->getParam('gallery', '');
        try {
            $response = new DataResponse($this->faces->sharedSortings($uid, $gallery));
            $response->addHeader('Cache-Control', 'private, no-store');
            return $response;
        } catch (\Throwable $e) {
            $this->logger?->error('Faces sharedSortings error: ' . $e->getMessage(), ['exception' => $e]);
            return new DataResponse(['error' => 'Interner Serverfehler'], 500);
        }
    }

    #[NoAdminRequired]
    public function saveSharedSorting(
        string $mode = '',
        ?bool $enabled = null,
        string $source_user = '',
        string $gallery = ''
    ): DataResponse {
        $uid = $this->uid();
        $modeName = $mode !== '' ? $mode : (string)$this->request->getParam('mode', '');
        $galleryName = $gallery !== '' ? $gallery : (string)$this->request->getParam('gallery', '');
        $sourceUser = $source_user !== '' ? $source_user : (string)$this->request->getParam('source_user', '');
        $rawEnabled = $enabled !== null ? $enabled : $this->request->getParam('enabled', null);

        try {
            if ($modeName === 'adopt' || ($modeName === '' && $sourceUser !== '')) {
                $res = $this->faces->adoptSharedSorting($uid, $sourceUser, $galleryName);
            } else {
                $boolEnabled = is_bool($rawEnabled)
                    ? $rawEnabled
                    : in_array(strtolower((string)$rawEnabled), ['1', 'true', 'yes', 'on'], true);
                $res = $this->faces->setSharedSorting($uid, $boolEnabled);
            }
            $response = new DataResponse($res);
            $response->addHeader('Cache-Control', 'private, no-store');
            return $response;
        } catch (FaceConflictException $e) {
            return new DataResponse(['error' => $e->getMessage()], 409);
        } catch (OCSBadRequestException $e) {
            return new DataResponse(['error' => $e->getMessage()], 400);
        } catch (OCSNotFoundException $e) {
            return new DataResponse(['error' => $e->getMessage()], 404);
        } catch (\Throwable $e) {
            $this->logger?->error('Faces saveSharedSorting error: ' . $e->getMessage(), ['exception' => $e]);
            return new DataResponse(['error' => 'Interner Serverfehler'], 500);
        }
    }

    #[NoAdminRequired]
    public function reset(string $confirmation = ''): DataResponse {
        $uid = $this->uid();
        $conf = $confirmation !== '' ? $confirmation : (string)$this->request->getParam('confirmation', '');
        if ($conf !== 'faces') {
            return new DataResponse(['error' => 'Bestätigung "faces" erforderlich'], 400);
        }
        $res = $this->faces->reset($uid);
        $response = new DataResponse(['reset' => true, 'generation' => $res['generation']]);
        $response->addHeader('Cache-Control', 'private, no-store');
        return $response;
    }
}
