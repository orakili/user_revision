<?php

/**
 * @file
 * Definition of Drupal\user_revision\UserStorage.
 */

namespace Drupal\user_revision;

use Drupal\user\UserStorage as BaseUserStorage;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityManagerInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Password\PasswordInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\user\UserInterface;

/**
 * Controller class for users.
 */
class UserStorage extends BaseUserStorage implements UserStorageInterface {

  /**
   * The key/value store.
   * 
   * @var \Drupal\Core\KeyValueStore\KeyValueFactoryInterface 
   */
  protected $keyvalue;

  /**
   * Constructs a new UserStorage object.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type definition.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection to be used.
   * @param \Drupal\Core\Entity\EntityManagerInterface $entity_manager
   *   The entity manager.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   Cache backend instance to use.
   * @param \Drupal\Core\Password\PasswordInterface $password
   *   The password hashing service.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   * @param \Drupal\Core\KeyValueStore\KeyValueFactoryInterface $keyvalue
   *   The key/value store factory.
   */
  public function __construct(EntityTypeInterface $entity_type, Connection $database, EntityManagerInterface $entity_manager, CacheBackendInterface $cache, PasswordInterface $password, LanguageManagerInterface $language_manager, KeyValueFactoryInterface $keyvalue) {
    parent::__construct($entity_type, $database, $entity_manager, $cache, $password, $language_manager);
    $this->keyvalue = $keyvalue;
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    return new static(
      $entity_type, $container->get('database'), $container->get('entity.manager'), $container->get('cache.entity'), $container->get('password'), $container->get('language_manager'), $container->get('keyvalue')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getStorageSchema() {
    if (!isset($this->storageSchema)) {
      $class = $this->entityType->getHandlerClass('storage_schema') ? : 'Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema';
      if (array_key_exists('Drupal\user_revision\UserStorageSchemaInterface', class_implements($class))) {
        $this->storageSchema = new $class($this->entityManager, $this->entityType, $this, $this->database, $this->keyvalue);
      }
      else {
        $this->storageSchema = new $class($this->entityManager, $this->entityType, $this, $this->database);
      }
    }
    return $this->storageSchema;
  }

  /**
   * {@inheritdoc}
   */
  public function revisionIds(UserInterface $user) {
    return $this->database->query(
        'SELECT vid FROM {' . $this->entityType->getRevisionTable() . '} WHERE uid=:uid ORDER BY vid', array(':uid' => $user->id())
      )->fetchCol();
  }

  /**
   * {@inheritdoc}
   */
  public function revisionCount(UserInterface $user) {
    return $this->database->query(
        'SELECT COUNT(DISTINCT vid) FROM {' . $this->entityType->getRevisionTable() . '} WHERE uid=:uid', array(':uid' => $user->id())
      )->fetchField();
  }

  /**
   * Install user revision storage.
   */
  public function install() {
    $this->getStorageSchema()->install();

    db_update($this->entityType->getBaseTable())
      ->expression('vid', 'uid')
      ->execute();

    db_update($this->entityType->getDataTable())
      ->expression('vid', 'uid')
      ->execute();

    $query = db_select($this->entityType->getBaseTable(), 'u')
      ->fields('u', array('uid', 'vid', 'langcode'));
    $result = $query->execute();
    foreach ($result as $record) {
      $fields = (array) $record;
      $fields['revision_timestamp'] = REQUEST_TIME;
      $fields['revision_uid'] = '1';
      db_insert($this->entityType->getRevisionTable())
        ->fields($fields)
        ->execute();
    }

    $query = db_select($this->entityType->getDataTable(), 'u')
      ->fields('u', array('uid', 'vid', 'langcode', 'default_langcode', 'name', 'pass', 'mail', 'signature', 'signature_format', 'timezone', 'status', 'created', 'changed'));
    $result = $query->execute();
    foreach ($result as $record) {
      db_insert($this->entityType->getRevisionDataTable())
        ->fields((array) $record)
        ->execute();
    }

    $this->getStorageSchema()->postinstall();
  }

  /**
   * Uninstall user revision storage.
   */
  public function uninstall() {
    $this->getStorageSchema()->uninstall();

    db_update($this->entityType->getBaseTable())
      ->expression(
        'langcode', db_select($this->entityType->getRevisionTable(), 'r')->fields('r', array('langcode'))->where('r.vid = ' . $this->entityType->getBaseTable() . '.vid')
      )
      ->execute();

    $this->getStorageSchema()->postuninstall();
  }

}
