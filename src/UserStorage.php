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

/**
 * Controller class for users.
 */
class UserStorage extends BaseUserStorage {

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
   * Install user revision storage.
   */
  public function install() {
    $this->getStorageSchema()->install();
  }

  /**
   * Uninstall user revision storage.
   */
  public function uninstall() {
    $this->getStorageSchema()->uninstall();
  }

}
