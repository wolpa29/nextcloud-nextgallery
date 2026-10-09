<?php
declare(strict_types=1);
namespace OCA\Chronicle\Command;
use OCA\Chronicle\AI\Service\{Settings,State};
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface,InputOption};
use Symfony\Component\Console\Output\OutputInterface;
class AiSetup extends Command {
    public function __construct(private Settings $settings, private State $state) { parent::__construct(); }
    protected function configure(): void { $this->setName('chronicle:ai-setup')->setAliases(['media-ai:setup'])->setDescription('Create private local worker bootstrap')->addOption('url',null,InputOption::VALUE_REQUIRED)->addOption('ca-file',null,InputOption::VALUE_REQUIRED); }
    protected function execute(InputInterface $input, OutputInterface $output): int {
        $url=rtrim((string)$input->getOption('url'),'/'); $parts=parse_url($url);
        if (($parts['scheme']??'')!=='https' || !isset($parts['host']) || (isset($parts['user']) || isset($parts['pass'])) || isset($parts['query']) || isset($parts['fragment'])) { throw new \InvalidArgumentException('Canonical HTTPS Nextcloud URL required'); }
        $ca=(string)$input->getOption('ca-file'); if ($ca!=='' && !is_readable($ca)) { throw new \InvalidArgumentException('CA file unavailable'); }
        $this->state->atomic('bootstrap.json',['url'=>$url,'app_id'=>'chronicle','token'=>$this->settings->workerToken(),'ca_file'=>$ca?:null]);
        $output->writeln('Worker bootstrap created in '.$this->state->directory().' (private, no credentials printed)'); return 0;
    }
}
