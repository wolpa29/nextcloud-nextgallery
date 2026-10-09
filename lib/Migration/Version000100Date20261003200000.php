<?php
declare(strict_types=1);
namespace OCA\Nextgallery\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\SimpleMigrationStep;
use OCP\Migration\IOutput;
class Version000100Date20261003200000 extends SimpleMigrationStep {
 public function __construct(private IDBConnection $db) {}
 public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
  $s=$schemaClosure();
  if ($s->hasTable('nextgallery_media')) return null;
  $t=$s->createTable('nextgallery_media');
  $t->addColumn('user_id','string',['length'=>64,'notnull'=>true]);
  $t->addColumn('file_id','bigint',['notnull'=>true]);
  $t->addColumn('sort_key','string',['length'=>14,'notnull'=>true]);
  $t->addColumn('period','string',['length'=>10,'notnull'=>true]);
  $t->addColumn('kind','string',['length'=>8,'notnull'=>true]);
  $t->addColumn('etag','string',['length'=>255,'notnull'=>true]);
  $t->addColumn('generation','string',['length'=>32,'notnull'=>true]);
  $t->addColumn('latitude','float',['notnull'=>false]);
  $t->addColumn('longitude','float',['notnull'=>false]);
  $t->addColumn('payload','text',['notnull'=>true]);
  $t->addColumn('name_text','text',['notnull'=>false]);
  $t->addColumn('path_text','text',['notnull'=>false]);
  $t->setPrimaryKey(['user_id','file_id']);
  $t->addIndex(['user_id','sort_key','file_id'],'nextgallery_order');
  $t->addIndex(['user_id','period','kind','sort_key'],'nextgallery_period');
  $t->addIndex(['user_id','generation'],'nextgallery_generation');
  $t=$s->createTable('nextgallery_users');
  $t->addColumn('user_id','string',['length'=>64,'notnull'=>true]);
  $t->setPrimaryKey(['user_id']);
  return $s;
 }
 public function postSchemaChange(IOutput $output,Closure $schemaClosure,array $options):void {
  $schema=$schemaClosure();
  foreach (['media','users'] as $kind) {
   $old='chronicle_'.$kind;$new='nextgallery_'.$kind;
   if (!$schema->hasTable($old)) continue;
   $columns=array_keys(array_intersect_key($schema->getTable($old)->getColumns(),$schema->getTable($new)->getColumns()));
   $q=$this->db->getQueryBuilder();$source=$q->getTableName($old);$target=$q->getTableName($new);
   $fields=implode(',',array_map(fn($column)=>$q->getColumnName($column),$columns));
   $identity=$kind==='media'?'user_id,file_id':'user_id';
   $where=implode(' AND ',array_map(fn($column)=>'n.'.$q->getColumnName($column).'=o.'.$q->getColumnName($column),explode(',',$identity)));
   $select=implode(',',array_map(fn($column)=>'o.'.$q->getColumnName($column),$columns));
   $this->db->executeStatement("INSERT INTO $target ($fields) SELECT $select FROM $source o WHERE NOT EXISTS (SELECT 1 FROM $target n WHERE $where)");
  }
 }
}
