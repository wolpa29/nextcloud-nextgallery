<?php
declare(strict_types=1);

namespace OCA\Nextgallery\Controller;

use OCP\IRequest;
use OCP\IUserManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\OCS\{OCSBadRequestException, OCSNotFoundException};
use OCA\Nextgallery\Exception\FaceConflictException;
use OCA\Nextgallery\AI\Service\{Settings, Inventory, Faces, State, FaceArtifacts};
use Psr\Log\LoggerInterface;

class FaceWorkerController extends Controller {
    public function __construct(
        IRequest $request,
        private Settings $settings,
        private Inventory $inventory,
        private Faces $faces,
        private ?IUserManager $userManager = null,
        private ?State $state = null,
        private ?LoggerInterface $logger = null,
        private ?FaceArtifacts $faceArtifacts = null
    ) {
        parent::__construct('nextgallery', $request);
    }

    private function denied(): ?DataResponse {
        if (!$this->settings->verifyWorker($this->request->getHeader('Authorization'))) {
            return new DataResponse(['error' => 'Worker authentication required'], 401);
        }
        $uid = $this->request->getHeader('X-Nextgallery-User');
        if ($uid === '') {
            $uid = $this->settings->get()['user_id'];
        }
        $userManager = $this->userManager ?? \OCP\Server::get(\OCP\IUserManager::class);
        if (!$userManager->userExists($uid)) {
            return new DataResponse(['error' => 'Account unavailable'], 404);
        }
        $this->settings = $this->settings->forUser($uid);
        $this->inventory = $this->inventory->forUser($uid);
        $this->faces = $this->faces->forUser($uid);
        return null;
    }

    #[PublicPage]
    #[NoCSRFRequired]
    public function state(int $cursor = 0, int $limit = 500): DataResponse {
        if ($denied = $this->denied()) {
            return $denied;
        }
        $uid = $this->settings->get()['user_id'];
        $data = $this->faces->state($uid, $cursor, $limit);
        $response = new DataResponse($data);
        $response->addHeader('Cache-Control', 'no-store');
        return $response;
    }

    #[PublicPage]
    #[NoCSRFRequired]
    public function artifactLookup(int $file_id = 0, string $etag = ''): DataResponse {
        if ($denied = $this->denied()) {
            return $denied;
        }
        $uid = $this->settings->get()['user_id'];
        $fileId = $file_id !== 0 ? $file_id : (int)$this->request->getParam('file_id', 0);
        $fileEtag = $etag !== '' ? $etag : (string)$this->request->getParam('etag', '');

        if ($fileId <= 0 || $fileEtag === '' || strlen($fileEtag) > 255) {
            return new DataResponse(['error' => 'Ungültige Dateiparameter'], 400);
        }

        $config = $this->settings->get();
        if (!($config['faces']['enabled'] ?? false)) {
            return new DataResponse(['error' => 'Personenerkennung nicht aktiviert'], 400);
        }

        try {
            $file = $this->inventory->allowedFile($fileId, 'faces');
        } catch (OCSNotFoundException | \OCP\Files\NotFoundException | \OCP\Files\NotPermittedException) {
            return new DataResponse(['error' => 'Datei nicht gefunden oder Ordner nicht aktiviert'], 404);
        } catch (\Throwable) {
            return new DataResponse(['error' => 'Zugriff verweigert oder Datei nicht verfügbar'], 404);
        }

        if ($file->getEtag() !== $fileEtag) {
            return new DataResponse(['error' => 'Bilddatei geändert (ETag-Konflikt)'], 409);
        }

        $mime = $file->getMimetype();
        if (!str_starts_with($mime, 'image/')) {
            return new DataResponse(['error' => 'Keine Bilddatei'], 400);
        }

        $faceArtifacts = $this->faceArtifacts ?? \OCP\Server::get(FaceArtifacts::class);
        $currentRev = $faceArtifacts->currentRevision();

        if (!$faceArtifacts->enabled()) {
            $resp = new DataResponse([
                'detections' => null,
                'inference_revision' => $currentRev,
                'disabled' => true,
            ]);
            $resp->addHeader('Cache-Control', 'no-store');
            return $resp;
        }

        $cached = $faceArtifacts->find($fileId, $fileEtag, $currentRev);
        $resp = new DataResponse([
            'detections' => $cached,
            'inference_revision' => $currentRev,
        ]);
        $resp->addHeader('Cache-Control', 'no-store');
        return $resp;
    }

    #[PublicPage]
    #[NoCSRFRequired]
    public function publish(
        int $file_id = 0,
        string $etag = '',
        string $epoch = '',
        int $generation = 0,
        int $rules_revision = 0,
        string $space = '',
        array $detections = [],
        string $inference_revision = ''
    ): DataResponse {
        if ($denied = $this->denied()) {
            return $denied;
        }
        $uid = $this->settings->get()['user_id'];
        $fileId = $file_id !== 0 ? $file_id : (int)$this->request->getParam('file_id', 0);
        $fileEtag = $etag !== '' ? $etag : (string)$this->request->getParam('etag', '');
        $fileEpoch = $epoch !== '' ? $epoch : (string)$this->request->getParam('epoch', '');
        $fileGen = $generation !== 0 ? $generation : (int)$this->request->getParam('generation', 0);
        $fileRules = $rules_revision !== 0 ? $rules_revision : (int)$this->request->getParam('rules_revision', 0);
        $fileSpace = $space !== '' ? $space : (string)$this->request->getParam('space', '');
        $dets = !empty($detections) ? $detections : (array)$this->request->getParam('detections', []);
        $inferenceRev = $inference_revision !== '' ? $inference_revision : (string)$this->request->getParam('inference_revision', '');

        if ($inferenceRev !== '' && $inferenceRev !== FaceArtifacts::revision($this->settings->get())) {
            return new DataResponse(['error' => 'Inference-Revision veraltet'], 409);
        }

        try {
            $canonical = $this->faces->publish(
                $uid,
                $fileId,
                $fileEtag,
                $fileEpoch,
                $fileGen,
                $fileRules,
                $fileSpace,
                $dets,
                $inferenceRev
            );
            $response = new DataResponse([
                'stored' => true,
                'file_id' => $fileId,
                'detections' => $canonical,
            ]);
            $response->addHeader('Cache-Control', 'no-store');
            return $response;
        } catch (FaceConflictException $e) {
            return new DataResponse(['error' => $e->getMessage()], 409);
        } catch (OCSBadRequestException $e) {
            return new DataResponse(['error' => $e->getMessage()], 400);
        } catch (OCSNotFoundException $e) {
            return new DataResponse(['error' => $e->getMessage()], 404);
        } catch (\Throwable $e) {
            $this->logger?->error('FaceWorkerController publish error: ' . $e->getMessage(), ['exception' => $e]);
            return new DataResponse(['error' => 'Interner Serverfehler'], 500);
        }
    }

    #[PublicPage]
    #[NoCSRFRequired]
    public function reset(): DataResponse {
        if ($denied = $this->denied()) {
            return $denied;
        }
        $uid = $this->settings->get()['user_id'];
        $control = $this->state?->forUser($uid)->forPipeline('faces')->control() ?? [];
        if (empty($control['reset_requested']) || empty($control['paused'])) {
            return new DataResponse(['error' => 'Explicit paused reset request required'], 409);
        }
        $res = $this->faces->reset($uid);
        $response = new DataResponse(['reset' => true, 'generation' => $res['generation']]);
        $response->addHeader('Cache-Control', 'no-store');
        return $response;
    }
}
