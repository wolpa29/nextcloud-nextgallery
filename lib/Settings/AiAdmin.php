<?php
declare(strict_types=1);
namespace OCA\Chronicle\Settings;
use OCP\Settings\ISettings;
use OCP\AppFramework\Http\TemplateResponse;
class AiAdmin implements ISettings {
    public function getForm(): TemplateResponse { return new TemplateResponse('chronicle','ai-admin'); }
    public function getSection(): string { return 'chronicle'; }
    public function getPriority(): int { return 10; }
}
