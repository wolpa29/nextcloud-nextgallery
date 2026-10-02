<?php
declare(strict_types=1);
namespace OCA\MediaAI\Controller;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\AppFramework\OCSController;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\OCS\OCSException;
use OCA\MediaAI\Service\Files;
use OCA\MediaAI\Service\Metadata;
class MetadataController extends OCSController {
    public function __construct(IRequest $request, private IUserSession $session, private Files $files, private Metadata $metadata) {
        parent::__construct('media_ai', $request);
    }
    private function uid(): string {
        $user = $this->session->getUser();
        if ($user === null) { throw new OCSException('Authentication required', 401); }
        return $user->getUID();
    }
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function resolve(string $path): DataResponse {
        $file = $this->files->byPath($this->uid(), $path);
        return new DataResponse(['file_id' => $file->getId(), 'etag' => $file->getEtag(), 'size' => $file->getSize(), 'mime' => $file->getMimeType()]);
    }
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function upsert(int $file_id, string $etag, string $sha256, string $revision, array $metadata, array $exif = []): DataResponse {
        // A browser form cannot set this custom header; authentication is still required.
        if ($this->request->getHeader('OCS-APIRequest') !== 'true') { throw new OCSException('OCS API header required', 403); }
        $uid = $this->uid();
        $data = Metadata::validate($file_id, $etag, $sha256, $revision, $metadata, $exif);
        $file = $this->files->byId($uid, $file_id);
        if (!hash_equals($file->getEtag(), $etag)) { throw new OCSException('File changed; re-analysis required', 409); }
        $this->metadata->upsert($uid, $data);
        return new DataResponse(['file_id' => $file_id, 'stored' => true]);
    }
}
