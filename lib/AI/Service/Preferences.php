<?php
declare(strict_types=1);
namespace OCA\Nextgallery\AI\Service;
use OCP\{IConfig,IUserSession};
class Preferences {
    public function __construct(private IConfig $config,private IUserSession $session) {}
    public function language(): string {
        $uid=$this->session->getUser()?->getUID();
        $p=$uid===null?[]:(json_decode($this->config->getUserValue($uid,'nextgallery','settings','{}'),true)??[]);
        $value=$p['language']??'system';
        if($value==='system')$value=$uid===null?'de':$this->config->getUserValue($uid,'core','lang','de');
        return str_starts_with($value,'de')?'de':'en';
    }
    public function saveLanguage(string $language): void {
        if(!in_array($language,['de','en'],true))throw new \InvalidArgumentException('Language must be de or en');
        $uid=$this->session->getUser()?->getUID();if($uid===null)throw new \RuntimeException('Authentication required');
        $p=json_decode($this->config->getUserValue($uid,'nextgallery','settings','{}'),true)??[];$p['language']=$language;
        $this->config->setUserValue($uid,'nextgallery','settings',json_encode($p,JSON_THROW_ON_ERROR));
    }
}
