<?php

  namespace OCA\BetterPhotos\Migration;

  use Closure;
  use OCP\DB\Types;
  use OCP\DB\ISchemaWrapper;
  use OCP\Migration\SimpleMigrationStep;
  use OCP\Migration\IOutput;

  class Version000000Date20220812163631 extends SimpleMigrationStep {

    /**
    * @param IOutput $output
    * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
    * @param array $options
    * @return null|ISchemaWrapper
    */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options) {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('betterphotos')) {
            $table = $schema->createTable('betterphotos');
            $table->addColumn('id', 'integer', [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('user_id', 'string', [
                'notnull' => true,
                'length' => 200,
            ]);
            $table->addColumn('date_taken', Types::DATETIME, [
                'notnull' => false,
            ]);
            $table->addColumn('file_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 20,
			]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id'], 'betterphotos_user_id_index');
        }
        return $schema;
    }
}