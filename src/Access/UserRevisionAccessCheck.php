<?php

namespace Drupal\user_revision\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\UserInterface;
use Symfony\Component\Routing\Route;

/**
 * Provides an access checker for user revisions.
 */
class UserRevisionAccessCheck implements AccessInterface {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * A static cache of access checks.
   *
   * @var array
   */
  protected $access = [];

  /**
   * Constructs a new UserRevisionAccessCheck.
   *
   * @param \Drupal\Core\Entity\EntityManagerInterface $entity_manager
   *   The entity manager.
   */

  /**
   * UserRevisionAccessCheck constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Checks routing access for the user revision.
   *
   * @param \Symfony\Component\Routing\Route $route
   *   The route to check against.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The currently logged in account.
   * @param int|null $revision_id
   *   The user revision ID. If not specified, but $user is, access is checked
   *   for that object's revision.
   * @param \Drupal\user\UserInterface|null $user
   *   A user object. Used for checking access to a user's default
   *   revision when $revision_id is unspecified. Ignored when $revision_id
   *   is specified. If neither $revision_id nor $user are specified, then
   *   access is denied.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(Route $route, AccountInterface $account, $revision_id = NULL, UserInterface $user = NULL) {
    if (isset($revision_id)) {
      $user = $this->entityTypeManager->getStorage('user')->loadRevision($revision_id);
    }
    $operation = $route->getRequirement('_access_user_revision');
    return AccessResult::allowedIf($user && $this->checkAccess($user, $account, $operation));
  }

  /**
   * Checks user revision access.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user to check.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   A user object representing the user for whom the operation is to be
   *   performed.
   * @param string $op
   *   The specific operation being checked. Defaults to 'view.' (optional).
   *
   * @return bool
   *   TRUE if the operation may be performed, FALSE otherwise.
   */
  public function checkAccess(UserInterface $user, AccountInterface $account, $op = 'view') {
    $map = [
      'view' => 'view all user revisions',
      'update' => 'revert all user revisions',
      'delete' => 'delete all user revisions',
    ];
    $own_map = [
      'view' => 'view own user revisions',
      'update' => 'revert own user revisions',
      'delete' => 'delete own user revisions',
    ];

    if (!$user || !isset($map[$op]) || !isset($own_map[$op])) {
      // If there was no user to check against, or the $op was not one of the
      // supported ones, we return access denied.
      return FALSE;
    }

    // Perform basic permission checks first.
    if (!$account->hasPermission($map[$op]) && !($account->id() == $user->id() && $account->hasPermission($own_map[$op]))) {
      return FALSE;
    }

    // Check minimal revisions count.
    if (user_revision_count($user) < 2) {
      return FALSE;
    }

    // There should be at least two revisions. If the revision id of the given
    // user and the revision id of the default revision differ, then we already
    // have two different revisions so there is no need for a separate database
    // check. Also, if you try to revert to or delete the default revision,
    // that's not good.
    if ($user->isDefaultRevision() && ($op == 'update' || $op == 'delete')) {
      return FALSE;
    }

    return $user->access($op, $account);
  }

}
