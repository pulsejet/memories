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
            $table->addColumn('date_taken', Types::INTEGER, [
                'notnull' => false,
            ]);
            $table->addColumn('file_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 20,
			]);
            $table->addColumn('day_id', Types::INTEGER, [
				'notnull' => true,
			]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id'], 'betterphotos_user_id_index');
            $table->addIndex(['day_id'], 'betterphotos_day_id_index');
            $table->addUniqueIndex(['user_id', 'file_id'], 'betterphotos_day_uf_ui');
        }

        if (!$schema->hasTable('betterphotos_day')) {
            $table = $schema->createTable('betterphotos_day');
            $table->addColumn('id', 'integer', [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('user_id', 'string', [
                'notnull' => true,
                'length' => 200,
            ]);
            $table->addColumn('count', Types::INTEGER, [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('day_id', Types::INTEGER, [
				'notnull' => true,
			]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id'], 'betterphotos_day_user_id_index');
            $table->addUniqueIndex(['user_id', 'day_id'], 'betterphotos_day_ud_ui');
        }

        return $schema;
    }
}