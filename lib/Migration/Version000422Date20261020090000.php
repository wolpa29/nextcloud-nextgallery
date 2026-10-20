<?php
declare(strict_types=1);
namespace OCA\Nextgallery\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\{SimpleMigrationStep,IOutput};
/** Shared raw outputs and canonical projections gain an optional face-quality metric. */
class Version000422Date20261020090000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output,Closure $schemaClosure,array $options):?ISchemaWrapper {
        $schema=$schemaClosure();$t=$schema->getTable('nextgallery_faces_detections');
        if($t->hasColumn('sharpness'))return null;
        $t->addColumn('sharpness','float',['notnull'=>false,'default'=>null]);
        return $schema;
    }
}
