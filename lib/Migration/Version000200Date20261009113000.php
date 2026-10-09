<?php
declare(strict_types=1);
namespace OCA\Chronicle\Migration;
use Closure;
use OCP\IDBConnection;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\{SimpleMigrationStep,IOutput};
class Version000200Date20261009113000 extends SimpleMigrationStep {
 public function __construct(private IDBConnection $db) {}
 public function changeSchema(IOutput $output,Closure $schemaClosure,array $options):?ISchemaWrapper {
  $schema=$schemaClosure();$table=$schema->getTable('chronicle_media');
  foreach (['name_text','path_text'] as $column) if (!$table->hasColumn($column)) $table->addColumn($column,'text',['notnull'=>false]);
  return $schema;
 }
 public function postSchemaChange(IOutput $output,Closure $schemaClosure,array $options):void {
  // Backfill the existing catalog in bounded batches. Originals and AI records stay untouched.
  $offset=0;
  do {
   $q=$this->db->getQueryBuilder();$result=$q->select('user_id','file_id','payload')->from('chronicle_media')->orderBy('user_id')->addOrderBy('file_id')->setFirstResult($offset)->setMaxResults(500)->executeQuery();
   try {$rows=$result->fetchAll();} finally {$result->closeCursor();}
   foreach ($rows as $row) {
    $item=json_decode($row['payload'],true)??[];$q=$this->db->getQueryBuilder();
    $q->update('chronicle_media')->set('name_text',$q->createNamedParameter(mb_strtolower($item['name']??'')))->set('path_text',$q->createNamedParameter(mb_strtolower($item['path']??'')))
     ->where($q->expr()->eq('user_id',$q->createNamedParameter($row['user_id'])))->andWhere($q->expr()->eq('file_id',$q->createNamedParameter($row['file_id'])))->executeStatement();
   }
   $offset+=count($rows);
  } while (count($rows)===500);
 }
}
