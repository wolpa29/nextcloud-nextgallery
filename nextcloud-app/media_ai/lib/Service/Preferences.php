<?php
declare(strict_types=1);
namespace OCA\MediaAI\Service;
use OCP\{IConfig,IUserSession};
class Preferences {
    public function __construct(private IConfig $config,private IUserSession $session) {}
    public function language(): string {
        $uid=$this->session->getUser()?->getUID();
        $value=$uid===null?'de':$this->config->getUserValue($uid,'media_ai','ui_language','de');
        return $value==='en'?'en':'de';
    }
    public function saveLanguage(string $language): void {
        if(!in_array($language,['de','en'],true))throw new \InvalidArgumentException('Language must be de or en');
        $uid=$this->session->getUser()?->getUID();if($uid===null)throw new \RuntimeException('Authentication required');
        $this->config->setUserValue($uid,'media_ai','ui_language',$language);
    }
}
