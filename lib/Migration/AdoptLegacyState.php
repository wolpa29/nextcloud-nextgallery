<?php
declare(strict_types=1);
namespace OCA\Nextgallery\Migration;
use OCP\{Server,IDBConnection};
use OCP\Migration\{IRepairStep,IOutput};

/** A new app ID gets schema-only installation; adopt data once all tables exist. */
class AdoptLegacyState implements IRepairStep {
 public function getName():string {return 'Adopt existing gallery and AI state into NextGallery';}
 public function run(IOutput $output):void {
  $db=Server::get(\OC\DB\Connection::class);
  $schema=fn()=>new \OC\DB\SchemaWrapper($db);
  foreach ([
   Version000000Date20261009150000::class,
   Version000100Date20261003200000::class,
   Version000200Date20261009110000::class,
   Version000200Date20261009113000::class,
   Version000250Date20261009152000::class,
   Version000300Date20261009153000::class,
  ] as $step) Server::get($step)->postSchemaChange($output,$schema,[]);
 }
}
