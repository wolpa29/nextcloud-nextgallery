<?php
declare(strict_types=1);
namespace OCA\Nextgallery\Migration;
use Closure;
use OCP\IDBConnection;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\{SimpleMigrationStep,IOutput};
class Version000200Date20261009113000 extends SimpleMigrationStep {
 public function __construct(private IDBConnection $db) {}
 public function changeSchema(IOutput $output,Closure $schemaClosure,array $options):?ISchemaWrapper {
  $schema=$schemaClosure();$table=$schema->getTable('nextgallery_media');
  foreach (['name_text','path_text'] as $column) if (!$table->hasColumn($column)) $table->addColumn($column,'text',['notnull'=>false]);
  return $schema;
 }
 public function postSchemaChange(IOutput $output,Closure $schemaClosure,array $options):void {
  // Backfill the existing catalog in bounded batches. Originals and AI records stay untouched.
  do {
   $q=$this->db->getQueryBuilder();$result=$q->select('user_id','file_id','payload')->from('nextgallery_media')->where($q->expr()->orX($q->expr()->isNull('name_text'),$q->expr()->isNull('path_text')))->orderBy('user_id')->addOrderBy('file_id')->setMaxResults(500)->executeQuery();
   try {$rows=$result->fetchAll();} finally {$result->closeCursor();}
   foreach ($rows as $row) {
    $item=json_decode($row['payload'],true)??[];$q=$this->db->getQueryBuilder();
    $q->update('nextgallery_media')->set('name_text',$q->createNamedParameter(mb_strtolower($item['name']??'')))->set('path_text',$q->createNamedParameter(mb_strtolower($item['path']??'')))
     ->where($q->expr()->eq('user_id',$q->createNamedParameter($row['user_id'])))->andWhere($q->expr()->eq('file_id',$q->createNamedParameter($row['file_id'])))->executeStatement();
   }
  } while (count($rows)===500);
 }
}
