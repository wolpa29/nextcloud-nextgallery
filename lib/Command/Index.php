<?php
declare(strict_types=1);
namespace OCA\Nextgallery\Command;
use OCA\Nextgallery\Service\Library;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface,InputArgument,InputOption};
use Symfony\Component\Console\Output\OutputInterface;
class Index extends Command {
 public function __construct(private Library $library,private ?\OCA\Nextgallery\Service\SearchIndex $searchIndex=null) {parent::__construct();}
 protected function configure():void {$this->setName('nextgallery:index')->setDescription('Index only this user’s selected NextGallery folders')->addArgument('user',InputArgument::REQUIRED)->addOption('restart',null,InputOption::VALUE_NONE);}
 protected function execute(InputInterface $input,OutputInterface $output):int {
  $uid=$input->getArgument('user');$restart=(bool)$input->getOption('restart');
  do {$s=$this->library->step($uid,$restart,400,15);$restart=false;$output->writeln(json_encode($s,JSON_UNESCAPED_SLASHES));} while($s['running']??false);
  if($this->searchIndex){try{$this->searchIndex->ensure($uid);$output->writeln(json_encode(['search_index'=>$this->searchIndex->status($uid)],JSON_THROW_ON_ERROR));}catch(\Throwable){$output->writeln('<error>Search index unavailable. Check the Python/SQLite runtime and server log.</error>');return 1;}}
  return ($s['errors']??0)?1:0;
 }
}
