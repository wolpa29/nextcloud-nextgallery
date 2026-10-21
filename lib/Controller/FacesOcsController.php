<?php
declare(strict_types=1);

namespace OCA\Nextgallery\Controller;

use OCP\IRequest;
use OCP\IUserSession;
use OCP\AppFramework\OCSController;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\OCS\{OCSBadRequestException, OCSForbiddenException, OCSNotFoundException};
use OCA\Nextgallery\Exception\FaceConflictException;
use OCA\Nextgallery\AI\Service\Faces;
use OCA\Nextgallery\Service\Library;
use Psr\Log\LoggerInterface;

/**
 * OCS adapter controller for NextGallery faces API.
 * Ensures OCS envelope wrapping when accessed via OCS endpoints.
 */
class FacesOcsController extends OCSController {
    public function __construct(
        IRequest $request,
        private IUserSession $session,
        private Faces $faces,
        private Library $library,
        private ?LoggerInterface $logger = null
    ) {
        parent::__construct('nextgallery', $request);
    }

    private function response(array $data): DataResponse {
        $response = new DataResponse($data);
        $response->addHeader('Cache-Control', 'private, no-store');
        return $response;
    }

    private function uid(): string {
        if ($this->request->getHeader('OCS-APIRequest') !== 'true') {
            throw new OCSForbiddenException('OCS API header required');
        }
        $user = $this->session->getUser();
        if ($user === null) {
            throw new OCSForbiddenException('Anmeldung erforderlich');
        }
        return $user->getUID();
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
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
        return $this->response($data);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
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
        $data = $this->faces->media($uid, $id, $gallery, $cursor, $limit, $filter);
        return $this->response($data);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function similar(string $id, string $gallery = ''): DataResponse {
        $uid = $this->uid();
        $gallery = $gallery !== '' ? $gallery : (string)$this->request->getParam('gallery', '');
        return $this->response($this->faces->similar($uid, $id, $gallery));
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
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

        $result = $this->faces->action(
            $uid,
            $id,
            $actionName,
            $target !== '' ? $target : null,
            !empty($dets) ? $dets : null,
            $rulesRev,
            is_string($lbl) ? $lbl : null
        );
        return $this->response($result);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function bulkAction(string $action = '', ?array $ids = null, int $rules_revision = 0, ?string $target_id = null): DataResponse {
        $uid = $this->uid();
        $actionName = $action !== '' ? $action : (string)$this->request->getParam('action', '');
        $groupIds = $ids !== null ? $ids : $this->request->getParam('ids', []);
        $rulesRev = $rules_revision !== 0 ? $rules_revision : (int)$this->request->getParam('rules_revision', 0);
        $targetId = $target_id !== null && $target_id !== '' ? $target_id : (string)($this->request->getParam('target_id', '') ?: $this->request->getParam('target', ''));
        return $this->response($this->faces->bulkAction($uid, is_array($groupIds) ? $groupIds : [], $actionName, $rulesRev, $targetId !== '' ? $targetId : null));
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function sharedSortings(string $gallery = ''): DataResponse {
        $uid = $this->uid();
        $gallery = $gallery !== '' ? $gallery : (string)$this->request->getParam('gallery', '');
        return $this->response($this->faces->sharedSortings($uid, $gallery));
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
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

        if ($modeName === 'adopt' || ($modeName === '' && $sourceUser !== '')) {
            return $this->response($this->faces->adoptSharedSorting($uid, $sourceUser, $galleryName));
        }
        $boolEnabled = is_bool($rawEnabled)
            ? $rawEnabled
            : in_array(strtolower((string)$rawEnabled), ['1', 'true', 'yes', 'on'], true);
        return $this->response($this->faces->setSharedSorting($uid, $boolEnabled));
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function reset(string $confirmation = ''): DataResponse {
        $uid = $this->uid();
        $conf = $confirmation !== '' ? $confirmation : (string)$this->request->getParam('confirmation', '');
        if ($conf !== 'faces') {
            throw new OCSBadRequestException('Bestätigung "faces" erforderlich');
        }
        $res = $this->faces->reset($uid);
        return $this->response(['reset' => true, 'generation' => $res['generation']]);
    }
}
