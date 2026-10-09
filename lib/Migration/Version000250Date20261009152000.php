<?php
declare(strict_types=1);
namespace OCA\Nextgallery\Migration;
use Closure;
use OCP\IDBConnection;
use OCP\Migration\{SimpleMigrationStep,IOutput};

/** Copy analysis bytes and row IDs without regenerating metadata or touching the worker queue. */
class Version000250Date20261009152000 extends SimpleMigrationStep {
 public function __construct(private IDBConnection $db) {}
 public function postSchemaChange(IOutput $output,Closure $schemaClosure,array $options):void {
  $schema=$schemaClosure();
  if (!$schema->hasTable('media_ai_meta')) return;
  $q=$this->db->getQueryBuilder();
  $columns=array_keys($schema->getTable('media_ai_meta')->getColumns());
  $fields=implode(',',array_map(fn($c)=>$q->getColumnName($c),$columns));
  $select=implode(',',array_map(fn($c)=>'o.'.$q->getColumnName($c),$columns));
  $source=$q->getTableName('media_ai_meta');$target=$q->getTableName('nextgallery_ai_meta');
  $this->db->executeStatement("INSERT INTO $target ($fields) SELECT $select FROM $source o WHERE NOT EXISTS (SELECT 1 FROM $target n WHERE n.user_id=o.user_id AND n.file_id=o.file_id)");
 }
}
