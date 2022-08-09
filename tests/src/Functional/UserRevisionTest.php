<?php

namespace Drupal\Tests\user_revision\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Functional tests for the user_revision module.
 *
 * @todo add test for the config settings.
 */
class UserRevisionTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'user',
    'user_revision',
  ];

  /**
   * Test that the install hook worked and that setting new revisions works.
   *
   * Functional test will run the module install with existing admin and
   * anonymous user.
   */
  public function testInstallAndRevisions() {
    $user_storage = \Drupal::entityTypeManager()->getStorage('user');
    $revision_keys = $user_storage->getEntityType()->getRevisionMetadataKeys();

    $uids = $user_storage
      ->getQuery()
      ->orderBy('uid', 'ASC')
      ->execute();

    // Check that the admin and anonymous users exists.
    $this->assertEquals([0, 1], array_values($uids));

    $admin = $user_storage->load(1);
    $admin->name = 'admin_new';
    $admin->set($revision_keys['revision_log_message'], 'changed name');
    $admin->setNewRevision(TRUE);
    $admin->save();

    $revision_ids = user_revision_ids($admin);
    $this->assertCount(2, $revision_ids);

    $first_revision = $user_storage->loadRevision($revision_ids[0]);
    $this->assertEquals('admin', $first_revision->name->value);
    $this->assertNull($first_evision->get($revision_keys['revision_log_message'])->value);

    $second_revision = $user_storage->loadRevision($revision_ids[1]);
    $this->assertEquals('admin_new', $second_revision->name->value);
    $this->assertEquals('changed name', $second_revision->get($revision_keys['revision_log_message'])->value);
  }

}
