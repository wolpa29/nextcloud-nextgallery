<?php
declare(strict_types=1);
namespace OCA\Nextgallery\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\SimpleMigrationStep;
use OCP\Migration\IOutput;

/**
 * Authoritative NextGallery storage for facial recognition:
 * account generation (epoch, generation, rules_revision),
 * processed files (including zero-detection images),
 * stable detections with normalized oriented bounding boxes and 128-d vectors,
 * stable face groups and manual constraints/reviews.
 */
class Version000420Date20261020010000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        $changed = false;

        if (!$schema->hasTable('nextgallery_faces_account')) {
            $t = $schema->createTable('nextgallery_faces_account');
            $t->addColumn('user_id', 'string', ['length' => 64, 'notnull' => true]);
            $t->addColumn('epoch', 'string', ['length' => 32, 'notnull' => true]);
            $t->addColumn('generation', 'integer', ['notnull' => true, 'default' => 0]);
            $t->addColumn('rules_revision', 'integer', ['notnull' => true, 'default' => 0]);
            $t->addColumn('updated_at', 'bigint', ['notnull' => true]);
            $t->setPrimaryKey(['user_id']);
            $changed = true;
        }

        if (!$schema->hasTable('nextgallery_faces_files')) {
            $t = $schema->createTable('nextgallery_faces_files');
            $t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $t->addColumn('user_id', 'string', ['length' => 64, 'notnull' => true]);
            $t->addColumn('file_id', 'bigint', ['notnull' => true, 'unsigned' => true]);
            $t->addColumn('etag', 'string', ['length' => 255, 'notnull' => true]);
            $t->addColumn('face_count', 'integer', ['notnull' => true, 'default' => 0]);
            $t->addColumn('updated_at', 'bigint', ['notnull' => true]);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['user_id', 'file_id'], 'ng_faces_files_user_file');
            $t->addIndex(['user_id'], 'ng_faces_files_user');
            $t->addIndex(['file_id', 'etag'], 'ng_faces_files_file_etag');
            $changed = true;
        }

        if (!$schema->hasTable('nextgallery_faces_groups')) {
            $t = $schema->createTable('nextgallery_faces_groups');
            $t->addColumn('user_id', 'string', ['length' => 64, 'notnull' => true]);
            $t->addColumn('id', 'string', ['length' => 32, 'notnull' => true]);
            $t->addColumn('hidden', 'smallint', ['notnull' => true, 'default' => 0]);
            $t->addColumn('created_at', 'bigint', ['notnull' => true]);
            $t->addColumn('updated_at', 'bigint', ['notnull' => true]);
            $t->setPrimaryKey(['user_id', 'id']);
            $t->addIndex(['user_id', 'hidden'], 'ng_faces_groups_user_hidden');
            $changed = true;
        }

        if (!$schema->hasTable('nextgallery_faces_detections')) {
            $t = $schema->createTable('nextgallery_faces_detections');
            $t->addColumn('user_id', 'string', ['length' => 64, 'notnull' => true]);
            $t->addColumn('id', 'string', ['length' => 32, 'notnull' => true]);
            $t->addColumn('file_id', 'bigint', ['notnull' => true, 'unsigned' => true]);
            $t->addColumn('etag', 'string', ['length' => 255, 'notnull' => true]);
            $t->addColumn('bbox_x', 'float', ['notnull' => true]);
            $t->addColumn('bbox_y', 'float', ['notnull' => true]);
            $t->addColumn('bbox_w', 'float', ['notnull' => true]);
            $t->addColumn('bbox_h', 'float', ['notnull' => true]);
            $t->addColumn('score', 'float', ['notnull' => true]);
            $t->addColumn('group_id', 'string', ['length' => 32, 'notnull' => true]);
            $t->addColumn('model_space', 'string', ['length' => 64, 'notnull' => true]);
            $t->addColumn('vector_data', 'text', ['length' => 65536, 'notnull' => true]);
            $t->addColumn('review', 'smallint', ['notnull' => true, 'default' => 0]);
            $t->addColumn('manual', 'smallint', ['notnull' => true, 'default' => 0]);
            $t->addColumn('created_at', 'bigint', ['notnull' => true]);
            $t->addColumn('updated_at', 'bigint', ['notnull' => true]);
            $t->setPrimaryKey(['user_id', 'id']);
            $t->addIndex(['user_id', 'group_id'], 'ng_faces_det_user_group');
            $t->addIndex(['user_id', 'file_id'], 'ng_faces_det_user_file');
            $t->addIndex(['file_id', 'etag'], 'ng_faces_det_file_etag');
            $changed = true;
        }

        if (!$schema->hasTable('nextgallery_faces_constraints')) {
            $t = $schema->createTable('nextgallery_faces_constraints');
            $t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $t->addColumn('user_id', 'string', ['length' => 64, 'notnull' => true]);
            $t->addColumn('kind', 'string', ['length' => 32, 'notnull' => true]);
            $t->addColumn('detection_id', 'string', ['length' => 32, 'notnull' => true]);
            $t->addColumn('group_id', 'string', ['length' => 32, 'notnull' => true]);
            $t->addColumn('created_at', 'bigint', ['notnull' => true]);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['user_id', 'detection_id', 'group_id'], 'ng_faces_const_unique');
            $t->addIndex(['user_id', 'detection_id'], 'ng_faces_const_user_det');
            $changed = true;
        }

        return $changed ? $schema : null;
    }
}
