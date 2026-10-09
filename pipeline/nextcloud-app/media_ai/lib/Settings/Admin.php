<?php
declare(strict_types=1);
namespace OCA\MediaAI\Settings;
use OCP\Settings\ISettings;
use OCP\AppFramework\Http\TemplateResponse;
class Admin implements ISettings {
    public function getForm(): TemplateResponse { return new TemplateResponse('media_ai','admin'); }
    public function getSection(): string { return 'media_ai'; }
    public function getPriority(): int { return 10; }
}
