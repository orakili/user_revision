<?php

/**
 * @file
 * Contains \Drupal\user_revision\UserStorageSchema.
 */

namespace Drupal\user_revision;

use Drupal\user\UserStorageSchema as BaseUserStorageSchema;
use Drupal\Core\Entity\EntityManagerInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
use Drupal\Core\Database\Connection;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Database\SchemaObjectExistsException;

/**
 * Defines the user schema handler.
 */
class UserStorageSchema extends BaseUserStorageSchema implements UserStorageSchemaInterface {

  /**
   * The key/value store.
   * 
   * @var \Drupal\Core\KeyValueStore\KeyValueFactoryInterface 
   */
  protected $keyvalue;

  /**
   * Constructs a UserStorageSchema.
   *
   * @param \Drupal\Core\Entity\EntityManagerInterface $entity_manager
   *   The entity manager.
   * @param \Drupal\Core\Entity\ContentEntityTypeInterface $entity_type
   *   The entity type.
   * @param \Drupal\Core\Entity\Sql\SqlContentEntityStorage $storage
   *   The storage of the entity type. This must be an SQL-based storage.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection to be used.
   * @param \Drupal\Core\KeyValueStore\KeyValueFactoryInterface $keyvalue
   *   The key/value store factory.
   */
  public function __construct(EntityManagerInterface $entity_manager, ContentEntityTypeInterface $entity_type, SqlContentEntityStorage $storage, Connection $database, KeyValueFactoryInterface $keyvalue) {
    parent::__construct($entity_manager, $entity_type, $storage, $database);
    $this->keyvalue = $keyvalue;
  }

  protected function getSharedTableFieldSchema(\Drupal\Core\Field\FieldStorageDefinitionInterface $storage_definition, $table_name, array $column_mapping) {
    $schema = parent::getSharedTableFieldSchema($storage_definition, $table_name, $column_mapping);
    if ($table_name == 'users_field_data') {
      //hook_field_schema
      $schema['fields']['vid']['initial'] = '0';
    }
    return $schema;
  }

  /**
   * Install user revision storage schema.
   */
  public function installRevision() {
    $this->installRevisionEntityTables();
    $this->installRevisionFieldStorageDefinitions();
    $this->installRevisionEntitySchemaData();
  }

  /**
   * Create entity tables.
   */
  protected function installRevisionEntityTables() {
    $schema_handler = $this->database->schema();
    $entity_type_id = $this->entityType->id();
    $entity_type = $this->entityType;
    $original_entity_type = $this->entityManager->getLastInstalledDefinition($entity_type_id);

    $entity_schema = $this->getEntitySchema($entity_type, TRUE);
    $original_entity_schema = $this->getEntitySchema($original_entity_type, TRUE);

    foreach (array_diff_key($entity_schema, $original_entity_schema) as $table_name => $table_schema) {
      if (!$schema_handler->tableExists($table_name)) {
        $schema_handler->createTable($table_name, $table_schema);
      }
    }
  }

  /**
   * Update entity indexes and unique keys
   */
  protected function installRevisionEntitySchemaData() {
    $schema_handler = $this->database->schema();
    $entity_type = $this->entityType;
    $entity_schema = $this->getEntitySchema($entity_type, TRUE);

    // Drop original entity indexes and unique keys.
    foreach ($this->loadEntitySchemaData($entity_type) as $table_name => $schema) {
      if (!empty($schema['indexes'])) {
        foreach ($schema['indexes'] as $name => $specifier) {
          $schema_handler->dropIndex($table_name, $name);
        }
      }
      if (!empty($schema['unique keys'])) {
        foreach ($schema['unique keys'] as $name => $specifier) {
          $schema_handler->dropUniqueKey($table_name, $name);
        }
      }
    }

    // Create new entity indexes and unique keys.
    foreach ($this->getEntitySchemaData($entity_type, $entity_schema) as $table_name => $schema) {
      if (!empty($schema['indexes'])) {
        foreach ($schema['indexes'] as $name => $specifier) {
          if (!$schema_handler->indexExists($table_name, $name)) {
            $schema_handler->addIndex($table_name, $name, $specifier);
          }
        }
      }
      if (!empty($schema['unique keys'])) {
        foreach ($schema['unique keys'] as $name => $specifier) {
          /** @see https://www.drupal.org/node/2445839 */
          try {
            $schema_handler->addUniqueKey($table_name, $name, $specifier);
          }
          catch (SchemaObjectExistsException $ex) {
            
          }
        }
      }
    }
  }

  /**
   * Update field storage definitions
   */
  protected function installRevisionFieldStorageDefinitions() {
    $entity_type_id = $this->entityType->id();
    $table_mapping = $this->storage->getTableMapping();

    $field_storage_definitions = $this->entityManager->getFieldStorageDefinitions($entity_type_id);
    $original_field_storage_definitions = $this->entityManager->getLastInstalledFieldStorageDefinitions($entity_type_id);
    $all_filed_storage_definitions = array_merge(array_keys($field_storage_definitions), array_keys($original_field_storage_definitions));

    foreach ($all_filed_storage_definitions as $field) {
      if (isset($original_field_storage_definitions[$field]) && !isset($field_storage_definitions[$field])) {
        $this->performFieldSchemaOperation('delete', $original_field_storage_definitions[$field]);
      }
      else if (!isset($original_field_storage_definitions[$field]) && isset($field_storage_definitions[$field])) {
        $this->performFieldSchemaOperation('create', $field_storage_definitions[$field]);
      }
      else {
        // Create dedicated field revision table
        if ($table_mapping->requiresDedicatedTableStorage($field_storage_definitions[$field])) {
          $this->createDedicatedTableSchema($field_storage_definitions[$field]);
        }
        if ($field_storage_definitions[$field]->getSchema() != $original_field_storage_definitions[$field]->getSchema()) {
          $this->performFieldSchemaOperation('update', $field_storage_definitions[$field], $original_field_storage_definitions[$field]);
        }
      }
    }
  }

  /**
   * Post install storage data
   */
  public function postinstallRevision() {
    $schema_handler = $this->database->schema();
    $entity_type_id = $this->entityType->id();
    $entity_type = $this->entityType;
    $original_entity_type = $this->entityManager->getLastInstalledDefinition($entity_type_id);

    $entity_schema = $this->getEntitySchema($entity_type, TRUE);
    $original_entity_schema = $this->getEntitySchema($original_entity_type, TRUE);

    foreach ($original_entity_schema as $table_name => $table_schema) {
      // Drop unused original tables
      if (!isset($entity_schema[$table_name])) {
        $schema_handler->dropTable($table_name);
      }
      else {
        // Drop original fields
        foreach ($table_schema['fields'] as $filed_name => $specifier) {
          if (!isset($entity_schema[$table_name]['fields'][$filed_name])) {
            $schema_handler->dropField($table_name, $filed_name);
          }
        }
      }
    }
  }

  /**
   * Uninstall user revision storage schema.
   */
  public function uninstall() {
    $schema_handler = $this->database->schema();

    // Create langcode field from base table
    $schema = $this->getEntitySchema($this->entityType, TRUE);
    $langcode_schema = $schema[$this->entityType->getRevisionTable()]['fields']['langcode'];
    $langcode_schema['not null'] = false;
    $schema_handler->addField($this->entityType->getBaseTable(), 'langcode', $langcode_schema);
  }

  /**
   * Uninstall user revision storage schema (post uninstall).
   */
  public function postuninstall() {
    $schema_handler = $this->database->schema();

    $schema = $this->getEntitySchema($this->entityType, TRUE);
    $langcode_schema = $schema[$this->entityType->getRevisionTable()]['fields']['langcode'];
    $schema_handler->changeField($this->entityType->getBaseTable(), 'langcode', 'langcode', $langcode_schema);

    foreach ($this->keyValue()->get('installed_indexes', array()) as $table_name => $indexes) {
      foreach ($indexes as $indexe_name) {
        $schema_handler->dropIndex($table_name, $indexe_name);
      }
    }

    foreach ($this->keyValue()->get('installed_unique_keys', array()) as $table_name => $keys) {
      foreach ($keys as $key_name) {
        $schema_handler->dropUniqueKey($table_name, $key_name);
      }
    }

    foreach ($this->keyValue()->get('installed_fields', array()) as $table_name => $fields) {
      foreach ($fields as $field_name) {
        $schema_handler->dropField($table_name, $field_name);
      }
    }

    foreach ($this->keyValue()->get('installed_tables', array()) as $table_name) {
      $schema_handler->dropTable($table_name);
    }

    foreach ($this->getDedicatedRevisionTables() as $table_name) {
      $schema_handler->dropTable($table_name);
    }

    $this->keyValue()->deleteAll();
  }

  /**
   * @return array
   */
  public function getDedicatedRevisionTables() {
    return array_keys($this->getDedicatedRevisionTablesSchema());
  }

  /**
   * @param string $table_name
   * @param array $table_schema
   */
  protected function installExistsTable($table_name, array $table_schema) {
    $schema_handler = $this->database->schema();
    $installed_fields = $this->keyValue()->get('installed_fields', array());
    $installed_unique_keys = $this->keyValue()->get('installed_unique_keys', array());
    $installed_indexes = $this->keyValue()->get('installed_indexes', array());

    foreach ($table_schema['fields'] as $field_name => $field_schema) {
      if (!$schema_handler->fieldExists($table_name, $field_name)) {
        $field_schema['not null'] = false;
        $schema_handler->addField($table_name, $field_name, $field_schema);
        $installed_fields[$table_name][] = $field_name;
      }
    }

    foreach ($table_schema['unique keys'] as $key_name => $fields) {
      /** @see https://www.drupal.org/node/2445839 */
      try {
        $schema_handler->addUniqueKey($table_name, $key_name, $fields);
        $installed_unique_keys[$table_name][] = $key_name;
      }
      catch (SchemaObjectExistsException $ex) {
        
      }
    }

    /**
     * @todo: foreign keys
     */
    //foreach ($table_schema['foreign keys'] as $key_name => $fields) {}

    foreach ($table_schema['indexes'] as $index_name => $fields) {
      if (!$schema_handler->indexExists($table_name, $index_name)) {
        $schema_handler->addIndex($table_name, $index_name, $fields);
        $installed_indexes[$table_name][] = $index_name;
      }
    }

    $this->keyValue()->set('installed_fields', $installed_fields);
    $this->keyValue()->set('installed_unique_keys', $installed_unique_keys);
    $this->keyValue()->set('installed_indexes', $installed_indexes);
  }

  /**
   * @return array
   */
  protected function getDedicatedRevisionTablesSchema() {
    $schema = array();
    $base_filed_definitions = $this->entityManager->getBaseFieldDefinitions($this->storage->getEntityTypeId());
    $filed_storage_definitions = $this->entityManager->getFieldStorageDefinitions($this->storage->getEntityTypeId());
    foreach ($filed_storage_definitions as $field_name => $storage_definition) {
      if ($field_name != 'roles' && array_key_exists($field_name, $base_filed_definitions)) {
        continue;
      }
      $revision_table_name = $this->storage->getTableMapping()->getDedicatedRevisionTableName($storage_definition);
      $revision_table_schema = $this->getDedicatedTableSchema($storage_definition)[$revision_table_name];
      $schema[$revision_table_name] = $revision_table_schema;
    }
    return $schema;
  }

  /**
   * @return \Drupal\Core\KeyValueStore\KeyValueStoreInterface
   */
  protected function keyValue() {
    return $this->keyvalue->get('module.user_revision.storage_schema');
  }

}
