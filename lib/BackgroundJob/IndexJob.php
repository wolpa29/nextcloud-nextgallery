<?php
declare(strict_types=1);
namespace OCA\Chronicle\BackgroundJob;
use OCP\BackgroundJob\TimedJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCA\Chronicle\Service\Library;
class IndexJob extends TimedJob {
 public function __construct(ITimeFactory $time,private Library $library) {parent::__construct($time);$this->setInterval(60);$this->setTimeSensitivity(self::TIME_INSENSITIVE);}
 protected function run($argument):void {
  $deadline=microtime(true)+40;
  foreach($this->library->users() as $uid) {
   try {$s=$this->library->state($uid);$restart=!($s['running']??false) && time()-($s['completed']??0)>900;
    $this->library->step($uid,$restart,400,8);
   } catch (\OCP\Lock\LockedException) {}
   if (microtime(true)>$deadline) break;
  }
 }
}
