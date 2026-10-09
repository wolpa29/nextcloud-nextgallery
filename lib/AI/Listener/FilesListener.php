<?php
declare(strict_types=1);
namespace OCA\Chronicle\AI\Listener;
use OCP\EventDispatcher\{Event,IEventListener};
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCP\Util;
class FilesListener implements IEventListener {
    public function __construct(private \OCP\IConfig $config) {}
    public function handle(Event $event): void {
        if (!$event instanceof LoadAdditionalScriptsEvent) { return; }
        Util::addScript('chronicle','ai-i18n'); Util::addStyle('chronicle','ai-admin');
        if ((int)$this->config->getSystemValueString('version') >= 35) {
            Util::addInitScript('chronicle','ai-files-status-v4');
        } else {
            Util::addScript('chronicle','ai-image-metadata'); Util::addInitScript('chronicle','ai-files-status');
        }
    }
}
