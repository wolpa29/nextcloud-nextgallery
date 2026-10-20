<?php
declare(strict_types=1);

namespace OCA\Nextgallery\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\SimpleMigrationStep;
use OCP\Migration\IOutput;

/**
 * Migration for shared per-file face inference artifacts:
 * - Adds inference_revision to nextgallery_faces_files
 * - Creates nextgallery_faces_artifacts and nextgallery_faces_artifact_refs
 * Existing rows remain unsigned; no assumptions about their historical settings.
 */
class Version000421Date20261020060000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        $changed = false;

        if ($schema->hasTable('nextgallery_faces_files')) {
            $t = $schema->getTable('nextgallery_faces_files');
            if (!$t->hasColumn('inference_revision')) {
                $t->addColumn('inference_revision', 'string', ['length' => 64, 'notnull' => true, 'default' => 'legacy']);
                $changed = true;
            }
        }

        if (!$schema->hasTable('nextgallery_faces_artifacts')) {
            $t = $schema->createTable('nextgallery_faces_artifacts');
            $t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $t->addColumn('file_id', 'bigint', ['notnull' => true, 'unsigned' => true]);
            $t->addColumn('etag', 'string', ['length' => 255, 'notnull' => true]);
            $t->addColumn('inference_revision', 'string', ['length' => 64, 'notnull' => true]);
            $t->addColumn('face_count', 'integer', ['notnull' => true, 'default' => 0]);
            $t->addColumn('detections_json', 'text', ['length' => 16777215, 'notnull' => true]);
            $t->addColumn('created_at', 'bigint', ['notnull' => true]);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['file_id', 'etag', 'inference_revision'], 'ng_faces_art_unique');
            $t->addIndex(['file_id', 'etag'], 'ng_faces_art_file');
            $t->addIndex(['inference_revision'], 'ng_faces_art_rev');
            $changed = true;
        }

        if (!$schema->hasTable('nextgallery_faces_artifact_refs')) {
            $t = $schema->createTable('nextgallery_faces_artifact_refs');
            $t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $t->addColumn('artifact_id', 'bigint', ['notnull' => true, 'unsigned' => true]);
            $t->addColumn('user_id', 'string', ['length' => 64, 'notnull' => true]);
            $t->addColumn('file_id', 'bigint', ['notnull' => true, 'unsigned' => true]);
            $t->addColumn('etag', 'string', ['length' => 255, 'notnull' => true]);
            $t->addColumn('created_at', 'bigint', ['notnull' => true]);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['artifact_id', 'user_id'], 'ng_faces_ref_unique');
            $t->addIndex(['user_id'], 'ng_faces_ref_user');
            $t->addIndex(['user_id', 'file_id'], 'ng_faces_ref_user_file');
            $t->addIndex(['artifact_id'], 'ng_faces_ref_art');
            $changed = true;
        }

        return $changed ? $schema : null;
    }

}
