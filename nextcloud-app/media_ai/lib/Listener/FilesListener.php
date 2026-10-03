<?php
declare(strict_types=1);
namespace OCA\MediaAI\Listener;
use OCP\EventDispatcher\{Event,IEventListener};
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCP\Util;
class FilesListener implements IEventListener {
    public function handle(Event $event): void {
        if (!$event instanceof LoadAdditionalScriptsEvent) { return; }
        Util::addScript('media_ai','i18n'); Util::addScript('media_ai','image-metadata'); Util::addInitScript('media_ai','files-status'); Util::addStyle('media_ai','admin');
    }
}
