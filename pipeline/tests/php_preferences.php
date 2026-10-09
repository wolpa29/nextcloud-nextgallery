<?php
declare(strict_types=1);
namespace OCP {interface IConfig {}interface IUserSession {}}
namespace {
require __DIR__.'/../nextcloud-app/media_ai/lib/Service/Preferences.php';
class User {public function __construct(public string $uid){}public function getUID(){return $this->uid;}}
class Session implements \OCP\IUserSession {public ?string $uid='paul';public function getUser(){return $this->uid===null?null:new User($this->uid);}}
class Config implements \OCP\IConfig {public array $values=[];public function getUserValue($uid,$app,$key,$default){return $this->values[$uid][$key]??$default;}public function setUserValue($uid,$app,$key,$value){$this->values[$uid][$key]=$value;}}
$s=new Session();$c=new Config();$p=new \OCA\MediaAI\Service\Preferences($c,$s);$checks=0;function check($v){global $checks;$checks++;if(!$v)throw new \Exception('assertion failed');}
check($p->language()==='de');$p->saveLanguage('en');check($p->language()==='en');$s->uid='bob';check($p->language()==='de');$s->uid='paul';check($p->language()==='en');try{$p->saveLanguage('invalid');throw new \Exception('invalid language accepted');}catch(\InvalidArgumentException $e){check($p->language()==='en');}$s->uid=null;try{$p->saveLanguage('de');throw new \Exception('anonymous save accepted');}catch(\RuntimeException $e){check($p->language()==='de');}
echo "$checks PHP language preference assertions passed\n";
}
