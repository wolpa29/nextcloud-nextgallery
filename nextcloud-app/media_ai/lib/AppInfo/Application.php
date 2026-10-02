<?php
declare(strict_types=1);
namespace OCA\MediaAI\AppInfo;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCA\MediaAI\Search\Provider;
class Application extends App implements IBootstrap {
    public function __construct(array $urlParams = []) { parent::__construct('media_ai', $urlParams); }
    public function register(IRegistrationContext $context): void { $context->registerSearchProvider(Provider::class); }
    public function boot(IBootContext $context): void {}
}
