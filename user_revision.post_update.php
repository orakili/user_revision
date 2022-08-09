<?php

/**
 * @file
 * Post updates for the user_revision module.
 */

/**
 * Implements hook_post_update_NAME().
 *
 * Update user_revision.settings configuration.
 */
function user_revision_post_update_change_configuration(&$sandbox) {
  $config = \Drupal::configFactory()->getEditable('user_revision.settings');

  // Get the entire config data.
  $data = $config->get();

  // Set the new keys.
  if (!array_key_exists('revision_mode', $data)) {
    if (array_key_exists('user_revision_always_enabled', $data)) {
      $data['revision_mode'] = !empty($data['user_revision_always_enabled']) ? 'required' : 'optional';
    }
    else {
      $data['revision_mode'] = 'optional';
    }
  }

  if (!array_key_exists('revision_default_enabled', $data)) {
    if (array_key_exists('user_revision_default_enabled', $data)) {
      $data['revision_default_enabled'] = !empty($data['user_revision_default_enabled']);
    }
    else {
      $data['revision_default_enabled'] = TRUE;
    }
  }

  if (!array_key_exists('revision_user_log_enabled', $data)) {
    if (array_key_exists('user_revision_user_log_enabled', $data)) {
      $data['revision_user_log_enabled'] = !empty($data['user_revision_user_log_enabled']);
    }
    else {
      $data['revision_user_log_enabled'] = TRUE;
    }
  }

  if (!array_key_exists('revision_information_open', $data)) {
    if (array_key_exists('user_revision_default_open', $data)) {
      $data['revision_information_open'] = !empty($data['user_revision_default_open']);
    }
    else {
      $data['revision_information_open'] = TRUE;
    }
  }

  if (!array_key_exists('revision_keep_passwords', $data)) {
    $data['revision_keep_passwords'] = TRUE;
  }

  // Remove the old keys.
  unset($data['user_revision_always_enabled']);
  unset($data['user_revision_default_enabled']);
  unset($data['user_revision_default_open']);
  unset($data['user_revision_user_log_enabled']);

  // Upddate the config data.
  $config->setData($data);
  $config->save(TRUE);
}

/**
 * Implements hook_post_update_NAME().
 *
 * Update the existing revision fields.
 *
 * In this hook update, we rename the existing revision fields via direct
 * renaming of the database fields because the Drupal Entity Update API doesn't
 * allow us to change existing entity and revision keys.
 *
 * @see \Drupal\Core\Entity\Sql\SqlFieldableEntityTypeListenerTrait::copyData()
 */
function user_revision_post_update_change_keys_test_0001(&$sandbox) {
  $entity_type_id = 'user';

  /** @var \Drupal\Core\Entity\EntityDefinitionUpdateManagerInterface $definition_update_manager */
  $definition_update_manager = \Drupal::entityDefinitionUpdateManager();

  /** @var \Drupal\Core\Entity\EntityLastInstalledSchemaRepositoryInterface $last_installed_schema_repository */
  $last_installed_schema_repository = \Drupal::service('entity.last_installed_schema.repository');

  /** @var \Drupal\Core\Database\Schema $schema */
  $schema = \Drupal::database()->schema();

  // Get the existing field definitions.
  $field_storage_definitions = $last_installed_schema_repository->getLastInstalledFieldStorageDefinitions($entity_type_id);

  /** @var \Drupal\Core\Entity\EntityTypeInterface $entity_type */
  $entity_type = $definition_update_manager->getEntityType($entity_type_id);

  // Get the existing entity and revision metadata keys.
  $entity_keys = $entity_type->getKeys();
  $revision_keys = $entity_type->getRevisionMetadataKeys();
  $existing_keys = $entity_keys + $revision_keys;

  // Update the keys.
  user_revision_add_revision_tables_and_keys($entity_type);

  // Only perserve the keys that already existed in older versions of this
  // module. The next post update will take care of properly adding the new
  // keys if any.
  $new_entity_keys = array_intersect_key($entity_type->getKeys(), $entity_keys);
  $new_revision_keys = array_intersect_key($entity_type->getRevisionMetadataKeys(), $revision_keys);
  $new_keys = $new_entity_keys + $new_revision_keys;
  $entity_type->set('entity_keys', $new_entity_keys);
  $entity_type->set('revision_metadata_keys', $new_revision_keys);

  // Update the field storage definitions for the changed keys.
  foreach ($new_keys as $key => $field) {
    if ($existing_keys[$key] !== $field) {
      // Re-use the same field storage, just change the name. Any other schema
      // change will be handled in the next post update.
      $field_storage_definitions[$field] = $field_storage_definitions[$existing_keys[$key]]->setName($field);
      unset($field_storage_definitions[$existing_keys[$key]]);
    }
  }

  // Update the revision key.
  if ($entity_keys['revision'] !== $new_entity_keys['revision']) {
    $old_field = $entity_keys['revision'];
    $new_field = $new_entity_keys['revision'];

    // Retrieve the field schema.
    $field_schema = $field_storage_definitions[$new_field]->getSchema()['columns']['value'];

    // Update the base table.
    $schema->dropUniqueKey($entity_type->getBaseTable(), $entity_type_id . '__' . $old_field);
    $schema->changeField($entity_type->getBaseTable(), $old_field, $new_field, $field_schema, [
      'unique keys' => [
        $entity_type_id . '__' . $new_field => [$new_field],
      ],
    ]);

    // For the other tables below, the field needs to be not null.
    $field_schema['not null'] = TRUE;

    // Update the data table.
    $schema->dropUniqueKey($entity_type->getDataTable(), $entity_type_id . '__' . $old_field);
    $schema->changeField($entity_type->getDataTable(), $old_field, $new_field, $field_schema, [
      'unique keys' => [
        $entity_type_id . '__' . $new_field => [$new_field],
      ],
    ]);

    // Update the revision table.
    // We need to add a temporary unique key because the revision ID field is
    // a serial field for that table and it cannot be altered unless there is a
    // key for it but we need to delete the primary key before changing the
    // field thus the need for this temporary key.
    $schema->addUniqueKey($entity_type->getRevisionTable(), 'temp_index', [$old_field]);
    $schema->dropPrimaryKey($entity_type->getRevisionTable());
    $schema->changeField($entity_type->getRevisionTable(), $old_field, $new_field, [
      'type' => 'serial',
    ] + $field_schema, [
      'primary key' => [$new_field],
    ]);
    $schema->dropUniqueKey($entity_type->getRevisionTable(), 'temp_index');

    // Update the revision data table.
    $schema->dropPrimaryKey($entity_type->getRevisionDataTable());
    $schema->changeField($entity_type->getRevisionDataTable(), $old_field, $new_field, $field_schema, [
      'primary key' => [$new_field, 'langcode'],
    ]);
  }

  // Update the revision translation affected field if it existed.
  if (isset($entity_keys['revision_translation_affected'], $new_entity_keys['revision_translation_affected']) &&
      $entity_keys['revision_translation_affected'] !== $new_entity_keys['revision_translation_affected']) {

    $old_field = $entity_keys['revision_translation_affected'];
    $new_field = $new_entity_keys['revision_translation_affected'];

    // Retrieve the field schema.
    $field_schema = $field_storage_definitions[$new_field]->getSchema()['columns']['value'];

    // Update the revision data table.
    $schema->changeField($entity_type->getDataTable(), $old_field, $new_field, $field_schema);
    $schema->changeField($entity_type->getRevisionDataTable(), $old_field, $new_field, $field_schema);
  }

  // Update the revision metadata keys.
  foreach ($revision_keys as $key => $old_field) {
    if (isset($new_revision_keys[$key]) && $revision_keys[$key] !== $new_revision_keys[$key]) {
      $new_field = $new_revision_keys[$key];

      // Update the revision data table.
      // The revision user field is an entity_reference field which has a
      // special index. We need to drop it before changing the field.
      if ($key === 'revision_user') {
        // Retrieve the field schema.
        $field_schema = $field_storage_definitions[$new_field]->getSchema()['columns']['target_id'];

        $schema_unique_key = $entity_type_id . 'field__' . $old_field . '__target_id';
        $schema->dropUniqueKey($entity_type->getRevisionTable(), $schema_unique_key);
        $schema->changeField($entity_type->getRevisionTable(), $old_field, $new_field, $field_schema, [
          'unique keys' => [
            $schema_unique_key => [$new_field],
          ],
        ]);
      }
      else {
        // Retrieve the field schema.
        $field_schema = $field_storage_definitions[$new_field]->getSchema()['columns']['value'];

        $schema->changeField($entity_type->getRevisionTable(), $old_field, $new_field, $field_schema);
      }
    }
  }

  // Set the modified entity type and field definitions as the latest installed
  // ones to reflect the field name changes.
  $last_installed_schema_repository->setLastInstalledDefinition($entity_type);
  $last_installed_schema_repository->setLastInstalledFieldStorageDefinitions($entity_type_id, $field_storage_definitions);

  // Clear the definition caches.
  \Drupal::entityTypeManager()->clearCachedDefinitions();
  \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
}

/**
 * Implements hook_post_update_NAME().
 *
 * Remove the old revision fields, add the new ones and update the schemas if
 * necessary.
 *
 * This is similar to what the user_revision's hook_install does().
 */
function user_revision_post_update_change_keys_test_0002(&$sandbox) {
  $entity_type_id = 'user';

  /** @var \Drupal\Core\Entity\EntityDefinitionUpdateManagerInterface $definition_update_manager */
  $definition_update_manager = \Drupal::entityDefinitionUpdateManager();

  /** @var \Drupal\Core\Entity\EntityTypeInterface $entity_type */
  $entity_type = $definition_update_manager->getEntityType($entity_type_id);

  /** @var \Drupal\Core\Entity\EntityLastInstalledSchemaRepositoryInterface $last_installed_schema_repository */
  $last_installed_schema_repository = \Drupal::service('entity.last_installed_schema.repository');

  // Update the display_name field storage definition.
  $field_storage_definitions = $last_installed_schema_repository->getLastInstalledFieldStorageDefinitions($entity_type_id);

  // Update the entity type definition, adding revision keys and tables.
  user_revision_add_revision_tables_and_keys($entity_type);

  // Add the revision field storage definitions for the revision fields.
  foreach (user_revision_get_revision_base_field_definitions($entity_type) as $field_name => $definition) {
    $field_storage_definitions[$field_name] = $definition;
  }

  // Mark the relevant base fields as revisionable.
  user_revision_set_revisionable_fields($entity_type, $field_storage_definitions);

  // We need to be able to update the anonymous user which has an ID of 0 so
  // we prevent auto incrementing the ID when inserting the anonymous user
  // in the temporary table used for the data migration.
  //
  // @see \Drupal\user\UserStorage::doSaveFieldItems();
  // @see https://drupal.org/i/3222123
  $database = \Drupal::database();
  if ($database->databaseType() === 'mysql') {
    $sql_mode = $database->query("SELECT @@sql_mode;")->fetchField();
    $database->query("SET sql_mode = '$sql_mode,NO_AUTO_VALUE_ON_ZERO'");
  }

  // Update the entity type. This will migrate the existing data.
  $definition_update_manager->updateFieldableEntityType($entity_type, $field_storage_definitions, $sandbox);

  // Reset the SQL mode if we've changed it.
  if (isset($sql_mode, $database)) {
    $database->query("SET sql_mode = '$sql_mode'");
  }

  // Retrieve the total number of user entities to update.
  if (!isset($sandbox['total'])) {
    $sandbox['total'] = \Drupal::database()
      ->select('users', 'u')
      ->countQuery()
      ->execute()
      ?->fetchField() ?? 0;
  }

  return t('@progress/@total user entities updated', [
    '@progress' => $sandbox['progress'] ?? 0,
    '@total' => $sandbox['total'],
  ]);
}

/**
 * Implements hook_post_update_NAME().
 *
 * Initialize the revision user and created fields.
 */
function user_revision_post_update_initialize_revision_fields(array &$sandbox) {
  /** @var \Drupal\Core\Database\Connection $database */
  $database = \Drupal::database();

  /** @var \Drupal\Core\Entity\ContentEntityTypeInterface $entity_type */
  $entity_type = \Drupal::entityDefinitionUpdateManager()->getEntityType('user');

  $data_table = $entity_type->getDataTable();
  $revision_table = $entity_type->getRevisionTable();
  $id_field = $entity_type->getKey('id');
  $revision_user_field = $entity_type->getRevisionMetadataKey('revision_user');
  $revision_created_field = $entity_type->getRevisionMetadataKey('revision_created');

  // Initialize the revision user field with the ID of the user.
  $database->update($revision_table)
    ->expression($revision_user_field, $id_field)
    ->isNull($revision_user_field)
    ->execute();

  // Initialize the revision created field with the user creation date.
  // Unfortunately Drupal still doesn't provide a way to do a join on an
  // update query so we need to use Connection::query() directly.
  $database->query("
    UPDATE {$revision_table} AS revision_table
    INNER JOIN {$data_table} AS data_table
    ON data_table.{$id_field} = revision_table.{$id_field}
    SET revision_table.{$revision_created_field} = data_table.created
    WHERE revision_table.{$revision_created_field} IS NULL
  ");

  return t('Set the initial revision user and created fields for the user revisions');
}
