<?php

/**
 * @file
 * Contains \Drupal\user_revision\Controller\UserController.
 */

namespace Drupal\user_revision\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Datetime\DateFormatter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\user\UserInterface;
use Drupal\Component\Utility\String;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Url;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Drupal\user_revision\Access\UserRevisionAccessCheck;

/**
 * Returns responses for User revision routes.
 */
class UserController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatter
   */
  protected $dateFormatter;

  /**
   * Constructs a UserController object.
   *
   * @param \Drupal\Core\Datetime\DateFormatter $date_formatter
   *   The date formatter service.
   */
  public function __construct(DateFormatter $date_formatter) {
    $this->dateFormatter = $date_formatter;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('date.formatter')
    );
  }

  /**
   * Generates an overview table of older revisions of a user.
   *
   * @param \Drupal\user\UserInterface $user
   *   A user object.
   *
   * @return array
   *   An array as expected by drupal_render().
   */
  public function revisionOverview(UserInterface $user) {
    $account = $this->currentUser();
    $user_storage = $this->entityManager()->getStorage('user');
    $access_check = new UserRevisionAccessCheck($this->entityManager());

    $build = array();
    $build['#title'] = $this->t('Revisions for %title', array('%title' => $user->label()));
    $header = array($this->t('Revision'), $this->t('Operations'));

    $rows = array();

    $vids = $user_storage->revisionIds($user);

    foreach (array_reverse($vids) as $vid) {
      if ($revision = $user_storage->loadRevision($vid)) {
        $row = array(
          'revision' => array('data' => array()),
          'operations' => array('data' => array())
        );
        $revision_author = $revision->revision_uid->entity;

        if ($vid == $user->getRevisionId()) {
          $username = array(
            '#theme' => 'username',
            '#account' => $revision_author,
          );
          $row['revision']['data']['#markup'] = $this->t('!date by !username', array('!date' => $user->link($this->dateFormatter->format($revision->revision_timestamp->value, 'short')), '!username' => drupal_render($username)));
          $row['revision']['data']['#markup'] .= ($revision->revision_log->value != '') ? '<p class="revision-log">' . Xss::filter($revision->revision_log->value) . '</p>' : '';
          $row['revision']['data']['class'] = array('revision-current');
          $row['operations'] = array('data' => String::placeholder($this->t('current revision')), 'class' => array('revision-current'));
        }
        else {
          $links = array();
          $username = array(
            '#theme' => 'username',
            '#account' => $revision_author,
          );
          $row['revision']['data']['#markup'] = $this->t('!date by !username', array('!date' => $this->l($this->dateFormatter->format($revision->revision_timestamp->value, 'short'), new Url('user.revision_show', array('user' => $user->id(), 'user_revision' => $vid))), '!username' => drupal_render($username)));
          $row['revision']['data']['#markup'] .= ($revision->revision_log->value != '') ? '<p class="revision-log">' . Xss::filter($revision->revision_log->value) . '</p>' : '';

          if ($access_check->checkAccess($revision, $account, 'update')) {
            $links['revert'] = array(
              'title' => $this->t('Revert'),
              'url' => Url::fromRoute('user.revision_revert_confirm', ['user' => $user->id(), 'user_revision' => $vid]),
            );
          }

          if ($access_check->checkAccess($revision, $account, 'delete')) {
            $links['delete'] = array(
              'title' => $this->t('Delete'),
              'url' => Url::fromRoute('user.revision_delete_confirm', ['user' => $user->id(), 'user_revision' => $vid]),
            );
          }

          if ($links) {
            $row['operations'] = array(
              'data' => array(
                '#type' => 'operations',
                '#links' => $links,
              ),
            );
          }
        }

        $rows[] = $row;
      }
    }

    $build['user_revisions_table'] = array(
      '#theme' => 'table',
      '#rows' => $rows,
      '#header' => $header,
      '#attached' => array(
        'library' => array('user_revision/user.admin')
      )
    );

    return $build;
  }

  /**
   * Displays a user revision.
   *
   * @param int $user
   *   The user ID.
   * @param int $user_revision
   *   The user revision ID.
   *
   * @return array
   *   An array suitable for drupal_render().
   */
  public function revisionShow($user, $user_revision) {
    $user_history = $this->entityManager()->getStorage('user')->loadRevision($user_revision);
    if ($user_history->id() != $user) {
      throw new NotFoundHttpException;
    }
    /* @var $view_builder \Drupal\Core\Entity\EntityViewBuilder */
    $view_builder = $this->entityManager()->getViewBuilder($user_history->getEntityTypeId());
    return $view_builder->view($user_history);
  }

  /**
   * Page title callback for a user revision.
   *
   * @param int $user_revision
   *   The user revision ID.
   *
   * @return string
   *   The page title.
   */
  public function revisionPageTitle($user_revision) {
    $user = $this->entityManager()->getStorage('user')->loadRevision($user_revision);
    return $this->t('Revision of %title from %date', array('%title' => $user->label(), '%date' => format_date($user->get('revision_timestamp')->value)));
  }

}
