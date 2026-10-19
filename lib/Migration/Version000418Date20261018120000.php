<?php
declare(strict_types=1);
namespace OCA\Nextgallery\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\{SimpleMigrationStep,IOutput};

/** Content-addressed stage results shared between accounts reading identical bytes. */
class Version000418Date20261018120000 extends SimpleMigrationStep {
 public function changeSchema(IOutput $output,Closure $schemaClosure,array $options):?ISchemaWrapper {
  $schema=$schemaClosure();$changed=false;
  // Same-file vector lookups across accounts (group mounts, shares).
  if($schema->hasTable('nextgallery_ai_vectors')){$v=$schema->getTable('nextgallery_ai_vectors');if(!$v->hasIndex('ng_vector_file')){$v->addIndex(['file_id','space'],'ng_vector_file');$changed=true;}}
  if($schema->hasTable('nextgallery_ai_shared'))return $changed?$schema:null;
  $t=$schema->createTable('nextgallery_ai_shared');
  $t->addColumn('id','bigint',['autoincrement'=>true,'notnull'=>true,'unsigned'=>true]);
  $t->addColumn('sha256','string',['length'=>64,'notnull'=>true]);
  $t->addColumn('kind','string',['length'=>16,'notnull'=>true]);
  $t->addColumn('revision','string',['length'=>64,'notnull'=>true]);
  $t->addColumn('user_id','string',['length'=>64,'notnull'=>true]);
  $t->addColumn('result','text',['length'=>16777215,'notnull'=>true]);
  $t->addColumn('model','text',['notnull'=>false]);
  $t->addColumn('created_at','bigint',['notnull'=>true]);
  $t->setPrimaryKey(['id']);
  $t->addUniqueIndex(['sha256','kind','revision'],'ng_shared_key');
  $t->addIndex(['user_id','kind'],'ng_shared_user');
  return $schema;
 }
}
