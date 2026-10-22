<?php
declare(strict_types=1);
namespace OCA\Nextgallery\Migration;

use Closure;
use OCP\IDBConnection;
use OCP\Migration\{SimpleMigrationStep, IOutput};

/** Keep existing People data while adopting names accepted by Nextcloud 32. */
class Version000439Date20261022190000 extends SimpleMigrationStep {
 public const TABLES = [
  'nextgallery_faces_detections' => 'nextgallery_face_detections',
  'nextgallery_faces_constraints' => 'nextgallery_face_rules',
  'nextgallery_faces_artifact_refs' => 'nextgallery_face_refs',
  'nextgallery_faces_suggestions' => 'nextgallery_face_suggest',
 ];

 public function __construct(private IDBConnection $db) {}

 public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
  $schema = $schemaClosure();
  // Detect ambiguity before changing any table. A retry can safely resume a
  // partially completed rename because an already renamed source is absent.
  foreach (self::TABLES as $old => $new) {
   if ($schema->hasTable($old) && $schema->hasTable($new)) {
    throw new \RuntimeException('Both People table names exist; refusing to overwrite data: ' . $new);
   }
  }
  foreach (self::TABLES as $old => $new) {
   if (!$schema->hasTable($old)) continue;
   $query = $this->db->getQueryBuilder();
   $statements = $this->db->getDatabasePlatform()->getRenameTableSQL($query->getTableName($old), $query->getTableName($new));
   foreach ((array)$statements as $statement) $this->db->executeStatement($statement);
  }
 }
}
