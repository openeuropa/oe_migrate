<?php

declare(strict_types = 1);

namespace Drupal\oe_migration_views\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\oe_migration_views\Plugin\migrate\id_map\SqlData;
use Drupal\oe_migration_views\Traits\MigrateToolsCommandsTrait;
use Drupal\migrate\Plugin\migrate\destination\EntityContentBase;
use Drupal\migrate\Plugin\migrate\id_map\Sql;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Plugin\MigrationPluginManager;
use Drupal\migrate_plus\Entity\MigrationGroup;
use Drush\Commands\DrushCommands;
use Drush\Exceptions\UserAbortException;
use Drush\Utils\StringUtils;

/**
 * Migrate Views drush commands.
 */
class OeMigrationViewsCommands extends DrushCommands {

  use MigrateToolsCommandsTrait;

  /**
   * Migration plugin manager service.
   *
   * @var \Drupal\migrate\Plugin\MigrationPluginManager
   */
  protected $migrationPluginManager;

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * OeMigrationViewsCommands constructor.
   *
   * @param \Drupal\migrate\Plugin\MigrationPluginManager $migrationPluginManager
   *   Migration Plugin Manager service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Entity type manager service.
   */
  public function __construct(MigrationPluginManager $migrationPluginManager, EntityTypeManagerInterface $entityTypeManager) {
    parent::__construct();
    $this->migrationPluginManager = $migrationPluginManager;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * Generate report views for migrations.
   *
   * @param string $migration_names
   *   Restrict to a comma-separated list of migrations (Optional).
   * @param array $options
   *   Additional options for the command.
   *
   * @option group Comma-separated list of migration groups to list
   * @option tag Name of the migration tag to list
   * @option continue-on-failure When a migration fails requirements checks,
   *   continue processing remaining migrations.
   *
   * @default $options []
   *
   * @command oe_migration_views:generate
   *
   * @validate-module-enabled oe_migration_views
   *
   * @aliases oe_migration_views-generate
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function generate($migration_names = '', array $options = [
    'group' => self::REQ,
    'tag' => self::REQ,
    'continue-on-failure' => FALSE,
  ]) {
    $options['group'] = $options['group'] ?? '';
    $options['tag'] = $options['tag'] ?? '';
    $migrations = $this->migrationsList($migration_names, $options);
    if (empty($migrations)) {
      $this->logger->error(dt('No migration(s) found.'));
    }

    // Take it one group at a time, listing the migrations within each group.
    foreach ($migrations as $group_id => $migration_list) {
      /** @var \Drupal\migrate_plus\Entity\MigrationGroup $group */
      $group = $this->entityTypeManager->getStorage('migration_group')
        ->load($group_id);

      /** @var \Drupal\migrate\Plugin\Migration $migration */
      foreach ($migration_list as $migration) {
        $this->generateMigrationView($group, $migration);
      }
    }
  }

  /**
   * Generates a view for a given migration.
   */
  protected function generateMigrationView(MigrationGroup $group, MigrationInterface $migration) {
    $view_storage = $this->entityTypeManager->getStorage('view');
    $view_id = $group->id() . '_' . $migration->id();

    $id_map = $migration->getIdMap();
    if (!is_a($id_map, Sql::class)) {
      $this->logger()
        ->warning(dt('Skipped %view_id view creation: The id map is not of type %type', [
          '%view_id' => $view_id,
          '%type' => '\Drupal\migrate\Plugin\migrate\id_map\Sql',
        ]));
      return;
    }

    // Ensure the map table exists.
    $id_map = $migration->getIdMap();
    $id_map->getDatabase();

    // Exit if the view already exists.
    if ($view_storage->load($view_id)) {
      $this->logger()
        ->warning(dt('Skipped %view_id view creation: The view already exists.', ['%view_id' => $view_id]));
      return;
    }

    // Create the view.
    $view = $this->createMigrationView($group, $migration);

    // Validate the view.
    $view_executable = $view->getExecutable();
    $errors = $view_executable->validate();
    if (!empty($errors)) {
      $this->logger()->error(dt('Failed %view_id view creation:', ['%view_id' => $view_id]));

      foreach ($errors as $display_errors) {
        foreach ($display_errors as $error) {
          $this->logger()->error($error);
        }
      }
      return;
    }

    // Save the view.
    $view->save();
    $this->logger()
      ->success(dt('Created %view_id migrate report view.', ['%view_id' => $view_id]));
  }

  /**
   * Creates a view for a given migration.
   */
  protected function createMigrationView(MigrationGroup $group, MigrationInterface $migration) {
    $view_storage = $this->entityTypeManager->getStorage('view');
    $group_name = !empty($group) ? $group->label() : 'Default';
    /** @var \Drupal\migrate\Plugin\migrate\id_map\Sql $id_map */
    $id_map = $migration->getIdMap();

    /** @var \Drupal\views\Entity\View $view */
    $view_id = $group->id() . '_' . $migration->id();
    $view = $view_storage->create([
      'id' => $view_id,
      'label' => $group_name . ' - ' . $migration->label(),
      'base_table' => $id_map->mapTableName(),
    ]);
    $view_executable = $view->getExecutable();
    $page_display = $view_executable->newDisplay('page', $migration->label(), 'page_1');
    $page_display->setOption('path', 'admin/structure/migrate/manage/' . $group->id() . '/migrations/' . $migration->id() . '/reports');
    $page_display->setOption('style', [
      'type' => 'table',
      'options' => [
        'row_class' => '',
        'default_row_class' => TRUE,
      ],
    ]);
    $page_display->setOption('pager', [
      'type' => 'full',
      'options' => [
        'items_per_page' => 200,
        'offset' => 0,
      ],
    ]);
    $page_display->setOption('access', [
      'type' => 'perm',
      'options' => [
        'perm' => 'view migrate reports',
      ],
    ]);

    // Add source fields.
    $map_table = $id_map->mapTableName();
    $view_executable->addHandler('default', 'field', $map_table, 'source_row_status');

    $source_id_field_names = array_keys($migration->getSourcePlugin()->getIds());
    $count = 0;
    foreach ($source_id_field_names as $id_definition) {
      $count++;
      $view_executable->addHandler('default', 'field', $map_table, 'sourceid' . $count, [
        'label' => 'Source: ' . $id_definition,
      ]);
    }

    if (is_a($id_map, SqlData::class)) {
      $source_plugin = $migration->getSourcePlugin();
      $source_fields = $source_plugin->fields();
      foreach ($source_fields as $key => $label) {
        $view_executable->addHandler('default', 'field', $map_table, 'source_data', [
          'format' => 'key',
          'key' => $key,
          'label' => 'Source: ' . $label,
        ]);
      }
    }

    // Add destination relationship/fields.
    $destination_plugin = $migration->getDestinationPlugin();
    if (is_a($destination_plugin, EntityContentBase::class)) {
      list (, $entity_type_id) = explode(':', $destination_plugin->getPluginId());
      $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
      $base_table = $entity_type->getDataTable() ?: $entity_type->getBaseTable();
      if (isset($base_table)) {
        // Relationship.
        $view_executable->addHandler('default', 'relationship', $map_table, 'migrate_map_' . $base_table);

        // Fields.
        $destination_id_field_names = $migration->getDestinationPlugin()->getIds();
        $count = 0;
        foreach ($destination_id_field_names as $id_definition => $schema) {
          $count++;
          $view_executable->addHandler('default', 'field', $map_table, 'destid' . $count, [
            'label' => 'Destination: ' . $id_definition,
          ]);
        }
      }
    }

    // Add migrate messages field.
    $view_executable->addHandler('default', 'field', $map_table, 'migrate_messages');

    // Add no result behavior.
    $view_executable->addHandler('default', 'empty', 'views', 'area_text_custom', [
      'empty' => TRUE,
      'content' => '<h2>No data at the moment, come back later</h2>',
      'plugin_id' => 'text_custom',
    ]);

    return $view;
  }

  /**
   * Drop source_data and destination_data columns from migrate_map table(s).
   *
   * Useful if you want to keep the migrate_map tables after a migration without
   * the data columns (to save space).
   *
   * @param array $options
   *   An array of options.
   *
   * @option tables
   *   A column-separated list of migrate_map tables.
   * @option all
   *   Perform operation on all migrate_map table(s).
   *
   * @usage oe_migration_views:cleanup-map-tables
   *   Drop source_data, destination_data columns from all migrate_map table(s).
   * @usage oe_migration_views:cleanup-map-tables --tables="migrate_map_articles"
   *   Drop source_data, destination_data columns from migrate_map_articles
   *   table.
   * @usage oe_migration_views:cleanup-map-tables --tables="migrate_map_articles, migrate_map_pages"
   *   Drop source_data, destination_data columns from migrate_map_articles
   *   and migrate_map_pages tables.
   *
   * @command oe_migration_views:cleanup-map-tables
   *
   * @aliases oe_migration_views-cleanup-map-tables, oe_migration_views:cleanup, oe_migration_views-cleanup
   *
   * @throws \Drush\Exceptions\UserAbortException
   */
  public function cleanup(array $options = ['tables' => '']) {
    $database_schema = \Drupal::database()->schema();

    if (!empty($options['tables'])) {
      $tables = [];
      $input = StringUtils::csvToArray($options['tables']);
      foreach ($input as $table) {
        if ($database_schema->tableExists($table)) {
          $tables[$table] = $table;
        }
      }
    }
    else {
      $tables = $database_schema->findTables('migrate_map_%');
    }

    if (empty($tables)) {
      $this->logger()->error(dt('No table(s) found.'));
    }

    if (!$this->io()->confirm(dt('Cleanup will be performed on the following table(s): @tables', ['@tables' => implode(', ', $tables)]))) {
      throw new UserAbortException();
    }

    foreach ($tables as $table) {
      foreach (['source_data', 'destination_data'] as $column) {
        if ($database_schema->fieldExists($table, $column)) {
          $database_schema->dropField($table, $column);
          $this->logger()->success(dt('Removed @column column from @table.', [
            '@column' => $column,
            '@table' => $table,
          ]));
        }
      }
    }
  }

}
