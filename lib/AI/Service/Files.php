<?php
declare(strict_types=1);
namespace OCA\Nextgallery\AI\Service;
use OCP\Files\IRootFolder;
use OCP\Files\File;
use OCP\AppFramework\OCS\OCSNotFoundException;
class Files {
    public function __construct(private IRootFolder $root) {}
    public function byPath(string $uid, string $path): File {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\') || preg_match('~(^|/)(\.|\.\.)(/|$)~', $path)) {
            throw new OCSNotFoundException('Invalid path');
        }
        try { $node = $this->root->getUserFolder($uid)->get(ltrim($path, '/')); }
        catch (\OCP\Files\NotFoundException|\OCP\Files\NotPermittedException $e) { throw new OCSNotFoundException('File unavailable'); }
        return $this->readableImage($node);
    }
    public function byId(string $uid, int $id): File {
        foreach ($this->root->getUserFolder($uid)->getById($id) as $node) {
            if ($node instanceof File && $node->isReadable() && (str_starts_with($node->getMimeType(), 'image/') || str_starts_with($node->getMimeType(), 'video/'))) { return $node; }
        }
        throw new OCSNotFoundException('Image unavailable');
    }
    private function readableImage($node): File {
        if (!$node instanceof File || !$node->isReadable() || !(str_starts_with($node->getMimeType(), 'image/') || str_starts_with($node->getMimeType(), 'video/'))) {
            throw new OCSNotFoundException('Readable image required');
        }
        return $node;
    }
    public function relativePath(string $uid, File $file): string {
        return (string)$this->root->getUserFolder($uid)->getRelativePath($file->getPath());
    }
}
