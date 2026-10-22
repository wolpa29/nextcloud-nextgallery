<?php
/** An actual app upgrade must preserve every People row while shortening table names. */
declare(strict_types=1);
require '/var/www/html/lib/base.php';
set_exception_handler(function (Throwable $error): void { fwrite(STDERR, $error->getMessage()."\n"); exit(1); });
if (!is_file('/test-output/ISOLATED_SYNTHETIC_TEST')) throw new RuntimeException('Synthetic guard missing');

use OCP\Server;
use OCP\IDBConnection;
use OCA\Nextgallery\Migration\Version000439Date20261022190000 as Upgrade;

$db = Server::get(IDBConnection::class);
$schema = new \OC\DB\SchemaWrapper(Server::get(\OC\DB\Connection::class));
$snapshotFile = '/test-output/people-upgrade-before.json';
$phase = $argv[1] ?? '';
$early = $phase === 'prepare-early';
function rowsFingerprint(IDBConnection $db, string $table): array {
 $result = $db->getQueryBuilder()->select('*')->from($table)->executeQuery();
 try { $rows = $result->fetchAll(); } finally { $result->closeCursor(); }
 $encoded = array_map(static function (array $row) use ($table): string { if ($table === 'nextgallery_face_detections' && !array_key_exists('sharpness', $row)) $row['sharpness'] = null; ksort($row); return json_encode($row, JSON_THROW_ON_ERROR); }, $rows);
 sort($encoded);
 return ['count' => count($encoded), 'hash' => hash('sha256', implode("\n", $encoded))];
}

if ($phase === 'prepare' || $early) {
 $tables = array_values(Upgrade::TABLES);
 $tables = array_merge($tables, ['nextgallery_faces_account', 'nextgallery_faces_groups', 'nextgallery_faces_files', 'nextgallery_faces_artifacts']);
 foreach ($tables as $table) {
  if ($early) continue; // Keep the data already verified in the preceding upgrade.
  $insert = $db->getQueryBuilder()->insert($table);
  foreach ($schema->getTable($table)->getColumns() as $column) {
   $name = $column->getName();
   $type = $column->getType()->getName();
   $value = match ($type) {
    'smallint' => 0,
    'integer', 'bigint' => str_ends_with($name, '_at') ? 1700000000 : 100001,
    'float', 'decimal' => .75,
    'boolean' => false,
    default => str_repeat('a', min(32, $column->getLength() ?: 32)),
   };
   if ($name === 'user_id') $value = 'table-upgrade-synthetic';
   if ($name === 'label') $value = 'Synthetic preserved name';
   if ($name === 'vector_data') $value = json_encode(array_merge([1.0], array_fill(0, 127, 0.0)));
   if ($name === 'detections_json') $value = '[]';
   $insert->setValue($name, $insert->createNamedParameter($value));
  }
  $insert->executeStatement();
 }
 if ($early) {
  $q = $db->getQueryBuilder();
  $db->executeStatement('ALTER TABLE '.$q->getTableName('nextgallery_face_detections').' DROP COLUMN sharpness');
 }
 $before = [];
 foreach ($tables as $table) $before[$table] = rowsFingerprint($db, $table);
 file_put_contents($snapshotFile, json_encode($before, JSON_THROW_ON_ERROR));
 // Simulate the physical schema of a previously installed 0.4.38 without
 // resetting rows or keeping duplicate copies of account-private information.
 foreach (Upgrade::TABLES as $old => $new) {
  $q = $db->getQueryBuilder();
  foreach ((array)$db->getDatabasePlatform()->getRenameTableSQL($q->getTableName($new), $q->getTableName($old)) as $statement) $db->executeStatement($statement);
 }
 foreach ($early ? ['000422Date20261020090000', '000423Date20261020150000', '000439Date20261022190000'] : ['000439Date20261022190000'] as $version) {
 $q = $db->getQueryBuilder();
 $q->delete('migrations')->where($q->expr()->eq('app', $q->createNamedParameter('nextgallery')))
  ->andWhere($q->expr()->eq('version', $q->createNamedParameter($version)))->executeStatement();
 }
 Server::get(\OCP\IConfig::class)->setAppValue('nextgallery', 'installed_version', $early ? '0.4.21' : '0.4.38');
 echo "Seeded nonempty legacy People tables for a real application upgrade\n";
} elseif ($phase === 'verify') {
 $before = json_decode(file_get_contents($snapshotFile), true, 64, JSON_THROW_ON_ERROR);
 foreach ($before as $table => $expected) {
  if (rowsFingerprint($db, $table) !== $expected) throw new RuntimeException('People data changed during table migration: '.$table);
 }
 foreach (Upgrade::TABLES as $old => $new) {
  if ($schema->hasTable($old) || !$schema->hasTable($new)) throw new RuntimeException('Table rename incomplete: '.$old);
 }
 // A repeated or partially retried migration must not copy or delete anything.
 $output = new class implements \OCP\Migration\IOutput {
  public function debug(string $message): void {} public function info($message) {} public function warning($message) {}
  public function startProgress($max = 0) {} public function advance($step = 1, $description = '') {} public function finishProgress() {}
 };
 Server::get(Upgrade::class)->preSchemaChange($output, fn () => new \OC\DB\SchemaWrapper(Server::get(\OC\DB\Connection::class)), []);
 echo count($before)." nonempty People tables retained exact row fingerprints; old names retired and retry idempotent\n";
} else {
 throw new RuntimeException('Expected prepare or verify');
}
