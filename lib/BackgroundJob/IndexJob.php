<?php
declare(strict_types=1);
namespace OCA\Nextgallery\BackgroundJob;
use OCP\BackgroundJob\TimedJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IUserManager;
use OCA\Nextgallery\Service\Library;

class IndexJob extends TimedJob {
 public function __construct(ITimeFactory $time,private Library $library,private ?\OCA\Nextgallery\Service\SearchIndex $searchIndex=null,private ?\Psr\Log\LoggerInterface $logger=null,private ?IConfig $config=null,private ?IUserManager $userManager=null) {
  parent::__construct($time);
  $this->setInterval(60);
  $this->setTimeSensitivity(self::TIME_INSENSITIVE);
 }

 private function now():float {
  if (method_exists($this->time,'now')) {
   return (float)$this->time->now()->format('U.u');
  }
  return method_exists($this->time,'getTime')?(float)$this->time->getTime():(float)microtime(true);
 }

 private function nowSec():int {
  return method_exists($this->time,'getTime')?$this->time->getTime():time();
 }

 protected function run($argument):void {
  $now=$this->now();
  $deadline=$now+40;
  try{$this->searchIndex?->cleanup();}catch(\Throwable $e){$this->logger?->warning('NextGallery search cleanup failed',['app'=>'nextgallery','exception'=>$e]);}
  $allUsers=$this->library->users();
  $requested=$this->searchIndex?->changes()->requestedBuilds()??[];
  foreach($requested as $reqUid){
   if(!in_array($reqUid,$allUsers,true)){
    if($this->userManager!==null&&!$this->userManager->userExists($reqUid)){
     $this->searchIndex?->changes()->clearBuildRequest($reqUid);
    }else{
     $allUsers[]=$reqUid;
    }
   }
  }
  if(empty($allUsers))return;

  // Fair persistent round-robin: rotate queue so subsequent accounts are not starved
  $lastUid=$this->config?->getAppValue('nextgallery','cron_last_user','');
  if($lastUid!==''&&($idx=array_search($lastUid,$allUsers,true))!==false){
   $users=array_merge(array_slice($allUsers,$idx+1),array_slice($allUsers,0,$idx+1));
  }else{
   $users=$allUsers;
  }

  foreach($users as $uid) {
   try {
    $s=$this->library->state($uid);
    $restart=!($s['running']??false) && ($this->nowSec()-($s['completed']??0))>900;
    $this->library->step($uid,$restart,400,8);
    $this->searchIndex?->ensure($uid);
   } catch (\OCP\Lock\LockedException) {} catch(\Throwable $e){$this->logger?->warning('NextGallery search index update failed',['app'=>'nextgallery','exception'=>$e]);}
   $this->config?->setAppValue('nextgallery','cron_last_user',$uid);
   if($this->now()>$deadline)break;
  }
 }
}
