<?php
declare(strict_types=1);
namespace OCA\MediaAI\Search;
use OCP\IUser;
use OCP\IURLGenerator;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCA\MediaAI\Service\Files;
use OCA\MediaAI\Service\Metadata;
use OCA\MediaAI\Service\Preferences;
class Provider implements IProvider {
    public function __construct(private Metadata $metadata, private Files $files, private IURLGenerator $urls, private ?Preferences $preferences = null) {}
    public function getId(): string { return 'media_ai'; }
    public function getName(): string { return $this->preferences?->language()==='en'?'Image content and OCR':'Bildinhalt und OCR'; }
    public function getOrder(string $route, array $routeParameters): int { return 20; }
    public function search(IUser $user, ISearchQuery $query): SearchResult {
        $cursor = max(0, (int)$query->getCursor());
        $limit = min(20, max(1, $query->getLimit()));
        $rows = $this->metadata->search($user->getUID(), $query->getTerm(), $cursor, $limit);
        $entries = [];
        foreach ($rows as $row) {
            $cursor = (int)$row['id'];
            try { $file = $this->files->byId($user->getUID(), (int)$row['file_id']); }
            catch (OCSNotFoundException|\OCP\Files\NotFoundException|\OCP\Files\NotPermittedException $e) { continue; }
            // Old/deleted/inaccessible versions never appear, even before the next daily run.
            if (!hash_equals($file->getEtag(), $row['etag'])) { continue; }
            $path = $this->files->relativePath($user->getUID(), $file);
            $url = $this->urls->linkToRoute('files.View.showFile', ['fileid' => $file->getId()]);
            $original=isset($row['metadata_json'])?json_decode($row['metadata_json'],true):null;
            $snippet = mb_substr(($original['analysis']['vision']['description']??$row['description']) . ' · ' . ($original['analysis']['ocr']['text']??$row['ocr_text']), 0, 600);
            $entries[] = new SearchResultEntry('', $file->getName(), $snippet, $url, ($original['analysis']['vision']['type'] ?? '') === 'video' ? 'icon-video' : 'icon-image');
        }
        if (count($rows) < $limit) { return SearchResult::complete($this->getName(), $entries); }
        return SearchResult::paginated($this->getName(), $entries, $cursor);
    }
}
