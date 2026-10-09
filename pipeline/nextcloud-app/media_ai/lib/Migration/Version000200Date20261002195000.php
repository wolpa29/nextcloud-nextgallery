<?php
declare(strict_types=1);
namespace OCA\MediaAI\Migration;
use Closure;
use OCP\Migration\{SimpleMigrationStep,IOutput};
use OCA\MediaAI\Service\Metadata;
class Version000200Date20261002195000 extends SimpleMigrationStep {
    public function __construct(private Metadata $metadata) {}
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $this->metadata->normalizeIndex();
    }
}
