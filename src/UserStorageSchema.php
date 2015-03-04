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

  /**
   * Install user revision storage schema.
   */
  public function install() {
    $schema_handler = $this->database->schema();

    // Create shared tables and fields
    $schema = $this->getEntitySchema($this->entityType, TRUE);
    $installed_tables = array();
    foreach ($schema as $table_name => $table_schema) {
      if ($schema_handler->tableExists($table_name)) {
        $this->installExistsTable($table_name, $table_schema);
      }
      else {
        $schema_handler->createTable($table_name, $table_schema);
        $installed_tables[] = $table_name;
      }
    }
    $this->keyValue()->set('installed_tables', $installed_tables);

    // Create dedicated tables
    foreach ($this->getDedicatedRevisionTablesSchema() as $table_name => $table_schema) {
      $schema_handler->createTable($table_name, $table_schema);
    }
  }

  /**
   * Install user revision storage schema (post install).
   * 
   * Update not null fileds.
   */
  public function postinstall() {
    $schema_handler = $this->database->schema();
    $schema = $this->getEntitySchema($this->entityType, TRUE);
    foreach ($this->keyValue()->get('installed_fields', array()) as $table_name => $fields) {
      foreach ($fields as $field_name) {
        $field_schema = $schema[$table_name]['fields'][$field_name];
        if ($field_schema['not null']) {
          $schema_handler->changeField($table_name, $field_name, $field_name, $field_schema);
        }
      }
    }
  }

  /**
   * Install user revision storage schema.
   */
  public function uninstall() {
    $schema_handler = $this->database->schema();

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