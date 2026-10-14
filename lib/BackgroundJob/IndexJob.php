<?php
declare(strict_types=1);
namespace OCA\Nextgallery\BackgroundJob;
use OCP\BackgroundJob\TimedJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCA\Nextgallery\Service\Library;
class IndexJob extends TimedJob {
 public function __construct(ITimeFactory $time,private Library $library,private ?\OCA\Nextgallery\Service\SearchIndex $searchIndex=null,private ?\Psr\Log\LoggerInterface $logger=null) {parent::__construct($time);$this->setInterval(60);$this->setTimeSensitivity(self::TIME_INSENSITIVE);}
 protected function run($argument):void {
  $deadline=microtime(true)+40;
  try{$this->searchIndex?->cleanup();}catch(\Throwable $e){$this->logger?->warning('NextGallery search cleanup failed',['app'=>'nextgallery','exception'=>$e]);}
  foreach($this->library->users() as $uid) {
   try {$s=$this->library->state($uid);$restart=!($s['running']??false) && time()-($s['completed']??0)>900;
    $this->library->step($uid,$restart,400,8);
    $this->searchIndex?->ensure($uid);
   } catch (\OCP\Lock\LockedException) {} catch(\Throwable $e){$this->logger?->warning('NextGallery search index update failed',['app'=>'nextgallery','exception'=>$e]);}
   if (microtime(true)>$deadline) break;
  }
 }
}
