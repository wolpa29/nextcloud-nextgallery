<?php
declare(strict_types=1);

namespace OCA\Nextgallery\Controller;

use OCP\{IRequest, IUserSession};
use OCP\AppFramework\OCSController;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attribute\{NoAdminRequired, NoCSRFRequired};
use OCP\AppFramework\OCS\{OCSBadRequestException, OCSForbiddenException};
use OCA\Nextgallery\Exception\FaceConflictException;
use OCA\Nextgallery\Service\NativePeople;

class NativePeopleController extends OCSController {
    public function __construct(
        IRequest $request,
        private IUserSession $session,
        private NativePeople $peopleService
    ) {
        parent::__construct('nextgallery', $request);
    }

    private function uid(): string {
        if ($this->request->getHeader('OCS-APIRequest') !== 'true') {
            throw new OCSForbiddenException('OCS API header required');
        }
        $user = $this->session->getUser();
        if ($user === null) {
            throw new OCSForbiddenException('Authentication required');
        }
        return $user->getUID();
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function subjects(array $roots = [], int $cursor = 0, int $limit = 60, string $snapshot_token = ''): DataResponse {
        $uid = $this->uid();
        try {
            $data = $this->peopleService->subjects($uid, $roots, $cursor, $limit, $snapshot_token);
            return new DataResponse($data, 200, ['Cache-Control' => 'private, no-store']);
        } catch (FaceConflictException $e) {
            return new DataResponse(['error' => $e->getMessage()], 409, ['Cache-Control' => 'private, no-store']);
        } catch (\InvalidArgumentException $e) {
            throw new OCSBadRequestException($e->getMessage());
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function media(
        array $roots = [],
        string $subject_id = '',
        int $cursor = 0,
        int $limit = 60,
        string $kind = '',
        string $period = '',
        string $mode = 'folder',
        string $timezone = 'UTC',
        string $order = 'desc'
    ): DataResponse {
        $uid = $this->uid();
        try {
            $data = $this->peopleService->media($uid, $subject_id, $roots, $cursor, $limit, $kind, $period, $mode, $timezone, $order);
            return new DataResponse($data, 200, ['Cache-Control' => 'private, no-store']);
        } catch (\InvalidArgumentException $e) {
            throw new OCSBadRequestException($e->getMessage());
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function snapshot(
        array $roots = [],
        int $cursor = 0,
        int $limit = 200,
        string $snapshot_token = ''
    ): DataResponse {
        $uid = $this->uid();
        try {
            $data = $this->peopleService->snapshot($uid, $roots, $cursor, $limit, $snapshot_token);
            return new DataResponse($data, 200, ['Cache-Control' => 'private, no-store']);
        } catch (FaceConflictException $e) {
            return new DataResponse(['error' => $e->getMessage()], 409, ['Cache-Control' => 'private, no-store']);
        } catch (\InvalidArgumentException $e) {
            throw new OCSBadRequestException($e->getMessage());
        }
    }
}
