<?php
declare(strict_types=1);
namespace OCA\Chronicle\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\SimpleMigrationStep;
use OCP\Migration\IOutput;
class Version000100Date20261003200000 extends SimpleMigrationStep {
 public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
  $s=$schemaClosure();
  if ($s->hasTable('chronicle_media')) return null;
  $t=$s->createTable('chronicle_media');
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
  $t->setPrimaryKey(['user_id','file_id']);
  $t->addIndex(['user_id','sort_key','file_id'],'chronicle_order');
  $t->addIndex(['user_id','period','kind','sort_key'],'chronicle_period');
  $t->addIndex(['user_id','generation'],'chronicle_generation');
  $t=$s->createTable('chronicle_users');
  $t->addColumn('user_id','string',['length'=>64,'notnull'=>true]);
  $t->setPrimaryKey(['user_id']);
  return $s;
 }
}
