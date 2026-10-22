<?php
declare(strict_types=1);
namespace OCA\Nextgallery\AppInfo;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\{IBootstrap,IRegistrationContext,IBootContext};
use OCP\User\Events\UserDeletedEvent;
use OCA\Nextgallery\Listener\UserDeleted;
class Application extends App implements IBootstrap {
 public const APP_ID = 'nextgallery';
 public function __construct(array $urlParams=[]) { parent::__construct(self::APP_ID,$urlParams); }
 public function register(IRegistrationContext $context):void {
  $context->registerCapability(\OCA\Nextgallery\Capabilities::class);
  $context->registerEventListener(UserDeletedEvent::class,UserDeleted::class);$context->registerSearchProvider(\OCA\Nextgallery\AI\Search\Provider::class);$context->registerEventListener(\OCA\Files\Event\LoadAdditionalScriptsEvent::class,\OCA\Nextgallery\AI\Listener\FilesListener::class);
  foreach([\OCP\Files\Events\Node\NodeCreatedEvent::class,\OCP\Files\Events\Node\NodeWrittenEvent::class,\OCP\Files\Events\Node\NodeDeletedEvent::class,\OCP\Files\Events\Node\NodeRenamedEvent::class,\OCP\Files\Events\Node\NodeCopiedEvent::class,\OCP\Share\Events\ShareCreatedEvent::class,\OCP\Share\Events\ShareAcceptedEvent::class,\OCP\Share\Events\ShareDeletedEvent::class,\OCP\Share\Events\ShareDeletedFromSelfEvent::class,\OCP\Share\Events\ShareMovedEvent::class] as $event)if(class_exists($event))$context->registerEventListener($event,\OCA\Nextgallery\Listener\SearchIndexChanged::class);
 }
 public function boot(IBootContext $context):void {}
}
