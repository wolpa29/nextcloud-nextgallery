<?php
declare(strict_types=1);
namespace OCA\Nextgallery\Settings;
use OCP\Settings\ISettings;
use OCP\AppFramework\Http\TemplateResponse;
class AiAdmin implements ISettings {
    public function getForm(): TemplateResponse { return new TemplateResponse('nextgallery','ai-admin'); }
    public function getSection(): string { return 'nextgallery'; }
    public function getPriority(): int { return 10; }
}
