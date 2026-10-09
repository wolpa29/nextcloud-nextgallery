<?php
declare(strict_types=1);
namespace OCA\Nextgallery\Migration;
use Closure;
use OCP\{IDBConnection,IConfig};
use OCP\DB\ISchemaWrapper;
use OCP\Migration\{SimpleMigrationStep,IOutput};

/** Retire old names only after every legacy catalog row is identical in its new table. */
class Version000300Date20261009153000 extends SimpleMigrationStep {
 public function __construct(private IDBConnection $db,private IConfig $config) {}
 private function fingerprint(string $table,array $columns,array $order):array {
  $q=$this->db->getQueryBuilder();$q->select(...$columns)->from($table);
  foreach ($order as $column) $q->addOrderBy($column);
  $r=$q->executeQuery();$hash=hash_init('sha256');$count=0;
  try {while($row=$r->fetch()) {hash_update($hash,json_encode($row,JSON_THROW_ON_ERROR)."\n");$count++;}} finally {$r->closeCursor();}
  return [$count,hash_final($hash)];
 }
 public function postSchemaChange(IOutput $output,Closure $schemaClosure,array $options):void {
  $schema=$schemaClosure();$retire=[];
  foreach (['chronicle_media'=>'nextgallery_media','chronicle_users'=>'nextgallery_users','media_ai_meta'=>'nextgallery_ai_meta'] as $old=>$new) {
   if (!$schema->hasTable($old)) continue;
   $columns=array_keys($schema->getTable($old)->getColumns());$order=$old==='chronicle_users'?['user_id']:['user_id','file_id'];
   if ($this->fingerprint($old,$columns,$order)!==$this->fingerprint($new,$columns,$order)) throw new \RuntimeException('Legacy gallery data differs; refusing to retire its table.');
   $retire[]=$old;
  }
  foreach ($retire as $table) {
   $this->db->executeStatement('DROP TABLE '.$this->db->getQueryBuilder()->getTableName($table));
  }
  // The installer has already registered the canonical job; avoid a duplicate.
  $q=$this->db->getQueryBuilder();$q->delete('jobs')->where($q->expr()->eq('class',$q->createNamedParameter('OCA\\Chronicle\\BackgroundJob\\IndexJob')))->executeStatement();
  foreach (['chronicle','media_ai'] as $app) {
   $q=$this->db->getQueryBuilder();$q->delete('preferences')->where($q->expr()->eq('appid',$q->createNamedParameter($app)))->executeStatement();
   // Retire configuration only, without invoking old uninstall hooks.
   $this->config->deleteAppValues($app);
   $q=$this->db->getQueryBuilder();$q->delete('migrations')->where($q->expr()->eq('app',$q->createNamedParameter($app)))->executeStatement();
  }
 }
}
