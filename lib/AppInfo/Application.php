<?php
declare(strict_types=1);
namespace OCA\Chronicle\AppInfo;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\{IBootstrap,IRegistrationContext,IBootContext};
use OCP\User\Events\UserDeletedEvent;
use OCA\Chronicle\Listener\UserDeleted;
class Application extends App implements IBootstrap {
 public const APP_ID = 'chronicle';
 public function __construct(array $urlParams=[]) { parent::__construct(self::APP_ID,$urlParams); }
 public function register(IRegistrationContext $context):void {$context->registerEventListener(UserDeletedEvent::class,UserDeleted::class);$context->registerSearchProvider(\OCA\Chronicle\AI\Search\Provider::class);$context->registerEventListener(\OCA\Files\Event\LoadAdditionalScriptsEvent::class,\OCA\Chronicle\AI\Listener\FilesListener::class);}
 public function boot(IBootContext $context):void {}
}
