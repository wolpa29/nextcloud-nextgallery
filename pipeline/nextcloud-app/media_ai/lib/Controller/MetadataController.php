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
    public function __construct(IRequest $request, private IUserSession $session, private Files $files, private Metadata $metadata, private ?\OCP\Files\IRootFolder $root = null, private ?\OCA\MediaAI\Service\Library $library = null, private ?\OCA\MediaAI\Service\Settings $settings = null) {
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
    public function show(int $file_id): DataResponse {
        $uid=$this->uid(); $file=$this->files->byId($uid,$file_id);
        $row=$this->metadata->get($uid,$file_id);
        if (!$row) { return new DataResponse(['available'=>false]); }
        if (!hash_equals($file->getEtag(),$row['etag'])) { return new DataResponse(['available'=>false,'stale'=>true]); }
        return new DataResponse(['available'=>true,'updated_at'=>$row['updated_at'],'metadata'=>json_decode($row['metadata_json'],true,64,JSON_THROW_ON_ERROR)]);
    }
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function statuses(array $file_ids): DataResponse {
        $uid=$this->uid();
        if (!array_is_list($file_ids) || count($file_ids)>100) { throw new OCSException('Maximum 100 file IDs',400); }
        foreach($file_ids as $id) { if(!is_int($id) || $id<1) { throw new OCSException('Invalid file ID',400); } }
        $home=$this->root->getUserFolder($uid); $nodes=[];
        foreach(array_unique($file_ids) as $id) {
            try {
                foreach($home->getById($id) as $node) {
                    if(!$node->isReadable()) { continue; }
                    $folder=$node instanceof \OCP\Files\Folder;
                    if(!$folder && !preg_match('~^(image|video)/~',$node->getMimeType())) { break; }
                    $nodes[]=['file_id'=>$id,'path'=>$home->getRelativePath($node->getPath()),'etag'=>$node->getEtag(),'folder'=>$folder]; break;
                }
            } catch (\OCP\Files\NotFoundException|\OCP\Files\NotPermittedException $e) { continue; }
        }
        $stored=$this->metadata->statuses($uid,array_column($nodes,'file_id'));
        $selected=$uid===($this->settings?->get()['user_id']??null) ? $this->library->nodeStatuses($nodes) : [];
        $statuses=[];
        foreach($nodes as $node) {
            $row=$stored[$node['file_id']]??null;
            $statuses[$node['file_id']]=$selected[$node['file_id']]??['status'=>$node['folder']?'unselected':($row?($row['etag']===$node['etag']?'done':'changed'):'unselected'),'updated_at'=>$row['updated_at']??null];
        }
        return new DataResponse(['statuses'=>$statuses]);
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
