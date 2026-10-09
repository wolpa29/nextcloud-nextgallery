<?php
declare(strict_types=1);
namespace OCA\Nextgallery\Migration;
use Closure;
use OCP\{IConfig,IDBConnection};
use OCP\Migration\{SimpleMigrationStep,IOutput};

/** Adopt configuration before any new-app defaults or worker credentials are created. */
class Version000000Date20261009150000 extends SimpleMigrationStep {
 public function __construct(private IConfig $config,private IDBConnection $db) {}
 public function postSchemaChange(IOutput $output,Closure $schemaClosure,array $options):void {
  foreach ($this->config->getAppKeys('chronicle') as $key) {
   if (in_array($key,['enabled','installed_version','types'],true)) continue;
   if ($this->config->getAppValue('nextgallery',$key,"\x01")==="\x01")
    $this->config->setAppValue('nextgallery',$key,$this->config->getAppValue('chronicle',$key));
  }
  $q=$this->db->getQueryBuilder();
  $r=$q->select('userid','configkey','configvalue')->from('preferences')->where($q->expr()->eq('appid',$q->createNamedParameter('chronicle')))->executeQuery();
  try {while ($row=$r->fetch()) {
   if ($this->config->getUserValue($row['userid'],'nextgallery',$row['configkey'],"\x01")==="\x01")
    $this->config->setUserValue($row['userid'],'nextgallery',$row['configkey'],$row['configvalue']);
  }} finally {$r->closeCursor();}
  $q=$this->db->getQueryBuilder();
  $r=$q->select('userid','configvalue')->from('preferences')->where($q->expr()->eq('appid',$q->createNamedParameter('media_ai')))->andWhere($q->expr()->eq('configkey',$q->createNamedParameter('ui_language')))->executeQuery();
  try {while ($row=$r->fetch()) {
   if ($this->config->getUserValue($row['userid'],'nextgallery','ai_ui_language',"\x01")==="\x01")
    $this->config->setUserValue($row['userid'],'nextgallery','ai_ui_language',$row['configvalue']);
  }} finally {$r->closeCursor();}
 }
}
