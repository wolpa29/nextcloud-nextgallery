<?php
declare(strict_types=1);
namespace OCA\Nextgallery\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\{SimpleMigrationStep, IOutput};

/**
 * Adds person naming (label), custom cover detection selection (cover_id),
 * unknown-person folder classification (stranger) on nextgallery_faces_groups,
 * and opt-in sorting sharing (share_sorting) on nextgallery_faces_account.
 */
class Version000425Date20261020200000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        $changed = false;

        if ($schema->hasTable('nextgallery_faces_groups')) {
            $t = $schema->getTable('nextgallery_faces_groups');
            if (!$t->hasColumn('label')) {
                $t->addColumn('label', 'string', ['length' => 120, 'notnull' => false, 'default' => null]);
                $changed = true;
            }
            if (!$t->hasColumn('cover_id')) {
                $t->addColumn('cover_id', 'string', ['length' => 32, 'notnull' => false, 'default' => null]);
                $changed = true;
            }
            if (!$t->hasColumn('stranger')) {
                $t->addColumn('stranger', 'smallint', ['notnull' => true, 'default' => 0]);
                $changed = true;
            }
        }

        if ($schema->hasTable('nextgallery_faces_account')) {
            $t = $schema->getTable('nextgallery_faces_account');
            if (!$t->hasColumn('share_sorting')) {
                $t->addColumn('share_sorting', 'smallint', ['notnull' => true, 'default' => 0]);
                $changed = true;
            }
        }

        return $changed ? $schema : null;
    }
}
