<?php

/**
 * @file
 * Contains \Drupal\user_revision\Access\UserRevisionAccessCheck.
 */

namespace Drupal\user_revision\Access;

use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Entity\EntityManagerInterface;
use Symfony\Component\Routing\Route;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\UserInterface;
use Drupal\Core\Access\AccessResult;

/**
 * Provides an access checker for user revisions.
 */
class UserRevisionAccessCheck implements AccessInterface {

  /**
   * The user storage.
   *
   * @var \Drupal\user\UserStorageInterface
   */
  protected $userStorage;

  /**
   * A static cache of access checks.
   *
   * @var array
   */
  protected $access = array();

  /**
   * Constructs a new UserRevisionAccessCheck.
   *
   * @param \Drupal\Core\Entity\EntityManagerInterface $entity_manager
   *   The entity manager.
   */
  public function __construct(EntityManagerInterface $entity_manager) {
    $this->userStorage = $entity_manager->getStorage('user');
  }

  /**
   * Checks routing access for the user revision.
   *
   * @param \Symfony\Component\Routing\Route $route
   *   The route to check against.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The currently logged in account.
   * @param int $user_revision
   *   (optional) The user revision ID. If not specified, but $node is, access
   *   is checked for that object's revision.
   * @param \Drupal\user\UserInterface $user
   *   (optional) A user object. Used for checking access to a user's default
   *   revision when $user_revision is unspecified. Ignored when $user_revision
   *   is specified. If neither $user_revision nor $user are specified, then
   *   access is denied.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(Route $route, AccountInterface $account, $user_revision = NULL, UserInterface $user = NULL) {
    if ($user_revision) {
      $user = $this->userStorage->loadRevision($user_revision);
    }
    $operation = $route->getRequirement('_access_user_revision');
    return AccessResult::allowedIf($user && $this->checkAccess($user, $account, $operation));
  }

  /**
   * Checks user revision access.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to check.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   A user object representing the user for whom the operation is to be
   *   performed.
   * @param string $op
   *   (optional) The specific operation being checked. Defaults to 'view.'
   *
   * @return bool
   *   TRUE if the operation may be performed, FALSE otherwise.
   */
  public function checkAccess(UserInterface $user, AccountInterface $account, $op = 'view') {
    $map = array(
      'view' => 'view all user revisions',
      'update' => 'revert all user revisions',
      'delete' => 'delete all user revisions',
    );
    $own_map = array(
      'view' => 'view own user revisions',
      'update' => 'revert own user revisions',
      'delete' => 'delete own user revisions',
    );

    if (!$user || !isset($map[$op]) || !isset($own_map[$op])) {
      // If there was no user to check against, or the $op was not one of the
      // supported ones, we return access denied.
      return FALSE;
    }

    // Perform basic permission checks first.
    if (!$account->hasPermission($map[$op]) && !($account->id() == $user->id() && $account->hasPermission($own_map[$op]))) {
      return FALSE;
    }

    // Check minimal revisions count
    if (user_revision_count($user) < 2) {
      return FALSE;
    }

    // There should be at least two revisions. If the vid of the given node
    // and the vid of the default revision differ, then we already have two
    // different revisions so there is no need for a separate database check.
    // Also, if you try to revert to or delete the default revision, that's
    // not good.
    if ($user->isDefaultRevision() && ($op == 'update' || $op == 'delete')) {
      return FALSE;
    }

    return $user->access($op, $account);
  }

}
