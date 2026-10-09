<?php
declare(strict_types=1);
namespace OCA\Nextgallery\Command;
use OCA\Nextgallery\Service\Library;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface,InputArgument,InputOption};
use Symfony\Component\Console\Output\OutputInterface;
class Index extends Command {
 public function __construct(private Library $library) {parent::__construct();}
 protected function configure():void {$this->setName('nextgallery:index')->setDescription('Index only this user’s selected NextGallery folders')->addArgument('user',InputArgument::REQUIRED)->addOption('restart',null,InputOption::VALUE_NONE);}
 protected function execute(InputInterface $input,OutputInterface $output):int {
  $uid=$input->getArgument('user');$restart=(bool)$input->getOption('restart');
  do {$s=$this->library->step($uid,$restart,400,15);$restart=false;$output->writeln(json_encode($s,JSON_UNESCAPED_SLASHES));} while($s['running']??false);
  return ($s['errors']??0)?1:0;
 }
}
