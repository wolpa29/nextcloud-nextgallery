<?php
declare(strict_types=1);
namespace OCA\Nextgallery\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\{SimpleMigrationStep,IOutput};
/** Worker-computed merge suggestions and an account's "not the same person" decisions. */
class Version000423Date20261020150000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output,Closure $schemaClosure,array $options):?ISchemaWrapper {
        $schema=$schemaClosure();$changed=false;
        if(!$schema->hasTable('nextgallery_faces_suggestions')){
            $t=$schema->createTable('nextgallery_faces_suggestions');
            $t->addColumn('id','bigint',['autoincrement'=>true,'notnull'=>true,'unsigned'=>true]);
            $t->addColumn('user_id','string',['length'=>64,'notnull'=>true]);
            $t->addColumn('group_a','string',['length'=>32,'notnull'=>true]);
            $t->addColumn('group_b','string',['length'=>32,'notnull'=>true]);
            $t->addColumn('similarity','float',['notnull'=>true]);
            $t->addColumn('created_at','bigint',['notnull'=>true]);
            $t->setPrimaryKey(['id']);
            $t->addIndex(['user_id','group_a'],'ng_faces_sugg_a');
            $t->addIndex(['user_id','group_b'],'ng_faces_sugg_b');
            $changed=true;
        }
        if(!$schema->hasTable('nextgallery_faces_dismissed')){
            $t=$schema->createTable('nextgallery_faces_dismissed');
            $t->addColumn('id','bigint',['autoincrement'=>true,'notnull'=>true,'unsigned'=>true]);
            $t->addColumn('user_id','string',['length'=>64,'notnull'=>true]);
            $t->addColumn('group_a','string',['length'=>32,'notnull'=>true]);
            $t->addColumn('group_b','string',['length'=>32,'notnull'=>true]);
            $t->addColumn('created_at','bigint',['notnull'=>true]);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['user_id','group_a','group_b'],'ng_faces_dism_pair');
            $changed=true;
        }
        return $changed?$schema:null;
    }
}
