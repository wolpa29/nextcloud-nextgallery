<?php
declare(strict_types=1);
namespace OCA\Chronicle\Settings;
use OCP\Settings\IIconSection;
use OCP\IURLGenerator;
class AiSection implements IIconSection {
    public function __construct(private IURLGenerator $url) {}
    public function getID(): string { return 'chronicle'; }
    public function getName(): string { return 'NextGallery · KI-Verarbeitung'; }
    public function getPriority(): int { return 80; }
    public function getIcon(): string { return $this->url->imagePath('chronicle','app.svg'); }
}
