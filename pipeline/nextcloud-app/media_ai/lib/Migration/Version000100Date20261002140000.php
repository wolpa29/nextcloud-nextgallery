<?php
declare(strict_types=1);
namespace OCA\MediaAI\Migration;
use Closure;
use OCP\IConfig;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\SimpleMigrationStep;
use OCP\Migration\IOutput;
class Version000100Date20261002140000 extends SimpleMigrationStep {
    public function __construct(private IConfig $config) {}
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        if ($this->config->getSystemValueString('dbtype') !== 'mysql') {
            throw new \RuntimeException('Media AI 0.1 requires MariaDB/MySQL for its full-text index');
        }
        $schema = $schemaClosure();
        if ($schema->hasTable('media_ai_meta')) { return null; }
        $table = $schema->createTable('media_ai_meta');
        $table->addColumn('id', 'bigint', ['autoincrement' => true, 'unsigned' => true, 'notnull' => true]);
        $table->addColumn('user_id', 'string', ['length' => 64, 'notnull' => true]);
        $table->addColumn('file_id', 'bigint', ['unsigned' => true, 'notnull' => true]);
        $table->addColumn('etag', 'string', ['length' => 255, 'notnull' => true]);
        $table->addColumn('sha256', 'string', ['length' => 64, 'notnull' => true]);
        $table->addColumn('revision', 'string', ['length' => 64, 'notnull' => true]);
        $table->addColumn('type', 'string', ['length' => 16, 'notnull' => true]);
        foreach (['description', 'ocr_text', 'tags_text', 'objects_text', 'metadata_json'] as $column) {
            $table->addColumn($column, 'text', ['length' => 16777215, 'notnull' => true]);
        }
        $table->addColumn('updated_at', 'bigint', ['notnull' => true]);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['user_id', 'file_id'], 'media_ai_user_file');
        $table->addIndex(['user_id'], 'media_ai_user');
        $table->addIndex(['description', 'ocr_text', 'tags_text', 'objects_text'], 'media_ai_text', ['fulltext']);
        return $schema;
    }
}
