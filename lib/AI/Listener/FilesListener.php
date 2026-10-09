<?php
declare(strict_types=1);
namespace OCA\Nextgallery\AI\Listener;
use OCP\EventDispatcher\{Event,IEventListener};
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCP\Util;
class FilesListener implements IEventListener {
    public function __construct(private \OCP\IConfig $config) {}
    public function handle(Event $event): void {
        if (!$event instanceof LoadAdditionalScriptsEvent) { return; }
        Util::addScript('nextgallery','ai-i18n'); Util::addStyle('nextgallery','ai-admin');
        if ((int)$this->config->getSystemValueString('version') >= 35) {
            Util::addInitScript('nextgallery','ai-files-status-v4');
        } else {
            Util::addScript('nextgallery','ai-image-metadata'); Util::addInitScript('nextgallery','ai-files-status');
        }
    }
}
