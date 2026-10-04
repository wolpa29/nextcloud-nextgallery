<?php
declare(strict_types=1);
namespace OCA\Chronicle\Listener;
use OCP\EventDispatcher\{Event,IEventListener};
use OCP\User\Events\UserDeletedEvent;
use OCA\Chronicle\Service\Library;
/** @implements IEventListener<UserDeletedEvent> */
class UserDeleted implements IEventListener {
 public function __construct(private Library $library) {}
 public function handle(Event $event):void {
  if($event instanceof UserDeletedEvent)$this->library->forget($event->getUid());
 }
}
