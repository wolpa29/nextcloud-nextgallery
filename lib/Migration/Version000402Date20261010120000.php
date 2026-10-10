<?php
declare(strict_types=1);
namespace OCA\Nextgallery\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\{SimpleMigrationStep,IOutput};
class Version000402Date20261010120000 extends SimpleMigrationStep {
 public function changeSchema(IOutput $output,Closure $schemaClosure,array $options):?ISchemaWrapper {
  $schema=$schemaClosure();if($schema->hasTable('nextgallery_ai_vectors'))return null;
  $t=$schema->createTable('nextgallery_ai_vectors');
  $t->addColumn('id','bigint',['autoincrement'=>true,'notnull'=>true,'unsigned'=>true]);
  $t->addColumn('user_id','string',['length'=>64,'notnull'=>true]);
  $t->addColumn('file_id','bigint',['notnull'=>true,'unsigned'=>true]);
  $t->addColumn('etag','string',['length'=>255,'notnull'=>true]);
  $t->addColumn('space','string',['length'=>64,'notnull'=>true]);
  $t->addColumn('frame','integer',['notnull'=>true]);
  $t->addColumn('sample_seconds','float',['notnull'=>true]);
  $t->addColumn('dimensions','integer',['notnull'=>true]);
  $t->addColumn('vector_data','text',['length'=>65536,'notnull'=>true]);
  $t->addColumn('updated_at','bigint',['notnull'=>true]);
  $t->setPrimaryKey(['id']);$t->addUniqueIndex(['user_id','file_id','space','frame'],'ng_vector_file_space_frame');
  $t->addIndex(['user_id','space'],'ng_vector_space');return $schema;
 }
}
