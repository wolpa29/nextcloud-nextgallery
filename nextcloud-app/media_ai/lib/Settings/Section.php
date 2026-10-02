<?php
declare(strict_types=1);
namespace OCA\MediaAI\Settings;
use OCP\Settings\IIconSection;
use OCP\IURLGenerator;
class Section implements IIconSection {
    public function __construct(private IURLGenerator $url) {}
    public function getID(): string { return 'media_ai'; }
    public function getName(): string { return 'Media AI'; }
    public function getPriority(): int { return 80; }
    public function getIcon(): string { return $this->url->imagePath('media_ai','app.svg'); }
}
