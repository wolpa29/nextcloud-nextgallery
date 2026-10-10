<?php
declare(strict_types=1);
namespace OCA\Nextgallery\Listener;
use OCP\EventDispatcher\{Event,IEventListener};
use OCP\User\Events\UserDeletedEvent;
use OCA\Nextgallery\Service\Library;
/** @implements IEventListener<UserDeletedEvent> */
class UserDeleted implements IEventListener {
 public function __construct(private Library $library,private \OCA\Nextgallery\AI\Service\Metadata $aiMetadata,private \OCA\Nextgallery\AI\Service\Embeddings $embeddings,private \OCA\Nextgallery\AI\Service\State $state) {}
 public function handle(Event $event):void {
  if($event instanceof UserDeletedEvent){$uid=$event->getUid();$this->library->forget($uid);$this->aiMetadata->reset($uid);$this->embeddings->reset($uid);$account=$this->state->forUser($uid);if($account->directory()!==$this->state->rootDirectory()){$account->setControl('pause');@unlink($account->directory().'/account.json');}}
 }
}
