<?php

/**
 * @file
 * Contains \Drupal\user_revision\UserStorageInterface.
 */

namespace Drupal\user_revision;

use Drupal\user\UserStorageInterface as BaseUserStorageInterface;
use Drupal\user\UserInterface;

/**
 * Defines an interface for user entity storage classes.
 */
interface UserStorageInterface extends BaseUserStorageInterface {

  /**
   * Returns a list of user revision IDs for a specific user.
   *
   * @param \Drupal\user\UserInterface
   *   The user entity.
   *
   * @return int[]
   *   User revision IDs (in ascending order).
   */
  public function revisionIds(UserInterface $user);

  /**
   * Returns a count of user revisions for a specific user.
   *
   * @param \Drupal\user\UserInterface
   *   The user entity.
   *
   * @return int
   *   User revision count.
   */
  public function revisionCount(UserInterface $user);
}
